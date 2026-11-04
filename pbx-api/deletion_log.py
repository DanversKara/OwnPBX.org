# SPDX-License-Identifier: GPL-2.0-or-later
"""The admin gets a copy of anything deleted or cleared, before it's gone.

What is copied (into deletion_copies, then emailed to the admin as CSV files
in a zip, after which the copy's contents are dropped):

  - Logs: the change log (audit), security events and sign-ins, deleted any
    way at all, including straight in the database over SSH. SQLite triggers
    copy each row as it is deleted.
  - Call history (cdr) and messages, deleted by the admin in the panel or
    straight in the database.
  - "Clear selected / all from view" on the sign-ins list (rows only hidden).

A user hiding messages or calls from their own view is NOT a deletion
(the rows stay, the other party keeps theirs), so it never enters this
pipeline; the change log records the hide instead.

Not copied: rows the normal 90-day / 2-year retention removes, and an
account or extension being wiped on purpose (account deletion requests,
extension reassignment), where keeping a copy would defeat the deletion.
Those run inside quiet(), which the triggers skip.

Email goes to the security-alert address (Email page), else the privacy
contact. Nothing is lost while email isn't set up or the mail server is
down: copies wait and are retried; the Email page shows how many wait.
scripts/pbx-clear-logs is the SSH way to delete logs: it emails the copy
first and only then deletes.
"""
import csv
import io
import json
import os
import threading
import time
import zipfile
from contextlib import contextmanager

M = None
SEND_SECS = 60
MAX_ROWS_PER_EMAIL = 50000
LOGGED = ("audit", "security_events", "signins", "cdr", "messages")
WHO_SSH = "database (SSH / direct)"
_wake = threading.Event()


def _age_rule(tbl):
    """Trigger condition: skip rows the normal retention purge removes."""
    if tbl == "audit":
        return ("(OLD.at >= datetime('now','-89 days') OR ((OLD.action LIKE 'privacy.%' OR "
                "OLD.action LIKE 'ucp.recording_consent%') AND OLD.at >= datetime('now','-729 days')))")
    if tbl == "cdr":
        return "OLD.started_at >= datetime('now','localtime','-89 days')"
    if tbl == "messages":
        return "1"
    return "OLD.at >= datetime('now','-89 days')"


def install_triggers(c):
    """(Re)create the copy-on-delete triggers from the tables' current columns."""
    for tbl in LOGGED:
        cols = [r[1] for r in c.execute(f"PRAGMA table_info({tbl})")]
        if not cols:
            continue
        obj = ", ".join(f"'{col}', OLD.{col}" for col in cols)
        c.execute(f"DROP TRIGGER IF EXISTS copy_on_delete_{tbl}")
        c.execute(f"""CREATE TRIGGER copy_on_delete_{tbl} AFTER DELETE ON {tbl}
            WHEN NOT EXISTS (SELECT 1 FROM deletion_quiet WHERE at >= datetime('now','-2 minutes')) AND {_age_rule(tbl)}
            BEGIN
              INSERT INTO deletion_copies (actor, action, tbl, rows_json)
              VALUES ('{WHO_SSH}', 'deleted', '{tbl}', json_array(json_object({obj})));
            END""")
    c.commit()


@contextmanager
def quiet(c):
    """Deletes inside this block aren't copied by the triggers (the caller
    either copied them itself with capture(), or must not keep a copy).
    The marker only counts for 2 minutes, so a leftover can't switch the
    copies off for good."""
    c.execute("INSERT INTO deletion_quiet (n) VALUES (1)")
    try:
        yield
    finally:
        c.execute("DELETE FROM deletion_quiet")


def capture(c, actor, action, tbl, rows):
    """Copy rows (dicts or sqlite rows) about to be deleted/hidden. Call in the
    same transaction as the delete; the email follows within seconds."""
    rows = [dict(r) for r in rows]
    if not rows:
        return
    for i in range(0, len(rows), 5000):
        c.execute("INSERT INTO deletion_copies (actor, action, tbl, rows_json) VALUES (?,?,?,?)",
                  (str(actor)[:80], action, tbl, json.dumps(rows[i:i + 5000], default=str)))
    _wake.set()


def admin_to():
    with M.db() as c:
        get = lambda k: (c.execute("SELECT value FROM kv_settings WHERE key=?", (k,)).fetchone() or [""])[0]
        return (get("sec_alert_to") or get("privacy_contact") or "").strip()


ACTION_TEXT = {
    "deleted": "deleted",
    "hidden": "cleared from the admin's view (still in the database)",
}
TABLE_TEXT = {"audit": "change log", "security_events": "security events", "signins": "sign-ins",
              "cdr": "call history", "messages": "messages"}


def _csv(rows):
    keys = []
    for r in rows:
        keys += [k for k in r if k not in keys]
    buf = io.StringIO()
    w = csv.DictWriter(buf, fieldnames=keys, extrasaction="ignore")
    w.writeheader()
    for r in rows:
        w.writerow({k: ("" if v is None else v) for k, v in r.items()})
    return buf.getvalue().encode("utf-8")


