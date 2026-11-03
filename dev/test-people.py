#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-2.0-or-later
"""Contacts presence rings + photos (people.py), on a throwaway DB with a
fake Asterisk status: online / recent / offline / hidden, the privacy
switch, profile and contact photos, the theirs/mine choice, who may see
which photo, the data download and account deletion.

    export PBX_DB=/tmp/apptest.db && rm -f $PBX_DB && python3 dev/seed-test-db.py
    python3 dev/test-people.py      # exits non-zero on failure
"""
import io, os, sys, tempfile, zipfile
here = os.path.dirname(os.path.abspath(__file__))
os.environ["PBX_PHOTO_DIR"] = tempfile.mkdtemp(prefix="photos-")
os.environ["PBX_EXPORT_DIR"] = tempfile.mkdtemp(prefix="exports-")
sys.path.insert(0, os.path.join(here, "..", "pbx-api"))
from fastapi.testclient import TestClient
import app as A, app_api, people, privacy_ui
from PIL import Image
A.init_db()
fails = []
def check(n, ok, x=""):
    print(("PASS " if ok else "FAIL ") + n, "" if ok else x); ok or fails.append(n)
d = A.db()
uid = lambda u: d.execute("SELECT id FROM logins WHERE username=?", (u,)).fetchone()[0]
# A third user to be "offline".
d.execute("INSERT OR IGNORE INTO logins (username, pwhash, role, exten, sip_username, display_name)"
          " VALUES ('phone8802','x','user','8802','phone8802','Third')")
d.commit()
status = {"contacts": [{"aor": "phone8801-app", "ip": "10.0.0.5", "port": 5, "transport": "tls", "status": "Avail"}]}
A._asterisk_status = lambda: status
c = TestClient(A.app)
tok = lambda u: {"Authorization": "Bearer " + c.post("/api/v1/app/pair", json={"code": app_api._new_pair_code(uid(u))}).json()["token"]}
H0, H1 = tok("phone8800"), tok("phone8801")
for name, num in (("Bob", "8801"), ("Third", "8802"), ("Pizza", "5551234567")):
    r = c.post("/api/v1/me/contacts/save", headers=H0, data={"name": name, "number": num})
cl = {x["name"]: x for x in c.get("/api/v1/me/contacts", headers=H0).json()["contacts"]}
check("online extension shows online", cl["Bob"]["presence"] == "online", cl["Bob"])
check("never-seen extension shows offline", cl["Third"]["presence"] == "offline", cl["Third"])
check("outside number has no ring", cl["Pizza"]["presence"] == "" and not cl["Pizza"]["is_extension"], cl["Pizza"])
people.sample()                                     # stamps 8801 last_seen
status["contacts"] = []                             # 8801 drops off
cl = {x["name"]: x for x in c.get("/api/v1/me/contacts", headers=H0).json()["contacts"]}
check("just dropped off shows recent", cl["Bob"]["presence"] == "recent", cl["Bob"])
d.execute("UPDATE logins SET last_seen_at=datetime('now','-31 minutes') WHERE username='phone8801'"); d.commit()
cl = {x["name"]: x for x in c.get("/api/v1/me/contacts", headers=H0).json()["contacts"]}
check("over 30 minutes shows offline", cl["Bob"]["presence"] == "offline", cl["Bob"])
r = c.post("/api/v1/me/status-privacy", headers=H1, data={"show_status": "0"}).json()
status["contacts"] = [{"aor": "phone8801", "ip": "10.0.0.5", "port": 5, "transport": "udp", "status": "Avail"}]
cl = {x["name"]: x for x in c.get("/api/v1/me/contacts", headers=H0).json()["contacts"]}
check("hidden user shows hidden even while online", r["ok"] and cl["Bob"]["presence"] == "hidden", (r, cl["Bob"]))
check("profile reports the switch", c.get("/api/v1/me/profile", headers=H1).json()["show_status"] is False)
c.post("/api/v1/me/status-privacy", headers=H1, data={"show_status": "1"})
def png(color):
    b = io.BytesIO(); Image.new("RGB", (800, 500), color).save(b, "PNG"); return b.getvalue()
r = c.post("/api/v1/me/photo", headers=H1, files={"photo": ("me.png", png("red"), "image/png")}).json()
check("profile photo saved", r["ok"], r)
cl = {x["name"]: x for x in c.get("/api/v1/me/contacts", headers=H0).json()["contacts"]}
check("their photo shows on my contact", cl["Bob"]["photo_source"] == "theirs" and cl["Bob"]["photo_url"], cl["Bob"])
img = c.get(cl["Bob"]["photo_url"], headers=H0)
im = Image.open(io.BytesIO(img.content)) if img.status_code == 200 else None
check("photo is a 256px square JPEG", im is not None and im.size == (256, 256) and im.format == "JPEG", img.status_code)
bob = cl["Bob"]["id"]
r = c.post(f"/api/v1/me/contacts/{bob}/photo", headers=H0, files={"photo": ("b.png", png("blue"), "image/png")}).json()
cl = {x["name"]: x for x in c.get("/api/v1/me/contacts", headers=H0).json()["contacts"]}
check("auto still prefers their photo", r["ok"] and cl["Bob"]["photo_source"] == "theirs", cl["Bob"])
c.post(f"/api/v1/me/contacts/{bob}/photo-pref", headers=H0, data={"pref": "mine"})
cl = {x["name"]: x for x in c.get("/api/v1/me/contacts", headers=H0).json()["contacts"]}
check("choosing mine shows my photo", cl["Bob"]["photo_source"] == "mine", cl["Bob"])
check("someone else can't see my contact photo", c.get(f"/api/v1/me/contacts/{bob}/photo", headers=H1).status_code == 404)
r = c.post("/api/v1/me/photo", headers=H1, files={"photo": ("x.txt", b"not a picture", "text/plain")}).json()
check("non-picture refused", not r["ok"] and "picture" in r["message"], r)
p = privacy_ui.build_export(uid("phone8800"))[0]
check("data download has the contact photo", any(n.startswith("photos/") for n in zipfile.ZipFile(p).namelist()))
files_before = len(os.listdir(os.environ["PBX_PHOTO_DIR"]))
A._best_effort_apply = lambda: None
A.wipe_login(uid("phone8801"))
check("account deletion removes their photo file", len(os.listdir(os.environ["PBX_PHOTO_DIR"])) == files_before - 1)
print("FAILED:", ", ".join(fails) or "none")
sys.exit(1 if fails else 0)
