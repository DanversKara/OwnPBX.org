#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-2.0-or-later
"""The admin gets a copy of anything deleted or cleared (deletion_log.py):
direct database deletes (SSH), panel deletes, "clear from view", users
deleting their messages/calls; retention and account deletion are not
copied; nothing is lost while email fails; pbx-clear-logs emails first.

    export PBX_DB=/tmp/apptest.db && rm -f $PBX_DB && python3 dev/seed-test-db.py
    python3 dev/test-deletion-copies.py      # exits non-zero on failure
"""
import io, os, re, sqlite3, subprocess, sys, tempfile, zipfile, csv
here = os.path.dirname(os.path.abspath(__file__))
os.environ["PBX_NO_BG"] = "1"
os.environ["PBX_MEDIA_DIR"] = tempfile.mkdtemp(prefix="media-")
sys.path.insert(0, os.path.join(here, "..", "shared"))
sys.path.insert(0, os.path.join(here, "..", "pbx-api"))
from fastapi.testclient import TestClient
import app as A, app_api, deletion_log, mailer, ucp
import bcrypt
A.init_db()
deletion_log.install(A)
fails = []
def check(n, ok, x=""):
    print(("PASS " if ok else "FAIL ") + n, "" if ok else x); ok or fails.append(n)

sent = []
mailer.configured = lambda cfg: True
mailer.send = lambda cfg, to, subj, text, html=None, attachment=None, timeout=20: sent.append((to, subj, text, attachment))
d = A.db()
d.execute("INSERT OR REPLACE INTO kv_settings (key, value) VALUES ('sec_alert_to', 'boss@example.com')")
d.commit()

def last_zip():
    z = zipfile.ZipFile(io.BytesIO(sent[-1][3][1]))
    return {n: list(csv.DictReader(io.StringIO(z.read(n).decode()))) for n in z.namelist()}

def pending():
    return d.execute("SELECT COUNT(*) FROM deletion_copies WHERE emailed_at IS NULL").fetchone()[0]

# --- someone deletes logs straight in the database (like sqlite3 over SSH)
for i in range(3):
    d.execute("INSERT INTO audit (actor, action, detail) VALUES ('admin', 'test.change', ?)", (f"change {i}",))
d.execute("INSERT INTO security_events (source, kind, ip, detail) VALUES ('panel', 'ban', '203.0.113.7', 'x')")
d.commit()
raw = sqlite3.connect(os.environ["PBX_DB"])
raw.execute("DELETE FROM audit"); raw.execute("DELETE FROM security_events"); raw.commit()
check("direct deletes are copied", pending() >= 4, pending())
check("emailed", deletion_log.send_pending() >= 4 and sent and sent[-1][0] == "boss@example.com")
check("subject says it was on the server", "logs deleted on the server" in sent[-1][1].lower(), sent[-1][1])
z = last_zip()
check("zip has the deleted rows", any("change 1" in r.get("detail", "") for rows in z.values() for r in rows) and
      any(r.get("ip") == "203.0.113.7" for rows in z.values() for r in rows), list(z))
check("copy's contents dropped after emailing", d.execute(
    "SELECT COUNT(*) FROM deletion_copies WHERE rows_json<>''").fetchone()[0] == 0)

# --- retention purge isn't copied
d.execute("INSERT INTO audit (at, actor, action, detail) VALUES (datetime('now','-100 days'), 'a', 'old', 'old')")
d.commit()
import security; security.purge()
check("90-day purge not copied", pending() == 0, pending())

# --- email down: kept and retried
d.execute("INSERT INTO audit (actor, action, detail) VALUES ('a', 'x', 'keep me')"); d.commit()
raw.execute("DELETE FROM audit WHERE detail='keep me'"); raw.commit()
def boom(*a, **k): raise mailer.MailError("SMTP down")
good = mailer.send; mailer.send = boom
check("mail down: nothing lost", deletion_log.send_pending() == 0 and pending() == 1)
check("error shown", "SMTP down" in deletion_log.waiting()[1])
mailer.send = good
check("sent once mail works", deletion_log.send_pending() == 1 and pending() == 0)

