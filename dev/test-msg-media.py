#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-2.0-or-later
"""Photos, videos and GIFs in messages (msg_media.py): sending from the app
and the web, hidden photo details removed, GIFs kept animated, who may open
a file, forwarding, texting media to a phone number (voip.ms sendMMS with
media1..3 and the public /mms/ link), reports, the web page (emoji picker,
reply box), and account deletion.

    export PBX_DB=/tmp/apptest.db && rm -f $PBX_DB && python3 dev/seed-test-db.py
    python3 dev/test-msg-media.py      # exits non-zero on failure
"""
import io, os, re, sys, tempfile
os.environ["PBX_NO_BG"] = "1"
here = os.path.dirname(os.path.abspath(__file__))
os.environ["PBX_MEDIA_DIR"] = tempfile.mkdtemp(prefix="media-")
os.environ["PBX_EXPORT_DIR"] = tempfile.mkdtemp(prefix="exports-")
sys.path.insert(0, os.path.join(here, "..", "shared"))
sys.path.insert(0, os.path.join(here, "..", "pbx-api"))
from fastapi.testclient import TestClient
import app as A, app_api, msg_media, ucp
import bcrypt
from PIL import Image
A.init_db()
fails = []
def check(n, ok, x=""):
    print(("PASS " if ok else "FAIL ") + n, "" if ok else x); ok or fails.append(n)

d = A.db()
d.execute("INSERT OR IGNORE INTO logins (username, pwhash, role, exten, sip_username, display_name)"
          " VALUES ('phone8802','x','user','8802','phone8802','Third')")
d.execute("UPDATE logins SET max_messages=1000 WHERE exten IN ('8800','8801','8802')")
d.commit()
uid = lambda u: d.execute("SELECT id FROM logins WHERE username=?", (u,)).fetchone()[0]
ucp._quota = lambda me, k: None
ucp._left = lambda me, k: None
A._best_effort_apply = lambda: None
c = TestClient(A.app)
tok = lambda u: {"Authorization": "Bearer " + c.post("/api/v1/app/pair", json={"code": app_api._new_pair_code(uid(u))}).json()["token"]}
H0, H1, H2 = tok("phone8800"), tok("phone8801"), tok("phone8802")

def jpeg_with_gps():
    im = Image.new("RGB", (3000, 2000), (200, 30, 30))
    ex = Image.Exif(); ex[0x8825] = {2: (37.0, 46.0, 30.0)}; ex[0x010F] = "SecretCam"
    b = io.BytesIO(); im.save(b, "JPEG", exif=ex); return b.getvalue()

def gif2():
    frames = [Image.new("P", (40, 40), i * 80) for i in range(3)]
    b = io.BytesIO(); frames[0].save(b, "GIF", save_all=True, append_images=frames[1:], duration=100, loop=0)
    return b.getvalue()

def last_msg(H, peer):
    return c.get(f"/api/v1/me/threads/{peer}", headers=H).json()["messages"][0]

# --- photo from the app to another extension
r = c.post("/api/v1/me/messages/send", headers=H0, data={"to": "8801", "body": "look 😀"},
           files=[("media", ("p.jpg", jpeg_with_gps(), "image/jpeg"))])
check("photo sent", r.status_code == 200 and r.json()["ok"], r.text)
m = last_msg(H1, "8800")
check("recipient sees text and one photo", m["text"] == "look 😀" and len(m["media"]) == 1 and m["media"][0]["kind"] == "image", m)
url = m["media"][0]["url"]
img = c.get(url, headers=H1)
check("recipient can open it", img.status_code == 200 and img.headers["content-type"] == "image/jpeg", img.status_code)
pic = Image.open(io.BytesIO(img.content))
check("hidden photo details removed", not pic.getexif(), dict(pic.getexif()))
check("shrunk to 2048px", max(pic.size) == 2048, pic.size)
check("sender can open it", c.get(url, headers=H0).status_code == 200)
check("someone else can't", c.get(url, headers=H2).status_code == 404)
check("no sign-in, no file", TestClient(A.app).get(url).status_code == 401)
fn = url.rsplit("/", 1)[1]
check("not public (internal only)", c.get("/mms/" + fn).status_code == 404)
check("download gives an attachment", "attachment" in c.get(url + "?dl=1", headers=H1).headers.get("content-disposition", ""))

# --- GIF stays animated (bytes untouched); media-only message gets a label
g = gif2()
c.post("/api/v1/me/messages/send", headers=H0, data={"to": "8801", "body": ""}, files=[("media", ("a.gif", g, "image/gif"))])
m = last_msg(H1, "8800")
check("GIF kept as is", c.get(m["media"][0]["url"], headers=H1).content == g and m["media"][0]["kind"] == "gif", m)
check("media-only message reads 'GIF'", m["body"] == "🎞️ GIF" and m["text"] == "", m)

# --- refused files
r = c.post("/api/v1/me/messages/send", headers=H0, data={"to": "8801", "body": "x"},
           files=[("media", ("evil.jpg", b"#!/bin/sh\nrm -rf /", "image/jpeg"))])
