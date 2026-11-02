#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-2.0-or-later
"""The app's cover templates: listed in /me/faxes, send with a saved
template, with a template file from the phone (one fax only, nothing
stored) or saved into the user's templates, and both cover previews.
Needs gs and tiff2pdf.

    export PBX_DB=/tmp/apptest.db && rm -f $PBX_DB && python3 dev/seed-test-db.py
    python3 dev/test-fax-templates-app.py      # exits non-zero on failure
"""
import io, json, os, sys, sqlite3, tempfile
here = os.path.dirname(os.path.abspath(__file__))
os.environ["PBX_FAX_DIR"] = tempfile.mkdtemp(prefix="faxdir-")
os.environ["PBX_FAX_BRAND_DIR"] = tempfile.mkdtemp(prefix="brand-")
os.environ["PBX_FAXOUT_DIR"] = tempfile.mkdtemp(prefix="faxout-")
sys.path.insert(0, os.path.join(here, "..", "pbx-api"))
from fastapi.testclient import TestClient
import app as A, app_api, fax_brand
A.init_db()
fails = []
def check(n, ok, x=""):
    print(("PASS " if ok else "FAIL ") + n, "" if ok else x); ok or fails.append(n)
d = sqlite3.connect(os.environ["PBX_DB"]); d.row_factory = sqlite3.Row
d.execute("INSERT INTO inbound_routes (did, ring_extens, fax_detect) VALUES ('5550001111','[\"8800\"]',1)")
d.execute("INSERT OR REPLACE INTO kv_settings (key,value) VALUES ('default_quota_call_minutes','100')")
d.execute("INSERT OR REPLACE INTO kv_settings (key,value) VALUES ('default_quota_fax','1')")
d.commit()
A._asterisk_status = lambda: {"contacts": [], "fax_ready": True}
lid = d.execute("SELECT id FROM logins WHERE username='phone8800'").fetchone()[0]
c = TestClient(A.app)
H = {"Authorization": "Bearer " + c.post("/api/v1/app/pair", json={"code": app_api._new_pair_code(lid)}).json()["token"]}
from PIL import Image
buf = io.BytesIO(); Image.new("RGB", (400, 120), "black").save(buf, "PNG"); logo = buf.getvalue()
import base64
tpl_file = json.dumps({"format": "ownpbx-fax-template", "version": 1, "name": "Phone Client",
                       "company": "Phone Client LLC", "address": "1 Main St", "phone": "5551112222",
                       "email": "", "website": "", "footer": "Confidential", "header": "PHONE CLIENT",
                       "logo": "data:image/png;base64," + base64.b64encode(logo).decode()}).encode()
with A.db() as cx:
    tid = fax_brand.save_template(cx, lid, None, "Acme", {"company": "Acme Corp", "header": "ACME"})
    cx.commit()
lst = c.get("/api/v1/me/faxes", headers=H).json()
check("list carries saved templates", [t["name"] for t in lst.get("templates", [])] == ["Acme"], lst.get("templates"))
check("list says templates can be saved", lst.get("can_save_templates") is True)
pdf = b"%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"
buf = io.BytesIO(); Image.new("RGB", (850, 1100), "white").save(buf, "PNG"); page = buf.getvalue()
def send(**extra):
    files = {"file": ("doc.png", page, "image/png")}
    data = {"to_number": "3105550100", "from_did": "5550001111", "cover": "1"}
    if "template_file" in extra:
        files["template_file"] = ("t.json", extra.pop("template_file"), "application/json")
    data.update(extra)
    return c.post("/api/v1/me/faxes/send", headers=H, data=data, files=files).json()
r = send(brand_mode="template", template_id=str(tid))
check("send with a saved template", r.get("ok"), r)
r = send(brand_mode="template", template_id="99999")
check("unknown template refused", not r.get("ok") and "template" in r.get("message", ""), r)
before = d.execute("SELECT COUNT(*) FROM fax_templates").fetchone()[0]
r = send(brand_mode="file", template_file=tpl_file)
check("send with a phone template file (one-time)", r.get("ok"), r)
check("one-time file stored nothing", d.execute("SELECT COUNT(*) FROM fax_templates").fetchone()[0] == before)
check("no stray logo left in the brand folder", not [f for f in os.listdir(os.environ["PBX_FAX_BRAND_DIR"]) if "templogo" in f])
r = send(brand_mode="file", template_file=b'{"not":"a template"}')
check("a non-template file is refused", not r.get("ok") and "not a fax template" in r.get("message", ""), r)
r = send(brand_mode="file", template_file=tpl_file, save_template="1")
names = [t["name"] for t in c.get("/api/v1/me/faxes", headers=H).json()["templates"]]
check("send + save adds it to saved templates", r.get("ok") and "Phone Client" in names, (r, names))
p = c.get(f"/api/v1/me/faxes/cover-preview?template_id={tid}&note=", headers=H)
check("preview of a saved template is a PDF", p.status_code == 200 and p.content[:4] == b"%PDF", p.status_code)
p = c.post("/api/v1/me/faxes/cover-preview-file", headers=H, data={"note": "hi"},
           files={"template_file": ("t.json", tpl_file, "application/json")})
check("preview of a phone template file is a PDF", p.status_code == 200 and p.content[:4] == b"%PDF", p.status_code)
check("file preview stored nothing", d.execute("SELECT COUNT(*) FROM fax_templates").fetchone()[0] == before + 1)
print("FAILED:", ", ".join(fails) or "none")
sys.exit(1 if fails else 0)