# --- admin panel: cdr delete, messages delete, clear sign-ins from view
pw = lambda p: bcrypt.hashpw(p.encode(), bcrypt.gensalt()).decode()
adm = d.execute("SELECT username FROM logins WHERE role='admin' LIMIT 1").fetchone()[0]
d.execute("UPDATE logins SET pwhash=? WHERE username=?", (pw("pw-admin-1"), adm))
d.execute("INSERT INTO cdr (call_id, started_at, src, dst, direction, duration_sec, disposition)"
          " VALUES ('cx1', datetime('now','localtime'), '8800', '5551112222', 'out', 30, 'ANSWERED')")
d.execute("INSERT INTO messages (from_ext, to_ext, body) VALUES ('8800', '8801', 'secret plan')")
d.commit()
A._login_failures.clear()
ac = TestClient(A.app, client=("192.168.1.50", 5555), follow_redirects=False)
ac.post("/login", data={"username": adm, "password": "pw-admin-1"})
tok = re.search(r'name="csrf_token" value="([^"]+)"', ac.get("/cdr").text).group(1)
cid = d.execute("SELECT id FROM cdr WHERE call_id='cx1'").fetchone()[0]
ac.post("/cdr/delete", data={"csrf_token": tok, "ids": str(cid)})
check("cdr deleted", not d.execute("SELECT 1 FROM cdr WHERE id=?", (cid,)).fetchone())
mid = d.execute("SELECT id FROM messages WHERE body='secret plan'").fetchone()[0]
ac.post("/messages/delete", data={"csrf_token": tok, "ids": str(mid)})
check("message deleted", not d.execute("SELECT 1 FROM messages WHERE id=?", (mid,)).fetchone())
check("each copied once (not twice by the triggers)", pending() == 2, pending())
deletion_log.send_pending(); z = last_zip()
check("copy names the admin and has the call + the text",
      any("by-" + adm in n for n in z) and any(r.get("dst") == "5551112222" for rows in z.values() for r in rows)
      and any(r.get("body") == "secret plan" for rows in z.values() for r in rows), list(z))
d.execute("INSERT INTO signins (login, ip, ok) VALUES ('mallory', '198.51.100.9', 0)"); d.commit()
r = ac.post("/api/v1/signins/delete", json={"all": True}, headers={"X-CSRF-Token": tok})
check("clear all from view", r.status_code == 200, r.text)
check("hidden, not deleted", d.execute("SELECT hidden FROM signins WHERE login='mallory'").fetchone()[0] == 1)
deletion_log.send_pending()
check("cleared sign-ins emailed", "cleared from the admin's view" in sent[-1][2] and
      any(r.get("login") == "mallory" for rows in last_zip().values() for r in rows))
check("no stray skip flag left", d.execute("SELECT COUNT(*) FROM deletion_quiet").fetchone()[0] == 0)

# --- a user deleting messages / calls from their own view
H0 = {"Authorization": "Bearer " + TestClient(A.app).post("/api/v1/app/pair", json={
    "code": app_api._new_pair_code(d.execute("SELECT id FROM logins WHERE exten='8800'").fetchone()[0])}).json()["token"]}
c = TestClient(A.app)
d.execute("INSERT INTO messages (from_ext, to_ext, body) VALUES ('8801', '8800', 'delete me later')"); d.commit()
mid = d.execute("SELECT id FROM messages WHERE body='delete me later'").fetchone()[0]
r = c.post("/api/v1/me/messages/delete", headers=H0, data={"ids": str(mid)})
check("user hides a message", r.status_code == 200, r.text)
d.execute("INSERT INTO cdr (call_id, started_at, src, dst, direction, duration_sec, disposition)"
          " VALUES ('cx2', datetime('now','localtime'), '8800', '8801', 'internal', 5, 'ANSWERED')"); d.commit()
cid = d.execute("SELECT id FROM cdr WHERE call_id='cx2'").fetchone()[0]
r = c.post("/api/v1/me/calls/hide", headers=H0, data={"ids": str(cid)})
deletion_log.send_pending(); z = last_zip()
check("user's deletes emailed to admin", "deleted from the user's own view" in sent[-1][2] and
      any(r.get("body") == "delete me later" for rows in z.values() for r in rows) and
      any(r.get("call_id") == "cx2" for rows in z.values() for r in rows), (sent[-1][2], list(z)))

