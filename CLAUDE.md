# Notes for AI assistants working on this repo

- Before any Android app build or release (a change under android/ that
  will ship as a new app version), read Future_Plans.txt. Items marked
  "QUEUED FOR THE NEXT ANDROID APP BUILD" are to be built into that same
  release without asking the owner first; delete the item from the file
  once it has shipped.
- Tests: export PBX_DB=<tmp>.db; rm -f $PBX_DB; python3 dev/seed-test-db.py;
  then python3 dev/test-*.py (each prints PASS/FAIL lines).
- Docs site changes: send the owner only the changed files, never
  overwrite images.