check("non-media file refused with a reason", r.status_code == 400 and "Only photos" in r.json()["message"], r.text)
r = c.post("/api/v1/me/messages/send", headers=H0, data={"to": "8801", "body": "x"},
           files=[("media", (f"{i}.gif", g, "image/gif")) for i in range(4)])
check("more than 3 refused", r.json()["code"] == "msgmany", r.text)

# --- a typed marker can't attach someone else's file
other = msg_media.FILE.fullmatch(fn).group(1)
c.post("/api/v1/me/messages/send", headers=H2, data={"to": "8800", "body": f"hi [MMS: media:{fn}]"})
m = last_msg(H2, "8800")
check("typed marker stripped", m["media"] == [] and "MMS" not in m["text"], m)
check("still can't open it", c.get(url, headers=H2).status_code == 404)

# --- forwarding
r = c.post("/api/v1/me/messages/send", headers=H2, data={"to": "8800", "body": "", "fwd": f"media:{fn}"})
check("can't forward a file you can't see", r.json()["code"] == "msgmedia", r.text)
r = c.post("/api/v1/me/messages/send", headers=H1, data={"to": "8802", "body": "fwd", "fwd": f"media:{fn}"})
check("recipient forwards it on", r.json()["ok"], r.text)
check("new recipient can open it", c.get(url, headers=H2).status_code == 200)
remote = "https://voip.ms/media/abc123.png"
d.execute("INSERT INTO messages (from_ext, to_ext, body, login_id) VALUES ('5551234567','8801',?,NULL)",
          (f"pic [MMS: {remote}]",)); d.commit()
m = last_msg(H1, "5551234567")
check("incoming MMS shows as a photo", m["media"] and m["media"][0]["url"] == remote and m["media"][0]["kind"] == "image", m)
check("incoming MMS can be forwarded", c.post("/api/v1/me/messages/send", headers=H1,
      data={"to": "8800", "body": "", "fwd": remote}).json()["ok"])

# --- to a phone number: MMS via voip.ms with public links
sent = []
V = ucp.voipms_sms
V.get_voipms_config = lambda c_: {"voipms_api_username": "u", "voipms_api_password": "p"}
V.lookup_conversation_did = lambda *a: "5550001111"
V.send_mms_via_voipms = lambda u, p, did, dst, body, urls: sent.append(("mms", dst, body, urls))
V.send_sms_via_voipms = lambda u, p, did, dst, body: sent.append(("sms", dst, body))
r = c.post("/api/v1/me/messages/send", headers=H0, data={"to": "5557654321", "body": "hey"},
           files=[("media", ("p.jpg", jpeg_with_gps(), "image/jpeg"))])
check("MMS sent", r.json()["ok"] and sent and sent[-1][0] == "mms", (r.text, sent))
u = sent[-1][3][0] if sent else ""
check("voip.ms gets a public /mms/ link", "/mms/" in u, u)
pub = TestClient(A.app).get("/mms/" + u.rsplit("/", 1)[-1])
check("public link works without sign-in", pub.status_code == 200, pub.status_code)
check("photo shrunk under 1.2 MB for MMS", len(pub.content) <= msg_media.MAX_MMS, len(pub.content))
big = b"\x00\x00\x00\x18ftypmp42" + os.urandom(2 * 1024 * 1024)
r = c.post("/api/v1/me/messages/send", headers=H0, data={"to": "5557654321", "body": ""},
           files=[("media", ("v.mp4", big, "video/mp4"))])
check("big video to a phone number refused", r.status_code == 400 and "too big" in r.json()["message"], r.text)
r = c.post("/api/v1/me/messages/send", headers=H0, data={"to": "8801", "body": ""},
           files=[("media", ("v.mp4", big, "video/mp4"))])
check("same video to an extension is fine", r.json()["ok"] and last_msg(H1, "8800")["media"][0]["kind"] == "video", r.text)
c.post("/api/v1/me/messages/send", headers=H0, data={"to": "5557654321", "body": "plain"})
check("plain text still goes as SMS", sent[-1][0] == "sms", sent[-1])

# --- web: page, reply box, send with a file, report with media
pw = bcrypt.hashpw(b"pw-web-12345", bcrypt.gensalt()).decode()
d.execute("UPDATE logins SET pwhash=? WHERE exten='8801'", (pw,)); d.commit()
A._login_failures.clear()
w = TestClient(A.app, follow_redirects=False)
w.post("/login", data={"username": "phone8801", "password": "pw-web-12345"})
t = w.get("/ucp/messages?with=8800").text
check("web shows the photo", f'src="/ucp/media/{fn}"' in t, "")
check("web has reply box under the conversation", 'class="msg-compose msg-reply"' in t and 'name="to" value="8800"' in t)
check("web has emoji picker and attach button", 'class="emo-grid"' in t and 'name="media"' in t and "😂" in t)
check("web has download and forward", f"/ucp/media/{fn}?dl=1" in t and 'name="fwd"' in t)
check("web forms send files", t.count('enctype="multipart/form-data"') >= 2)
check("web GIF animates (plain img)", "alt=\"GIF\"" in t)
check("web video player", "<video" in t)
csrf = re.search(r'name="csrf_token" value="([^"]+)"', t).group(1)
r = w.post("/ucp/messages/send", data={"csrf_token": csrf, "to": "8800", "body": "from web"},
           files=[("media", ("a.gif", g, "image/gif"))])