# --- account deletion is not copied
uid = d.execute("SELECT id FROM logins WHERE exten='8801'").fetchone()[0]
d.execute("INSERT INTO messages (from_ext, to_ext, body) VALUES ('8801', '5550000000', 'private')"); d.commit()
A.wipe_login(uid)
check("account deletion leaves no copy", pending() == 0, pending())
check("account's texts are gone", not d.execute("SELECT 1 FROM messages WHERE body='private'").fetchone())
check("triggers still on afterwards", (d.execute("INSERT INTO audit (actor, action) VALUES ('a','b')"), d.commit(),
      raw.execute("DELETE FROM audit WHERE action='b'"), raw.commit(), pending())[-1] == 1)
deletion_log.send_pending()

# --- pbx-clear-logs (SSH command): emails first, then deletes; refuses without email
env = dict(os.environ, PBX_API_DIR=os.path.join(here, "..", "pbx-api"), PBX_CLEAR_AS_ROOT="1")
script = os.path.join(here, "..", "scripts", "pbx-clear-logs.py")
d.execute("INSERT INTO audit (actor, action, detail) VALUES ('a', 'y', 'ssh-row')"); d.commit()
out = subprocess.run([sys.executable, script, "--dry-run", "--only", "audit"], env=env, capture_output=True, text=True)
check("dry run deletes nothing", "Dry run" in out.stdout and d.execute("SELECT COUNT(*) FROM audit WHERE detail='ssh-row'").fetchone()[0] == 1, out.stdout + out.stderr)
d.execute("DELETE FROM kv_settings WHERE key='sec_alert_to'"); d.commit()
out = subprocess.run([sys.executable, script, "--only", "audit"], env=env, capture_output=True, text=True)
check("no admin email: refuses", out.returncode == 1 and "NOT deleted" in out.stdout and
      d.execute("SELECT COUNT(*) FROM audit WHERE detail='ssh-row'").fetchone()[0] == 1, out.stdout + out.stderr)
# --- and with a (local, fake) mail server it emails, then deletes
import socket, threading
got = []
srv = socket.socket(); srv.bind(("127.0.0.1", 0)); srv.listen(1); port = srv.getsockname()[1]
def smtp():
    conn, _ = srv.accept(); f = conn.makefile("rwb")
    say = lambda t: (f.write(t.encode() + b"\r\n"), f.flush())
    say("220 test"); data = False; buf = []
    for line in f:
        t = line.decode(errors="replace").rstrip("\r\n")
        if data:
            if t == ".": data = False; got.append("\n".join(buf)); say("250 ok"); continue
            buf.append(t); continue
        u = t.upper()
        if u.startswith(("EHLO", "HELO")): say("250 test")
        elif u.startswith("DATA"): data = True; say("354 go")
        elif u.startswith("QUIT"): say("221 bye"); break
        else: say("250 ok")
    conn.close()
threading.Thread(target=smtp, daemon=True).start()
for k, v in (("sec_alert_to", "boss@example.com"), ("smtp_host", "127.0.0.1"), ("smtp_port", str(port)),
             ("smtp_security", "none"), ("smtp_from", "pbx@example.com")):
    d.execute("INSERT OR REPLACE INTO kv_settings (key, value) VALUES (?,?)", (k, v))
d.commit()
out = subprocess.run([sys.executable, script, "--only", "audit"], env=env, capture_output=True, text=True)
check("pbx-clear-logs emails, then deletes", out.returncode == 0 and got and "deleted-logs.zip" in got[0]
      and d.execute("SELECT COUNT(*) FROM audit WHERE detail='ssh-row'").fetchone()[0] == 0, out.stdout + out.stderr)
check("it leaves a note in the change log", d.execute("SELECT COUNT(*) FROM audit WHERE action='logs.cleared'").fetchone()[0] == 1)
check("and doesn't email the rows twice", pending() == 0, pending())

print("FAILED:", ", ".join(fails) or "none")
sys.exit(1 if fails else 0)