def build_email(entries):
    """(subject, text, zip bytes) for pending copies [(id, at, actor, action, tbl, rows)]."""
    groups = {}
    for e in entries:
        groups.setdefault((e["actor"], e["action"], e["tbl"]), []).append(e)
    lines = ["These records were deleted or cleared on your phone system. A copy of each is attached",
             "(one CSV file per group), so you can see exactly what was removed.", ""]
    z = io.BytesIO()
    total = 0
    with zipfile.ZipFile(z, "w", zipfile.ZIP_DEFLATED) as zf:
        for n, ((actor, action, tbl), es) in enumerate(sorted(groups.items()), 1):
            rows = [r for e in es for r in e["rows"]]
            total += len(rows)
            first, last = min(e["at"] for e in es), max(e["at"] for e in es)
            lines.append(f"- {len(rows)} {TABLE_TEXT.get(tbl, tbl)} row(s) {ACTION_TEXT.get(action, action)}")
            lines.append(f"    by: {actor}   when: {first} UTC" + (f" to {last} UTC" if last != first else ""))
            safe = "".join(ch if ch.isalnum() else "-" for ch in actor)[:30].strip("-") or "unknown"
            zf.writestr(f"{n:02d}-{tbl}-{action}-by-{safe}.csv", _csv(rows))
    lines += ["", "Rows removed by the normal 90-day (2-year for privacy records) retention, and accounts",
              "deleted on request, are not copied."]
    ssh = any(a == WHO_SSH for a, _, _ in groups)
    # The SSH warning used to ride along on EVERY deletion email, so a
    # routine delete from a user's own view arrived warning about SSH
    # access — and read as if someone had used SSH when nobody had. It
    # belongs only on emails that actually contain a direct-database group.
    if ssh:
        lines += ["",
                  "If you didn't expect a deletion by \"" + WHO_SSH + "\", someone with access to the server",
                  "itself changed the database: check who can sign in to it over SSH."]
    subj = ("Security alert: logs deleted on the server" if ssh else "Deleted records") + f" ({total} rows)"
    return subj, "\n".join(lines), z.getvalue()


def send_pending():
    """Email waiting copies; on success drop their contents. Returns rows sent."""
    to = admin_to()
    if not to:
        return 0
    with M.db() as c:
        cfg = M.mailer.settings(c)
        if not M.mailer.configured(cfg):
            return 0
        raw = c.execute("SELECT id, at, actor, action, tbl, rows_json FROM deletion_copies "
                        "WHERE emailed_at IS NULL ORDER BY id LIMIT 20000").fetchall()
    entries, count = [], 0
    for r in raw:
        rows = json.loads(r["rows_json"] or "[]")
        if entries and count + len(rows) > MAX_ROWS_PER_EMAIL:
            break
        entries.append({"id": r["id"], "at": r["at"], "actor": r["actor"], "action": r["action"],
                        "tbl": r["tbl"], "rows": rows})
        count += len(rows)
    if not entries:
        return 0
    subj, text, data = build_email(entries)
    try:
        M.mailer.send(cfg, to, subj, text,
                      attachment=(f"deleted-records-{time.strftime('%Y%m%d-%H%M%S')}.zip", data, "application/zip"))
        err = ""
    except Exception as e:
        err = str(e)[:300] or e.__class__.__name__
    ids = [e["id"] for e in entries]
    with M.db() as c:
        for i in range(0, len(ids), 500):
            chunk = ids[i:i + 500]
            q = ",".join("?" * len(chunk))
            if err:
                c.execute(f"UPDATE deletion_copies SET last_error=? WHERE id IN ({q})", (err, *chunk))
            else:
                c.execute(f"UPDATE deletion_copies SET emailed_at=datetime('now'), rows_json='', "
                          f"last_error='' WHERE id IN ({q})", chunk)
        c.commit()
    return 0 if err else count


def waiting():
    """(copies waiting, last error) for the Email page."""
    with M.db() as c:
        n = c.execute("SELECT COUNT(*) FROM deletion_copies WHERE emailed_at IS NULL").fetchone()[0]
        e = c.execute("SELECT last_error FROM deletion_copies WHERE emailed_at IS NULL AND last_error<>'' "
                      "ORDER BY id DESC LIMIT 1").fetchone()
    return n, (e[0] if e else "")


def _loop():
    time.sleep(5)
    while True:
        _wake.clear()
        try:
            while send_pending():
                pass
            with M.db() as c:   # the record of what was sent is kept a year
                c.execute("DELETE FROM deletion_copies WHERE emailed_at < datetime('now','-365 days')")
                c.commit()
        except Exception:
            pass
        _wake.wait(SEND_SECS)


def install(app_module):
    global M
    M = app_module
    with M.db() as c:
        c.execute("DELETE FROM deletion_quiet")   # a leftover must never switch the copies off
        install_triggers(c)
    if os.environ.get("PBX_NO_BG") != "1":
        threading.Thread(target=_loop, daemon=True, name="deletion-copies").start()
