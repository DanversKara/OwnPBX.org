# SPDX-License-Identifier: GPL-2.0-or-later
"""Messages as a page that looks like a phone's messages app, with the
photos, GIFs and videos next to it, for the data download (Request my data)
and "Download conversation".

    html, files = bundle(me, msgs, title)
    # html: messages.html; files: [(name in zip, path on disk)] for media/

Works offline: one HTML file, no scripts from anywhere, media by relative
path. Pictures still only on the provider's server (not copied to the PBX
yet) are linked by their web address.
"""
import html as _h
import os
import re

M = None


def esc(x):
    return _h.escape(str(x if x is not None else ""), quote=True)


def _pretty(n):
    d = re.sub(r"\D", "", n or "")
    if len(d) == 11 and d.startswith("1"):
        d = d[1:]
    if len(d) == 10:
        return f"({d[:3]}) {d[3:6]}-{d[6:]}"
    return n or ""


def _label(num):
    """'ext 8801' for an extension, '(555) 123-4567' for a phone number."""
    if re.fullmatch(r"\d{2,8}", num or ""):
        return f"ext {num}"
    return _pretty(num)


def _initials(name, num):
    words = [w for w in re.split(r"\s+", name or "") if w[:1].isalnum()]
    if words:
        return "".join(w[0] for w in words[:2]).upper()
    d = re.sub(r"\D", "", num or "")
    return d[-2:] or "?"


def _split_ts(ts):
    """Stored UTC time -> (date, time) in the PBX's time zone."""
    s = M.ucp._ago(ts) if ts else ""
    parts = s.split(" ", 1)
    return (parts[0], parts[1] if len(parts) > 1 else "")


def _nice_day(d):
    """2026-11-01 -> Sunday, November 1, 2026."""
    import datetime as _dt
    try:
        x = _dt.date.fromisoformat(d)
        return f"{x:%A, %B} {x.day}, {x.year}"
    except ValueError:
        return d


def collect(msgs):
    """{ref: name in zip} and [(name in zip, path)] for media saved on the PBX."""
    names, files = {}, []
    for m in msgs:
        for ref in M.msg_media.refs_in(m["body"]):
            lm = M.msg_media.LOCAL.match(ref)
            if not lm or ref in names:
                continue
            fn = f"{lm.group(1)}.{lm.group(2)}"
            p = os.path.join(M.msg_media.MEDIA_DIR, fn)
            if os.path.isfile(p):
                names[ref] = f"media/{fn}"
                files.append((f"media/{fn}", p))
    return names, files


CSS = """
:root{--bg:#f2f2f7;--panel:#fff;--in:#e9e9eb;--in-fg:#111;--out:#0a84ff;--out-fg:#fff;--fg:#111;--muted:#6b7280;--line:#e5e7eb}
@media (prefers-color-scheme:dark){:root{--bg:#000;--panel:#111214;--in:#26262a;--in-fg:#f2f2f2;--fg:#f2f2f2;--muted:#9ca3af;--line:#26262a}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
header.top{padding:18px 20px;border-bottom:1px solid var(--line);background:var(--panel)}
header.top h1{margin:0;font-size:20px}header.top p{margin:4px 0 0;color:var(--muted);font-size:13px}
.wrap{display:grid;grid-template-columns:280px 1fr;min-height:calc(100vh - 70px)}
nav{background:var(--panel);border-right:1px solid var(--line);overflow-y:auto}
nav a{display:flex;gap:10px;align-items:center;padding:10px 14px;color:inherit;text-decoration:none;border-bottom:1px solid var(--line)}
nav a:hover{background:rgba(127,127,127,.1)}
.av{flex:none;width:40px;height:40px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:700;font-size:15px}
.who{min-width:0}.who b{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.who span{display:block;color:var(--muted);font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
main{padding:0 0 40px}
section.conv{max-width:760px;margin:0 auto 28px;background:var(--panel);border-radius:18px;overflow:hidden;
  box-shadow:0 1px 3px rgba(0,0,0,.08);border:1px solid var(--line)}
section.conv:first-child{margin-top:20px}
.ch{display:flex;gap:12px;align-items:center;padding:14px 16px;border-bottom:1px solid var(--line);position:sticky;top:0;background:var(--panel);z-index:1}
.ch .who b{font-size:16px}
.msgs{padding:10px 14px 18px;display:flex;flex-direction:column;gap:3px}
.day{align-self:center;color:var(--muted);font-size:12px;font-weight:600;margin:14px 0 6px}
.row{display:flex;flex-direction:column;max-width:78%}
.row.in{align-self:flex-start;align-items:flex-start}.row.out{align-self:flex-end;align-items:flex-end}
.b{padding:8px 12px;border-radius:18px;white-space:pre-wrap;word-wrap:break-word;overflow-wrap:anywhere}
.in .b{background:var(--in);color:var(--in-fg);border-bottom-left-radius:5px}
.out .b{background:var(--out);color:var(--out-fg);border-bottom-right-radius:5px}
.b.media-only{background:none;padding:0}
.meta{font-size:11px;color:var(--muted);margin:2px 6px 6px}
.q{display:block;border-left:3px solid currentColor;opacity:.85;font-size:12px;padding:2px 8px;margin:0 0 6px;border-radius:4px;
  background:rgba(127,127,127,.18);text-decoration:none;color:inherit;white-space:normal}
.q b{display:block}
.mm{display:block;max-width:280px;max-height:320px;border-radius:14px;margin:4px 0;background:rgba(127,127,127,.15)}
video.mm{max-width:300px}
.file{display:inline-block;padding:6px 10px;border-radius:10px;background:rgba(127,127,127,.18);color:inherit}
.gone{font-size:12px;color:var(--muted)}
@media (max-width:760px){.wrap{grid-template-columns:1fr}nav{border-right:0;max-height:40vh}section.conv{border-radius:0;margin:0 0 16px}}
@media print{nav{display:none}.wrap{display:block}.ch{position:static}}
"""
COLORS = ("#1a73e8", "#d93025", "#188038", "#e37400", "#9334e6", "#007b83", "#c5221f", "#1967d2")