check("web send with GIF", "msg=msgsent" in r.headers.get("location", ""), r.headers.get("location"))
r = w.post("/ucp/messages/send", data={"csrf_token": csrf, "to": "8800", "body": ""},
           files=[("media", ("x.txt", b"hello", "text/plain"))])
check("web shows why a file was refused", "merr=" in r.headers.get("location", "") and
      "Only photos" in w.get(r.headers["location"]).text, r.headers.get("location"))
mid = d.execute("SELECT id FROM messages WHERE body LIKE ? AND from_ext='8800' ORDER BY id LIMIT 1", (f"%{fn}%",)).fetchone()[0]
H1 = tok("phone8801")   # the password change above signed the app out
r = c.post("/api/v1/me/report", headers=H1, data={"kind": "message", "ref": str(mid), "note": "rude pic"})
check("report a photo", r.json().get("ok"), r.text)
check("report keeps the file", fn in d.execute("SELECT detail FROM reports ORDER BY id DESC LIMIT 1").fetchone()[0])

# --- download a whole conversation
r = c.get("/api/v1/me/messages/export", headers=H1, params={"with": "8800"})
check("conversation downloads as text", r.status_code == 200 and "attachment" in r.headers["content-disposition"]
      and "look 😀" in r.text and "/ucp/media/" in r.text, r.text[:200])
check("web has Download conversation", "/ucp/messages/export?with=8800" in w.get("/ucp/messages?with=8800").text)
check("no conversation, 404", c.get("/api/v1/me/messages/export", headers=H1, params={"with": "999"}).status_code == 404)

# --- MMS pictures get a local copy (provider links vanish when they're down)
outage = {"down": True}
pic = jpeg_with_gps()
def fake_fetch(u):
    if outage["down"]:
        raise OSError("HTTP Error 522: Connection timed out")
    return pic, "image/jpeg"
msg_media._fetch = fake_fetch
murl = "https://voip.ms/media/mms-xyz.jpg"
d.execute("INSERT INTO messages (from_ext, to_ext, body, login_id) VALUES ('5551234567','8801',?,NULL)",
          (f"new pic [MMS: {murl}]",)); d.commit()
check("provider down: nothing saved", msg_media.cache_pass() == 0)
row = d.execute("SELECT tries, last_error, next_try > datetime('now') AS later FROM mms_cache WHERE url=?", (murl,)).fetchone()
check("failure noted, retried later", row and row["tries"] == 1 and "522" in row["last_error"] and row["later"], dict(row) if row else None)
check("not retried before its time", msg_media.cache_pass() == 0 and
      d.execute("SELECT tries FROM mms_cache WHERE url=?", (murl,)).fetchone()[0] == 1)
d.execute("UPDATE mms_cache SET next_try=datetime('now','-1 minute') WHERE url=?", (murl,)); d.commit()
outage["down"] = False
check("provider back: copy saved", msg_media.cache_pass() == 1)
m = last_msg(H1, "5551234567")
check("message now points at the PBX copy", m["media"] and m["media"][0]["url"].startswith("/api/v1/me/media/") and "voip.ms" not in m["media"][0]["url"], m)
check("copy opens for the recipient", c.get(m["media"][0]["url"], headers=H1).content == pic)
check("copy is private", c.get(m["media"][0]["url"], headers=H2).status_code == 404)
outage["down"] = True
check("still there when the provider goes down again", c.get(m["media"][0]["url"], headers=H1).status_code == 200)
msg_media._fetch = lambda u: (b"<!DOCTYPE html><html>error</html>", "text/html")
d.execute("INSERT INTO messages (from_ext, to_ext, body, login_id) VALUES ('5551234567','8801',?,NULL)",
          ("[MMS: https://voip.ms/media/err.jpg]",)); d.commit()
check("an error page isn't saved as the picture", msg_media.cache_pass() == 0 and
      d.execute("SELECT token FROM mms_cache WHERE url LIKE '%err.jpg'").fetchone()[0] is None)
msg_media._wake.clear(); msg_media.kick("hello"); k1 = msg_media._wake.is_set()
msg_media.kick("pic [MMS: https://voip.ms/media/a.jpg]")
check("a new MMS wakes the copier at once (plain texts don't)", not k1 and msg_media._wake.is_set())
check("web shows a note when a provider picture won't load", "Picture not available right now" in w.get("/ucp/messages?with=8800").text)

# --- account deletion removes their files
path = os.path.join(msg_media.MEDIA_DIR, fn)
check("file on disk", os.path.isfile(path))
with A.db() as cc:
    msg_media.wipe_login(cc, uid("phone8800")); cc.commit()
check("deleted with the account", not os.path.isfile(path) and
      not d.execute("SELECT COUNT(*) FROM message_media WHERE login_id=?", (uid("phone8800"),)).fetchone()[0])

print("FAILED:", ", ".join(fails) or "none")
sys.exit(1 if fails else 0)
