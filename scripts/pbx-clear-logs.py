#!/opt/pbx/api-venv/bin/python
# SPDX-License-Identifier: GPL-2.0-or-later
"""pbx-clear-logs: delete the PBX's logs from the server's shell (SSH).

The admin is emailed a copy of everything first (CSV files in a zip); only
when that email has gone out are the rows deleted. If the email can't be
sent, nothing is deleted. There is deliberately no button for this in the
web panel.

    sudo pbx-clear-logs                  # change log, security events, sign-ins: everything
    sudo pbx-clear-logs --last-24h       # only the last 24 hours (what the panel shows)
    sudo pbx-clear-logs --only audit     # one kind: audit | security_events | signins
    sudo pbx-clear-logs --dry-run        # show what would go, delete nothing

Deleting rows any other way (sqlite3 by hand) still emails the admin a copy:
the database copies deleted rows and the panel sends them within a minute.
"""
import argparse
import os
import sqlite3
import sys

API_DIR = os.environ.get("PBX_API_DIR", "/opt/pbx/pbx-api")
DB = os.environ.get("PBX_DB", "/var/lib/pbx/pbx.db")
TABLES = {"audit": "change log", "security_events": "security events", "signins": "sign-ins"}


def main():
    ap = argparse.ArgumentParser(description="Email the admin a copy of the logs, then delete them.")
    ap.add_argument("--last-24h", action="store_true", help="only rows from the last 24 hours")
    ap.add_argument("--only", choices=sorted(TABLES), action="append", help="limit to these logs")
    ap.add_argument("--dry-run", action="store_true", help="count only; send and delete nothing")
    a = ap.parse_args()

    # Work as the PBX's own user so the database files keep the right owner.
    if os.geteuid() == 0 and os.environ.get("PBX_CLEAR_AS_ROOT") != "1":
        os.execvp("runuser", ["runuser", "-u", "pbx", "--", sys.executable, os.path.abspath(__file__), *sys.argv[1:]])

    sys.path.insert(0, API_DIR)
    import deletion_log
    import mailer

    tables = a.only or list(TABLES)
    when = " WHERE at >= datetime('now','-1 day')" if a.last_24h else ""
    c = sqlite3.connect(DB, timeout=30)
    c.row_factory = sqlite3.Row
    who = f"pbx-clear-logs over SSH (user {os.environ.get('SUDO_USER') or os.environ.get('USER') or '?'})"
    entries, total = [], 0
    for t in tables:
        rows = [dict(r) for r in c.execute(f"SELECT * FROM {t}{when}").fetchall()]
        print(f"{TABLES[t]}: {len(rows)} row(s)")
        if rows:
            entries.append({"id": 0, "at": c.execute("SELECT datetime('now')").fetchone()[0],
                            "actor": who, "action": "deleted", "tbl": t, "rows": rows})
            total += len(rows)
    if not total:
        print("Nothing to delete.")
        return 0
    if a.dry_run:
        print("Dry run: nothing sent, nothing deleted.")
        return 0

    get = lambda k: (c.execute("SELECT value FROM kv_settings WHERE key=?", (k,)).fetchone() or [""])[0]
    to = (get("sec_alert_to") or get("privacy_contact") or "").strip()
    cfg = mailer.settings(c)
    if not to or not mailer.configured(cfg):
        print("NOT deleted: no admin email is set up (Email page: mail server and Security alerts address).")
        return 1
    subj, text, data = deletion_log.build_email(entries)
    try:
        mailer.send(cfg, to, "Logs deleted on the server" + subj[subj.rfind(" ("):], text,
                    attachment=("deleted-logs.zip", data, "application/zip"))
    except Exception as e:
        print(f"NOT deleted: couldn't email the copy to {to}: {e}")
        return 1
    print(f"Copy emailed to {to}.")

    with c:
        c.execute("INSERT INTO deletion_quiet (n) VALUES (1)")   # already emailed above
        n = sum(c.execute(f"DELETE FROM {t}{when}").rowcount for t in tables)
        c.execute("DELETE FROM deletion_quiet")
        c.execute("INSERT INTO audit (actor, action, detail) VALUES (?,?,?)",
                  (who, "logs.cleared", f"{n} rows of {', '.join(tables)}{' (last 24h)' if a.last_24h else ''};"
                                         f" copy emailed to {to}"))
    print(f"Deleted {n} row(s). A note that this happened stays in the change log.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
