# SPDX-License-Identifier: GPL-2.0-or-later
"""Personal themes: each user's own panel colors and look (My Phone -> Settings).

A user saves up to MAX_PRESETS presets (name, header color, accent color,
panel look, animated header) and picks one, or "Site default" (the admin's
Branding). The pick applies only to what that user sees, on every page
(My Phone, and the admin panel for admins); light/dark stays the per-browser
moon/sun switch.

The middleware calls load(request) once per request; it sets
panel_templates.USER_THEME, which get_brand() layers over the site branding.
"""
import re
import urllib.parse

from fastapi import HTTPException, Request
from fastapi.responses import RedirectResponse

M = None
MAX_PRESETS = 5
LOOKS = (("frost", "Frosted glass"), ("wet", "Wet liquid glass"))
STARTERS = [  # quick starting points (same as the admin's Branding presets)
    ("Default", "#1a1a2e", "#1a1a2e"), ("Ocean", "#0b3d66", "#0099cc"),
    ("Midnight", "#101024", "#5a5ad6"), ("Sunset", "#431407", "#ea580c"),
    ("Forest", "#0c2417", "#22a355"), ("Royal", "#2b0f4d", "#8b3fd9"),
    ("Crimson", "#3d0a12", "#e0263c"), ("Amber", "#2e2008", "#d9a416"),
    ("Lagoon", "#06364d", "#18b4d8"), ("Deep sea", "#04182b", "#2ee6c8"),
]
_HEX = re.compile(r"#[0-9a-fA-F]{6}")


def esc(x):
    return M.esc(x)


def _color(v, fallback):
    v = (v or "").strip()
    return v if _HEX.fullmatch(v) else fallback


def presets(c, login_id):
    return [dict(r) for r in c.execute(
        "SELECT slot, name, header, accent, look, animated FROM user_themes WHERE login_id=? ORDER BY slot",
        (login_id,))]


def active_slot(c, login_id):
    r = c.execute("SELECT theme_slot FROM user_prefs WHERE login_id=?", (login_id,)).fetchone()
    return int(r[0] or 0) if r else 0


def load(request: Request):
    """Set this request's personal theme (or none). Never raises."""
    try:
        M.USER_THEME.set(None)
        s = M._sess(request)
        if not s:
            return
        with M.db() as c:
            slot = active_slot(c, s["id"])
            if not slot:
                return
            r = c.execute("SELECT header, accent, look, animated FROM user_themes WHERE login_id=? AND slot=?",
                          (s["id"], slot)).fetchone()
        if r:
            M.USER_THEME.set({"header_color": r["header"], "accent_color": r["accent"],
                              "glass_style": r["look"], "header_animated": "1" if r["animated"] else "0"})
    except Exception:
        pass


def section(s, me):
    """The "My theme" panel for My Phone -> Settings."""
    csrf = M._csrf_field(s)
    with M.db() as c:
        mine = presets(c, me["id"])
        cur = active_slot(c, me["id"])

    def swatch(h, a, size=26):
        return (f'<span style="display:inline-block;width:{size}px;height:{size}px;border-radius:50%;'
                f'border:2px solid #999;vertical-align:middle;'
                f'background:linear-gradient(135deg,{esc(h)} 50%,{esc(a)} 50%)"></span>')

    rows = (f'<label class="inline-chk" style="display:flex;align-items:center;gap:10px;margin:6px 0">'
            f'<input type="radio" name="slot" value="0" {"checked" if cur == 0 else ""}> '
            f'<span>Site default <span class="muted">(the panel\'s normal look)</span></span></label>')
    for p in mine:
        look = dict(LOOKS).get(p["look"], p["look"])
        rows += (f'<div style="display:flex;align-items:center;gap:10px;margin:6px 0;flex-wrap:wrap">'
                 f'<label class="inline-chk" style="display:flex;align-items:center;gap:10px">'
                 f'<input type="radio" name="slot" value="{p["slot"]}" {"checked" if cur == p["slot"] else ""}> '
                 f'{swatch(p["header"], p["accent"])} <b>{esc(p["name"])}</b>'
                 f'<span class="muted">{esc(look)}{" · animated" if p["animated"] else ""}</span></label>'
                 f'<button class="btn ghost" name="delete" value="{p["slot"]}" '
                 f'onclick="return confirm(\'Delete this preset?\')">Delete</button></div>')
    full = len(mine) >= MAX_PRESETS
    starters = "".join(
        f'<button type="button" class="preset utp" data-h="{h}" data-a="{a}" title="{esc(n)}" '
        f'style="width:30px;height:30px;border-radius:50%;border:2px solid #999;cursor:pointer;margin:2px;'
        f'background:linear-gradient(135deg,{h} 50%,{a} 50%)"></button>' for n, h, a in STARTERS)
    looks = "".join(f'<option value="{k}">{esc(t)}</option>' for k, t in LOOKS)
    new_form = ('<p class="muted">You have 5 presets, the most you can keep. Delete one to add another.</p>' if full else f"""
<h4 style="margin-top:16px">New preset</h4>
<form method="post" action="/ucp/settings/theme">{csrf}
<input type="hidden" name="action" value="add">
<label>Start from<br>{starters}</label>
<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end">
<label>Header color<br><input type="color" name="header" value="#06364d"></label>
<label>Buttons &amp; active tabs<br><input type="color" name="accent" value="#18b4d8"></label>
<label>Look<br><select name="look">{looks}</select></label>
</div>
<label class="inline-chk"><input type="checkbox" name="animated" value="1" checked> Animated header</label><br>
<label>Name<br><input name="name" maxlength="30" placeholder="e.g. Night ocean" required></label>
<button class="btn">Save and use it</button>
</form>
<script>
document.querySelectorAll('.utp').forEach(function(b){{b.addEventListener('click',function(){{
  var f=b.closest('form');f.header.value=b.dataset.h;f.accent.value=b.dataset.a;
  if(!f.name.value)f.name.value=b.title;
}});}});
</script>""")
    return f"""<section class="panel" id="my-theme" style="margin-top:16px">
<h3>My theme</h3>
<p class="muted">Your own colors and look, only for you (on this and every device you sign in on). Light / dark stays the 🌙/☀️ switch. Up to {MAX_PRESETS} presets.</p>
<form method="post" action="/ucp/settings/theme">{csrf}
<input type="hidden" name="action" value="use">
{rows}
<button class="btn">Use selected</button>
</form>
{new_form}
</section>"""