def _color(key):
    return COLORS[sum(map(ord, key or "?")) % len(COLORS)]


def render(me, msgs, title, media_names):
    """The page. msgs: rows from ucp._my_messages (any order)."""
    ex = me["exten"]
    by_id = {m["id"]: m for m in msgs}
    convs = {}
    for m in sorted(msgs, key=lambda r: (r["sent_at"] or "", r["id"])):
        peer = m["to_ext"] if m["from_ext"] == ex else m["from_ext"]
        convs.setdefault(peer, []).append(m)
    order = sorted(convs, key=lambda p: (convs[p][-1]["sent_at"] or "", convs[p][-1]["id"]), reverse=True)
    my_name = me.get("display_name") or me.get("username") or ""

    nav, body = [], []
    for i, peer in enumerate(order, 1):
        rows = convs[peer]
        name = next((r.get("peer_name") for r in rows if r.get("peer_name")), "")
        av = f'<div class="av" style="background:{_color(peer)}">{esc(_initials(name, peer))}</div>'
        last_date = _split_ts(rows[-1]["sent_at"])[0]
        nav.append(f'<a href="#c{i}">{av}<div class="who"><b>{esc(name or _label(peer))}</b>'
                   f'<span>{esc(_label(peer)) + " · " if name else ""}{len(rows)} message{"s" if len(rows) != 1 else ""} · {esc(last_date)}</span></div></a>')
        out = [f'<section class="conv" id="c{i}"><div class="ch">{av}<div class="who"><b>{esc(name or _label(peer))}</b>'
               f'<span>{(esc(_label(peer)) + " · saved contact · ") if name else ""}{len(rows)} message{"s" if len(rows) != 1 else ""}</span></div></div>'
               f'<div class="msgs">']
        day = None
        for m in rows:
            mine = m["from_ext"] == ex
            d, t = _split_ts(m["sent_at"])
            if d != day:
                out.append(f'<div class="day">{esc(_nice_day(d))}</div>')
                day = d
            text = M.msg_media.strip_marks(m["body"])
            parts = []
            rid = m.get("reply_to")
            if rid:
                o = by_id.get(rid)
                if o:
                    who = "You" if o["from_ext"] == ex else (name or _label(o["from_ext"]))
                    snip = M.msg_media.display_body(o["body"]).replace("\n", " ")
                    parts.append(f'<a class="q" href="#m{rid}"><b>↩ {esc(who)}</b>{esc(snip[:120])}{"…" if len(snip) > 120 else ""}</a>')
            if text:
                parts.append(esc(text))
            media = []
            for it in M.msg_media.items(m["body"], ""):
                src = media_names.get(it["ref"]) or (it["ref"] if it["ref"].startswith(("http://", "https://")) else "")
                if not src:
                    media.append('<span class="gone">[attachment no longer on the server]</span>')
                elif it["kind"] in ("image", "gif"):
                    media.append(f'<a href="{esc(src)}"><img class="mm" src="{esc(src)}" alt="{"GIF" if it["kind"] == "gif" else "Photo"}" loading="lazy"></a>')
                elif it["kind"] == "video":
                    media.append(f'<video class="mm" src="{esc(src)}" controls preload="metadata"></video>')
                else:
                    media.append(f'<a class="file" href="{esc(src)}">📎 {esc(it["name"])}</a>')
            cls = "b" + (" media-only" if media and not parts else "")
            sender = "You" if mine else (name or _label(peer))
            meta = f'{esc(t)} · {esc(sender)}'
            if m.get("via_did"):
                meta += f' · {"from" if mine else "to"} {esc(_pretty(m["via_did"]))}'
            out.append(f'<div class="row {"out" if mine else "in"}" id="m{m["id"]}"><div class="{cls}">'
                       f'{"".join(parts)}{"".join(media)}</div><div class="meta">{meta}</div></div>')
        out.append('</div></section>')
        body.append("".join(out))
    sub = (f'{esc(my_name)}{" · ext " + esc(ex) if ex else ""} · {len(msgs)} message{"s" if len(msgs) != 1 else ""} '
           f'in {len(order)} conversation{"s" if len(order) != 1 else ""} · times in the phone system\'s time zone')
    return (f'<!doctype html><html lang="en"><head><meta charset="utf-8">'
            f'<meta name="viewport" content="width=device-width,initial-scale=1"><title>{esc(title)}</title>'
            f'<style>{CSS}</style></head><body><header class="top"><h1>{esc(title)}</h1><p>{sub}</p></header>'
            f'<div class="wrap"><nav>{"".join(nav) or "<p style=padding:14px>No messages.</p>"}</nav>'
            f'<main>{"".join(body)}</main></div></body></html>')


def bundle(me, msgs, title):
    """(html, [(name in zip, path)])."""
    names, files = collect(msgs)
    return render(me, msgs, title, names), files


def install(app_module):
    global M
    M = app_module
