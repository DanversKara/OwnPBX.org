# SPDX-License-Identifier: GPL-2.0-or-later
"""Presence and photos for contacts (My Phone web + the app).

Presence, for contacts that are another extension on this PBX (first
match wins):
  hidden  = that user turned off "Show my online status"
  oncall  = on a call right now (talking)
  dnd     = Do Not Disturb is on
  online  = one of that login's phones/apps is signed in right now
  recent  = was signed in within RECENT_SECS (30 min)
  offline = longer ago, or never
Signed-in comes from the same Asterisk registrations the dashboard's
"Signed in now" shows. A sampler (every SAMPLE_SECS) stamps
logins.last_seen_at while a login is online, so "recent" survives a
dropped connection. The PBX enforces the privacy switch: a hidden user's
presence never leaves the server.

Photos (resized to PHOTO_PX square JPEG, stored in PHOTO_DIR):
  - a user's own profile photo (logins.photo); any signed-in user of this
    PBX may view it (it shows on their contact for you)
  - a photo a user sets on one of their contacts (contacts.photo); only
    that user can view it
  - per contact, contacts.photo_pref picks which to show for an extension:
    auto (theirs if they have one, else mine) | theirs | mine
"""
import io
import os
import secrets
import threading
import time

from fastapi import HTTPException, Request
from fastapi.responses import FileResponse, JSONResponse, RedirectResponse

M = None
PHOTO_DIR = os.environ.get("PBX_PHOTO_DIR", "/var/lib/pbx/photos")
PHOTO_PX = 256
MAX_UPLOAD = 10 * 1024 * 1024
RECENT_SECS = 30 * 60
SAMPLE_SECS = 60
ONLINE_STATES = {"Avail", "Reachable", "NonQual", "Unknown"}
PREFS = ("auto", "theirs", "mine")


# ------------------------------------------------------------------ presence

def _online_sip_users():
    """SIP usernames (main line or app line) signed in right now, or None if unknown."""
    try:
        st = M._asterisk_status()
    except Exception:
        return None
    out = set()
    for ct in st.get("contacts", []):
        if ct.get("status") in ONLINE_STATES:
            aor = ct.get("aor") or ""
            out.add(aor[:-4] if aor.endswith("-app") else aor)
    return out


def sample():
    """Stamp last_seen_at for every login that is signed in now."""
    on = _online_sip_users()
    if not on:
        return
    with M.db() as c:
        for su in on:
            c.execute("UPDATE logins SET last_seen_at=datetime('now') WHERE sip_username=? AND sip_username!=''",
                      (su,))
        c.commit()


def _loop():
    time.sleep(20)
    while True:
        try:
            sample()
        except Exception:
            pass
        time.sleep(SAMPLE_SECS)


def _on_call():
    """(login ids, extensions) talking on a call right now; empty if unknown."""
    ids, exts = set(), set()
    try:
        calls = M._brain_calls()
    except Exception:
        return ids, exts
    for cl in calls or []:
        if cl.get("direction") == "spy" or cl.get("state") != "bridged":
            continue
        for k in ("login_id", "answered_id"):
            if cl.get(k):
                ids.add(int(cl[k]))
        for k in ("caller", "callee"):
            v = str(cl.get(k) or "")
            if v.isdigit():
                exts.add(v)
    return ids, exts


def presence_map():
    """{exten: hidden|oncall|dnd|online|recent|offline} for every enabled login with an extension."""
    on = _online_sip_users() or set()
    call_ids, call_exts = _on_call()
    out = {}
    with M.db() as c:
        for r in c.execute("SELECT l.id, l.exten, l.sip_username, l.show_status,"
                           " CASE WHEN COALESCE(p.dnd,0)=1 AND (p.dnd_until IS NULL OR p.dnd_until > CAST(strftime('%s','now') AS INTEGER)) THEN 1 ELSE 0 END AS dnd,"
                           " CAST(strftime('%s','now') - strftime('%s', l.last_seen_at) AS INTEGER) AS ago"
                           " FROM logins l LEFT JOIN user_prefs p ON p.login_id=l.id"
                           " WHERE l.enabled=1 AND l.exten!=''"):
            if not r["show_status"]:
                out[r["exten"]] = "hidden"
            elif r["id"] in call_ids or r["exten"] in call_exts:
                out[r["exten"]] = "oncall"
            elif r["dnd"]:
                out[r["exten"]] = "dnd"
            elif r["sip_username"] and r["sip_username"] in on:
                out[r["exten"]] = "online"
            elif r["ago"] is not None and r["ago"] <= RECENT_SECS:
                out[r["exten"]] = "recent"
            else:
                out[r["exten"]] = "offline"
    return out