def _back(msg):
    return RedirectResponse("/ucp/settings?theme=" + urllib.parse.quote(msg) + "#my-theme", status_code=303)


async def save(request: Request):
    s, me = M.ucp._me(request)
    if not s:
        raise HTTPException(401)
    await M._check_csrf(request, s)
    f = await request.form()
    lid = me["id"]
    with M.db() as c:
        def set_active(slot):
            c.execute("INSERT INTO user_prefs (login_id, theme_slot) VALUES (?,?)"
                      " ON CONFLICT(login_id) DO UPDATE SET theme_slot=excluded.theme_slot", (lid, slot))
        if f.get("delete"):
            slot = int(f.get("delete")) if str(f.get("delete")).isdigit() else 0
            c.execute("DELETE FROM user_themes WHERE login_id=? AND slot=?", (lid, slot))
            if active_slot(c, lid) == slot:
                set_active(0)
            c.commit()
            return _back("deleted")
        action = f.get("action")
        if action == "use":
            slot = int(f.get("slot") or 0) if str(f.get("slot") or "0").isdigit() else 0
            if slot and not c.execute("SELECT 1 FROM user_themes WHERE login_id=? AND slot=?", (lid, slot)).fetchone():
                slot = 0
            set_active(slot)
            c.commit()
            return _back("used")
        if action == "add":
            taken = {p["slot"] for p in presets(c, lid)}
            free = [n for n in range(1, MAX_PRESETS + 1) if n not in taken]
            if not free:
                return _back("full")
            name = " ".join((f.get("name") or "").split())[:30] or "My theme"
            look = f.get("look") if f.get("look") in dict(LOOKS) else "frost"
            c.execute("INSERT INTO user_themes (login_id, slot, name, header, accent, look, animated)"
                      " VALUES (?,?,?,?,?,?,?)",
                      (lid, free[0], name, _color(f.get("header"), "#1a1a2e"), _color(f.get("accent"), "#1a1a2e"),
                       look, 1 if f.get("animated") else 0))
            set_active(free[0])
            c.commit()
            return _back("saved")
    return _back("")


def effective(me):
    """The theme this user sees: their preset if they picked one, else the
    site's Branding. For the app (GET /api/v1/me/theme)."""
    b = M._brand_settings()
    out = {"header": _color(b.get("header_color"), "#1a1a2e"), "accent": _color(b.get("accent_color"), "#1a1a2e"),
           "look": b.get("glass_style") if b.get("glass_style") in dict(LOOKS) else "frost",
           "animated": b.get("header_animated") == "1", "dark_default": b.get("theme_default") != "light",
           "source": "site", "name": ""}
    with M.db() as c:
        slot = active_slot(c, me["id"])
        if slot:
            r = c.execute("SELECT name, header, accent, look, animated FROM user_themes WHERE login_id=? AND slot=?",
                          (me["id"], slot)).fetchone()
            if r:
                out.update(header=r["header"], accent=r["accent"], look=r["look"], animated=bool(r["animated"]),
                           source="mine", name=r["name"])
    return out


def api_theme(request: Request):
    s, me = M.ucp._me(request)
    if not s:
        raise HTTPException(401)
    return effective(me)


def install(app_module):
    global M
    M = app_module
    M.app.post("/ucp/settings/theme")(save)
    M.app.get("/api/v1/me/theme")(api_theme)