# ------------------------------------------------------------------ photos

def _safe(name):
    if not name:
        return ""
    p = os.path.realpath(os.path.join(PHOTO_DIR, os.path.basename(name)))
    return p if p.startswith(os.path.realpath(PHOTO_DIR) + os.sep) and os.path.isfile(p) else ""


def _unlink(name):
    p = _safe(name)
    if p:
        try:
            os.remove(p)
        except OSError:
            pass


def process(data):
    """Upload bytes -> square JPEG bytes. Raises ValueError."""
    if not data:
        raise ValueError("Choose a picture first.")
    if len(data) > MAX_UPLOAD:
        raise ValueError("That picture is too large (10 MB max).")
    try:
        from PIL import Image, ImageOps
        im = Image.open(io.BytesIO(data))
        im = ImageOps.exif_transpose(im)
        im = im.convert("RGB")
        w, h = im.size
        s = min(w, h)
        im = im.crop(((w - s) // 2, (h - s) // 2, (w - s) // 2 + s, (h - s) // 2 + s))
        im = im.resize((PHOTO_PX, PHOTO_PX), Image.LANCZOS)
        out = io.BytesIO()
        im.save(out, "JPEG", quality=84, optimize=True)
        return out.getvalue()
    except ValueError:
        raise
    except Exception:
        raise ValueError("That file isn't a picture we can use (try a JPG or PNG).")


def _store(prefix, data):
    os.makedirs(PHOTO_DIR, exist_ok=True)
    name = f"{prefix}-{secrets.token_hex(8)}.jpg"
    with open(os.path.join(PHOTO_DIR, name), "wb") as f:
        f.write(process(data))
    return name


def _ver(name):
    return name.rsplit("-", 1)[-1].split(".")[0] if name else ""


def set_profile_photo(login_id, data):
    name = _store(f"u{int(login_id)}", data)
    with M.db() as c:
        old = c.execute("SELECT photo FROM logins WHERE id=?", (login_id,)).fetchone()
        c.execute("UPDATE logins SET photo=? WHERE id=?", (name, login_id))
        c.commit()
    if old and old[0]:
        _unlink(old[0])


def clear_profile_photo(login_id):
    with M.db() as c:
        old = c.execute("SELECT photo FROM logins WHERE id=?", (login_id,)).fetchone()
        c.execute("UPDATE logins SET photo='' WHERE id=?", (login_id,))
        c.commit()
    if old and old[0]:
        _unlink(old[0])


def set_contact_photo(login_id, cid, data):
    with M.db() as c:
        r = c.execute("SELECT photo FROM contacts WHERE id=? AND login_id=?", (cid, login_id)).fetchone()
    if not r:
        raise ValueError("No such contact.")
    name = _store(f"c{int(cid)}", data)
    with M.db() as c:
        c.execute("UPDATE contacts SET photo=? WHERE id=? AND login_id=?", (name, cid, login_id))
        c.commit()
    if r[0]:
        _unlink(r[0])


def clear_contact_photo(login_id, cid):
    with M.db() as c:
        r = c.execute("SELECT photo FROM contacts WHERE id=? AND login_id=?", (cid, login_id)).fetchone()
        if r:
            c.execute("UPDATE contacts SET photo='' WHERE id=? AND login_id=?", (cid, login_id))
            c.commit()
    if r and r[0]:
        _unlink(r[0])


def wipe_login_photos(c, login_id):
    """Account deletion: the login's own photo and its contacts' photos."""
    r = c.execute("SELECT photo FROM logins WHERE id=?", (login_id,)).fetchone()
    if r and r[0]:
        _unlink(r[0])
    for r in c.execute("SELECT photo FROM contacts WHERE login_id=? AND photo!=''", (login_id,)):
        _unlink(r[0])


def photo_files(c, login_id):
    """[(zip name, file path)] for the privacy data download."""
    out = []
    r = c.execute("SELECT photo FROM logins WHERE id=?", (login_id,)).fetchone()
    if r and _safe(r[0]):
        out.append(("photos/my-profile-photo.jpg", _safe(r[0])))
    for r in c.execute("SELECT id, name, photo FROM contacts WHERE login_id=? AND photo!=''", (login_id,)):
        if _safe(r["photo"]):
            out.append((f"photos/contact-{r['id']}.jpg", _safe(r["photo"])))
    return out


# ------------------------------------------------------------------ for contacts lists

def decorate(me, rows):
    """Add presence + photo fields to contact dicts (both the app and the web)."""
    pmap = presence_map()
    with M.db() as c:
        users = {r["exten"]: dict(r) for r in c.execute(
            "SELECT id, exten, photo FROM logins WHERE enabled=1 AND exten!=''")}
    for r in rows:
        ext = M.contacts.norm_number(r.get("number") or "")
        u = users.get(ext) if ext and ext != (me.get("exten") or "") else None
        r["is_extension"] = bool(u)
        r["presence"] = pmap.get(ext, "") if u else ""
        theirs = (u or {}).get("photo") or ""
        mine = r.get("photo") or ""
        pref = r.get("photo_pref") or "auto"
        r["has_their_photo"] = bool(theirs)
        r["has_my_photo"] = bool(mine)
        if pref == "mine" and mine or (pref == "auto" and mine and not theirs) or (not u and mine):
            r["photo_url"], r["photo_source"] = f"/api/v1/me/contacts/{r['id']}/photo?v={_ver(mine)}", "mine"
        elif theirs and pref in ("auto", "theirs"):
            r["photo_url"], r["photo_source"] = f"/api/v1/me/photos/user/{u['id']}?v={_ver(theirs)}", "theirs"
        elif mine:
            r["photo_url"], r["photo_source"] = f"/api/v1/me/contacts/{r['id']}/photo?v={_ver(mine)}", "mine"
        else:
            r["photo_url"], r["photo_source"] = "", ""
        r.pop("photo", None)
    return rows


def my_info(me):
    with M.db() as c:
        r = c.execute("SELECT photo, show_status FROM logins WHERE id=?", (me["id"],)).fetchone()
    photo = (r["photo"] if r else "") or ""
    return {"photo_url": f"/api/v1/me/photos/user/{me['id']}?v={_ver(photo)}" if photo else "",
            "show_status": bool(r["show_status"]) if r else True}


# ------------------------------------------------------------------ web pieces

GREEN, RED = "#22c55e", "#ef4444"
# presence: (ring color, ring style, label). Styles: solid | dotted | slash (no-entry sign).
RING = {"online": (GREEN, "solid", "Online"),
        "oncall": (GREEN, "dotted", "Online / On a call"),
        "dnd": (RED, "slash", "Do Not Disturb"),
        "recent": (RED, "dotted", "Recently online (last 30 minutes)"),
        "offline": (RED, "solid", "Offline"),
        "hidden": ("", "", "Status hidden"), "": ("", "", "")}


def presence_label(p):
    return RING.get(p or "", ("", "", ""))[2]


def avatar_html(row, size=40):
    """Round picture (photo or initials) inside its presence ring: solid green
    online, dotted green + phone on a call, red with a slash for Do Not
    Disturb, dotted red recently online, solid red offline, none hidden."""
    color, style, label = RING.get(row.get("presence") or "", ("", "", ""))
    initials = "".join(w[0] for w in (row.get("name") or "?").split()[:2]).upper() or "?"
    inner = (f'<img src="{M.esc(row["photo_url"])}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%">'
             if row.get("photo_url") else
             f'<span style="font-weight:700;font-size:{int(size * .4)}px">{M.esc(initials)}</span>')
    bw = 3 if size < 60 else 4
    border = f"border:{bw}px {'dotted' if style == 'dotted' else 'solid'} {color};" if color else f"border:{bw}px solid transparent;"
    extra = ""
    if style == "slash":     # the "no entry" slash across the picture
        extra = (f'<span style="position:absolute;left:50%;top:-{bw}px;bottom:-{bw}px;width:{bw}px;margin-left:-{bw // 2}px;'
                 f'background:{color};transform:rotate(-45deg);border-radius:2px"></span>')
    elif row.get("presence") == "oncall":
        extra = (f'<span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;'
                 f'border-radius:50%;background:rgba(0,0,0,.45);font-size:{int(size * .42)}px">&#128222;</span>')
    return (f'<span title="{M.esc(label)}" style="position:relative;display:inline-flex;align-items:center;'
            f'justify-content:center;width:{size}px;height:{size}px;border-radius:50%;box-sizing:content-box;'
            f'background:var(--pill-bg);vertical-align:middle;flex:none;{border}">{inner}{extra}</span>')


def _note(request):
    q = request.query_params
    if q.get("pmsg"):
        return f'<div class="flash ok">{M.esc(q["pmsg"])}</div>'
    if q.get("perr"):
        return f'<div class="flash bad">{M.esc(q["perr"])}</div>'
    return ""


def contact_photo_panel(request, s, me, c):
    """Photo controls on a contact's page (web)."""
    csrf = M._csrf_field(s)
    row = decorate(me, [dict(c)])[0]
    pref = c.get("photo_pref") or "auto"
    choose = ""
    if row["is_extension"]:
        opts = "".join(f'<option value="{k}" {"selected" if k == pref else ""}>{t}</option>' for k, t in (
            ("auto", "Automatic (their photo if they have one)"), ("theirs", "Their profile photo"),
            ("mine", "My photo for them")))
        choose = (f'<form method="post" action="/ucp/contacts/{c["id"]}/photo-pref" class="inline">{csrf}'
                  f'<label>Show<br><select name="pref">{opts}</select></label> <button class="btn ghost">Save</button></form>')
    remove = (f'<form method="post" action="/ucp/contacts/{c["id"]}/photo/delete" class="inline">{csrf}'
              f'<button class="btn ghost">Remove my photo</button></form>') if row["has_my_photo"] else ""
    status = presence_label(row["presence"])
    return f"""{_note(request)}<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin:8px 0 14px">
{avatar_html(row, 72)}
<div>{f'<div class="muted">{M.esc(status)}</div>' if status else ''}
<form method="post" action="/ucp/contacts/{c["id"]}/photo" enctype="multipart/form-data" class="inline">{csrf}
<label>Photo for this contact <input type="file" name="photo" accept="image/*" required></label>
<button class="btn ghost">Upload</button></form> {remove}
{choose}</div></div>"""


def overview_status_switch(s, me):
    """The "Show my online status" switch for the Overview's Status tile."""
    info = my_info(me)
    return (f'<form method="post" action="/ucp/status-privacy" style="margin-top:10px">{M._csrf_field(s)}'
            f'<input type="hidden" name="show_status" value="0"><input type="hidden" name="back" value="/ucp">'
            f'<label class="switch"><input type="checkbox" name="show_status" value="1" '
            f'{"checked" if info["show_status"] else ""} onchange="this.form.submit()"> '
            f'<span>Show my online status</span></label></form>')


def settings_section(request, s, me):
    """Profile photo + status privacy for My Phone -> Settings (web)."""
    csrf = M._csrf_field(s)
    info = my_info(me)
    row = {"name": me.get("display_name") or me.get("username"), "photo_url": info["photo_url"],
           "presence": "online" if info["show_status"] else "hidden"}
    legend = " ".join(
        f'<span style="display:inline-flex;align-items:center;gap:6px;margin:4px 12px 4px 0">'
        f'{avatar_html({"name": "A", "presence": p}, 22)} <span class="muted">{presence_label(p)}</span></span>'
        for p in ("online", "oncall", "dnd", "recent", "offline"))
    remove = (f'<form method="post" action="/ucp/photo/delete" class="inline">{csrf}'
              f'<button class="btn ghost">Remove photo</button></form>') if info["photo_url"] else ""
    return f"""<section class="panel" id="my-photo" style="margin-top:16px">
<h3>My photo and status</h3>{_note(request)}
<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
{avatar_html(row, 72)}
<div><form method="post" action="/ucp/photo" enctype="multipart/form-data" class="inline">{csrf}
<label>Profile photo <input type="file" name="photo" accept="image/*" required></label>
<button class="btn ghost">Upload</button></form> {remove}
<p class="muted">Other users on this PBX see it on their contact for you (unless they chose their own photo for you).</p></div></div>
<form method="post" action="/ucp/status-privacy">{csrf}
<input type="hidden" name="show_status" value="0">
<label class="switch"><input type="checkbox" name="show_status" value="1" {"checked" if info["show_status"] else ""}
 onchange="this.form.submit()"> <span>Show my online status to other users</span></label>
<p class="muted">On: people who have you in their contacts see your status around your picture. Off: they see no ring at all. Same switch as in the app (Settings → Account) and on the Overview.</p>
<div>{legend}</div>
</form></section>"""


# ------------------------------------------------------------------ HTTP (app token or web session)

def _me(request):
    s, me = M.ucp._me(request)
    if not s:
        raise HTTPException(401)
    return s, me


def _jpeg(path):
    return FileResponse(path, media_type="image/jpeg", headers={"Cache-Control": "private, max-age=86400"})


def user_photo(request: Request, uid: int):
    _me(request)
    with M.db() as c:
        r = c.execute("SELECT photo FROM logins WHERE id=? AND enabled=1", (uid,)).fetchone()
    p = _safe(r[0]) if r else ""
    if not p:
        raise HTTPException(404)
    return _jpeg(p)


def contact_photo(request: Request, cid: int):
    s, me = _me(request)
    with M.db() as c:
        r = c.execute("SELECT photo FROM contacts WHERE id=? AND login_id=?", (cid, me["id"])).fetchone()
    p = _safe(r[0]) if r else ""
    if not p:
        raise HTTPException(404)
    return _jpeg(p)


def _wants_json(request):
    return request.headers.get("authorization", "").lower().startswith("bearer ") or \
        "application/json" in (request.headers.get("accept") or "")


async def _form(request):
    s, me = _me(request)
    if not request.headers.get("authorization", "").lower().startswith("bearer "):
        await M._check_csrf(request, s)
    return s, me, await request.form()


def _reply(request, ok, msg, back):
    if _wants_json(request):
        return JSONResponse({"ok": ok, "code": "ok" if ok else "invalid", "message": msg},
                            status_code=200)
    import urllib.parse
    path, _, frag = back.partition("#")
    sep = "&" if "?" in path else "?"
    url = path + sep + ("pmsg=" if ok else "perr=") + urllib.parse.quote(msg) + ("#" + frag if frag else "")
    return RedirectResponse(url, status_code=303)


async def my_photo_save(request: Request):
    s, me, f = await _form(request)
    up = f.get("photo")
    data = await up.read(MAX_UPLOAD + 1) if hasattr(up, "read") else b""
    try:
        set_profile_photo(me["id"], data)
    except ValueError as e:
        return _reply(request, False, str(e), "/ucp/settings#my-photo")
    M.ucp._audit(me, "ucp.photo", "profile photo set")
    return _reply(request, True, "Profile photo saved.", "/ucp/settings#my-photo")


async def my_photo_delete(request: Request):
    s, me, f = await _form(request)
    clear_profile_photo(me["id"])
    M.ucp._audit(me, "ucp.photo", "profile photo removed")
    return _reply(request, True, "Profile photo removed.", "/ucp/settings#my-photo")


async def status_privacy(request: Request):
    s, me, f = await _form(request)
    on = 1 if (f.get("show_status") or "") in ("1", "true", "on") else 0
    with M.db() as c:
        c.execute("UPDATE logins SET show_status=? WHERE id=?", (on, me["id"]))
        c.commit()
    M.ucp._audit(me, "ucp.status_privacy", f"show_status={on}")
    back = f.get("back") if f.get("back") in ("/ucp", "/ucp/settings#my-photo") else "/ucp/settings#my-photo"
    return _reply(request, True, "Others can see when you're online." if on else
                  "Your online status is hidden from others.", back)


async def contact_photo_save(request: Request, cid: int):
    s, me, f = await _form(request)
    up = f.get("photo")
    data = await up.read(MAX_UPLOAD + 1) if hasattr(up, "read") else b""
    try:
        set_contact_photo(me["id"], cid, data)
    except ValueError as e:
        return _reply(request, False, str(e), f"/ucp/contacts/{cid}")
    return _reply(request, True, "Contact photo saved.", f"/ucp/contacts/{cid}")


async def contact_photo_delete(request: Request, cid: int):
    s, me, f = await _form(request)
    clear_contact_photo(me["id"], cid)
    return _reply(request, True, "Contact photo removed.", f"/ucp/contacts/{cid}")


async def contact_photo_pref(request: Request, cid: int):
    s, me, f = await _form(request)
    pref = f.get("pref") if f.get("pref") in PREFS else "auto"
    with M.db() as c:
        n = c.execute("UPDATE contacts SET photo_pref=? WHERE id=? AND login_id=?", (pref, cid, me["id"])).rowcount
        c.commit()
    if not n:
        return _reply(request, False, "No such contact.", "/ucp/contacts")
    return _reply(request, True, "Saved.", f"/ucp/contacts/{cid}")


def me_info(request: Request):
    s, me = _me(request)
    return my_info(me)


def install(app_module):
    global M
    M = app_module
    app = M.app
    for prefix in ("/api/v1/me", "/ucp"):
        app.post(prefix + "/photo")(my_photo_save)
        app.post(prefix + "/photo/delete")(my_photo_delete)
        app.post(prefix + "/status-privacy")(status_privacy)
        app.post(prefix + "/contacts/{cid}/photo")(contact_photo_save)
        app.post(prefix + "/contacts/{cid}/photo/delete")(contact_photo_delete)
        app.post(prefix + "/contacts/{cid}/photo-pref")(contact_photo_pref)
    app.get("/api/v1/me/photos/user/{uid}")(user_photo)
    app.get("/api/v1/me/contacts/{cid}/photo")(contact_photo)
    app.get("/api/v1/me/profile")(me_info)
    threading.Thread(target=_loop, daemon=True, name="presence").start()
