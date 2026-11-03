# SPDX-License-Identifier: GPL-2.0-or-later
"""Photos, videos and GIFs in messages (My Phone web and app).

A message keeps its text in messages.body plus one "[MMS: <ref>]" marker per
attachment, the same form voip.ms MMS already arrive in:

  [MMS: media:<token>.<ext>]   a file someone sent from this PBX (MEDIA_DIR)
  [MMS: https://...]           a picture that came in by MMS (voip.ms hosts it)

Files sent from here are checked by their content (not their name), photos
are re-saved without their hidden details (location, camera) and shrunk to
MAX_PX; GIFs are kept as they are so they stay animated; videos are kept as
they are.

Who can open a file (GET /ucp/media/<file>, /api/v1/me/media/<file>):
  - whoever sent it,
  - anyone a message carrying it went to or came from (by extension),
  - an admin, when a report mentions it.
A file texted to an outside phone number is also served without sign-in at
/mms/<file> (voip.ms fetches it from there to deliver the MMS); the name is a
long random token.
"""
import io
import os
import re
import secrets

from fastapi import HTTPException, Request
from fastapi.responses import FileResponse

M = None
MEDIA_DIR = os.environ.get("PBX_MEDIA_DIR", "/var/lib/pbx/media")
MAX_FILES = 3                      # voip.ms takes up to 3 per MMS; same everywhere
MAX_FILE = 25 * 1024 * 1024        # per file, to extensions
MAX_MMS = 1200 * 1024              # per file, to phone numbers (voip.ms / carriers)
MAX_PX = 2048

MARK = re.compile(r"\[MMS:\s*([^\]\s]+)\s*\]")
LOCAL = re.compile(r"media:([0-9a-f]{32})\.([a-z0-9]{2,4})$")
FILE = re.compile(r"([0-9a-f]{32})\.([a-z0-9]{2,4})$")
MIME = {"jpg": "image/jpeg", "png": "image/png", "webp": "image/webp", "gif": "image/gif",
        "mp4": "video/mp4", "mov": "video/quicktime", "webm": "video/webm", "3gp": "video/3gpp"}


class MediaError(Exception):
    """A file we won't send; str() is the message for the user."""


def kind_of(ext):
    ext = (ext or "").lower()
    if ext == "gif":
        return "gif"
    if ext in ("jpg", "jpeg", "png", "webp", "bmp", "heic"):
        return "image"
    if ext in ("mp4", "mov", "webm", "3gp", "m4v"):
        return "video"
    return "file"


def _sniff(data: bytes) -> str:
    """File type from the first bytes: jpg | png | gif | webp | mp4 | mov | 3gp | webm | ''."""
    if data[:3] == b"\xff\xd8\xff":
        return "jpg"
    if data[:8] == b"\x89PNG\r\n\x1a\n":
        return "png"
    if data[:6] in (b"GIF87a", b"GIF89a"):
        return "gif"
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "webp"
    if data[:4] == b"\x1a\x45\xdf\xa3":
        return "webm"
    if data[4:8] == b"ftyp":
        brand = data[8:12]
        if brand.startswith(b"3g"):
            return "3gp"
        if brand == b"qt  ":
            return "mov"
        if brand[:4] in (b"heic", b"heix", b"mif1", b"msf1", b"hevc", b"avif"):
            return "heic"
        return "mp4"
    return ""


def _clean_image(data: bytes, ext: str, limit: int) -> tuple[bytes, str]:
    """Re-save a photo without its hidden details, turned the right way up,
    no bigger than MAX_PX, and under `limit` bytes. GIFs are left alone."""
    from PIL import Image, ImageOps
    try:
        im = Image.open(io.BytesIO(data))
        im = ImageOps.exif_transpose(im)
    except Exception:
        raise MediaError("That picture couldn't be opened.")
    keep_png = ext == "png" and len(data) <= limit and max(im.size) <= MAX_PX
    px = MAX_PX
    while True:
        cur = im.copy()
        cur.thumbnail((px, px))
        out = io.BytesIO()
        if keep_png:
            cur.save(out, "PNG", optimize=True)
            new_ext = "png"
        else:
            if cur.mode not in ("RGB", "L"):
                bg = Image.new("RGB", cur.size, (255, 255, 255))
                bg.paste(cur, mask=cur.convert("RGBA").split()[-1])
                cur = bg
            cur.save(out, "JPEG", quality=85 if px >= 1600 else 78, optimize=True)
            new_ext = "jpg"
        b = out.getvalue()
        if len(b) <= limit or px <= 480:
            if len(b) > limit:
                raise MediaError("That picture is too big to send.")
            return b, new_ext
        keep_png = False
        px = int(px * 0.75)


def prepare(data: bytes, external: bool) -> tuple[bytes, str]:
    """Checked, cleaned bytes and extension for one upload, or MediaError."""
    if not data:
        raise MediaError("That file is empty.")
    ext = _sniff(data)
    limit = MAX_MMS if external else MAX_FILE
    if ext == "heic":
        raise MediaError("HEIC photos aren't supported yet. Choose 'Most compatible' (JPEG) in your camera settings.")
    if not ext:
        raise MediaError("Only photos (JPEG, PNG, WebP), GIFs and videos (MP4, MOV, WebM, 3GP) can be sent.")
    if len(data) > MAX_FILE:
        raise MediaError(f"Files can be up to {MAX_FILE // (1024 * 1024)} MB.")
    if ext in ("jpg", "png", "webp"):
        return _clean_image(data, ext, limit)
    if len(data) > limit:
        what = "GIF" if ext == "gif" else "video"
        raise MediaError(f"That {what} is too big to text to a phone number (about 1.2 MB at most). "
                         "Send a smaller one, or send it to an extension.")
    return data, ext


def store(c, me, data: bytes, ext: str, name: str, public: bool) -> str:
    """Save the file; returns its message ref ("media:<token>.<ext>")."""
    os.makedirs(MEDIA_DIR, exist_ok=True)
    tok = secrets.token_hex(16)
    fn = f"{tok}.{ext}"
    with open(os.path.join(MEDIA_DIR, fn), "wb") as fh:
        fh.write(data)
    c.execute("INSERT INTO message_media (token, login_id, ext, size, name, public) VALUES (?,?,?,?,?,?)",
              (tok, me["id"], ext, len(data), (name or "")[:120], 1 if public else 0))
    return f"media:{fn}"


def strip_marks(text: str) -> str:
    return MARK.sub("", text or "").strip()


def refs_in(body: str) -> list[str]:
    return MARK.findall(body or "")


def label(refs) -> str:
    """Short stand-in for a message that's only attachments ("📷 Photo")."""
    kinds = [kind_of(LOCAL.match(r).group(2) if LOCAL.match(r) else r.rsplit(".", 1)[-1].split("?")[0])
             for r in refs]
    if not kinds:
        return ""
    k = kinds[0]
    word = {"gif": "🎞️ GIF", "video": "🎬 Video", "image": "📷 Photo"}.get(k, "📎 Attachment")
    return word + (f" +{len(kinds) - 1}" if len(kinds) > 1 else "")


def items(body: str, prefix: str) -> list[dict]:
    """Attachments of a message for display: url (under `prefix` for ours),
    ref (to forward it), kind, mime, name."""
    out = []
    for r in refs_in(body):
        m = LOCAL.match(r)
        if m:
            fn = f"{m.group(1)}.{m.group(2)}"
            out.append({"url": f"{prefix}/media/{fn}", "ref": r, "kind": kind_of(m.group(2)),
                        "mime": MIME.get(m.group(2), "application/octet-stream"), "name": fn})
        elif r.startswith(("https://", "http://")):
            tail = r.split("?")[0].rsplit("/", 1)[-1]
            ext = tail.rsplit(".", 1)[-1].lower() if "." in tail else ""
            out.append({"url": r, "ref": r, "kind": kind_of(ext), "mime": MIME.get(ext, ""), "name": tail or "media"})
    return out


def display_body(body: str) -> str:
    """Text of a message, or a stand-in like "📷 Photo" when it's only media."""
    t = strip_marks(body)
    return t or label(refs_in(body)) or (body or "")


def _can_open(me, s, tok: str) -> bool:
    with M.db() as c:
        r = c.execute("SELECT login_id FROM message_media WHERE token=?", (tok,)).fetchone()
        if not r:
            return False
        if r["login_id"] == me["id"]:
            return True
        like = f"%media:{tok}.%"
        if me["exten"] and c.execute("SELECT 1 FROM messages WHERE body LIKE ? AND (from_ext=? OR to_ext=?) LIMIT 1",
                                     (like, me["exten"], me["exten"])).fetchone():
            return True
        if s and s.get("role") == "admin" and c.execute(
                "SELECT 1 FROM reports WHERE detail LIKE ? LIMIT 1", (like,)).fetchone():
            return True
    return False


def can_forward(me, ref: str) -> bool:
    """A file or MMS link this user may send on: theirs, or in a message they can see."""
    m = LOCAL.match(ref or "")
    if m:
        return _can_open(me, None, m.group(1))
    if (ref or "").startswith(("https://", "http://")) and me["exten"]:
        with M.db() as c:
            return bool(c.execute("SELECT 1 FROM messages WHERE body LIKE ? AND (from_ext=? OR to_ext=?) LIMIT 1",
                                  (f"%[MMS: {ref}]%", me["exten"], me["exten"])).fetchone())
    return False


def _file(fn: str, dl: bool):
    m = FILE.fullmatch(fn or "")
    if not m:
        raise HTTPException(404)
    p = os.path.join(MEDIA_DIR, fn)
    if not os.path.isfile(p):
        raise HTTPException(404)
    headers = {"Cache-Control": "private, max-age=86400"}
    if dl:
        headers["Content-Disposition"] = f'attachment; filename="ownpbx-{m.group(1)[:8]}.{m.group(2)}"'
    return FileResponse(p, media_type=MIME.get(m.group(2), "application/octet-stream"), headers=headers)


def serve(request: Request, fn: str):
    s, me = M.ucp._me(request)
    if not s:
        raise HTTPException(401)
    m = FILE.fullmatch(fn or "")
    if not m or not _can_open(me, s, m.group(1)):
        raise HTTPException(404)
    return _file(fn, request.query_params.get("dl") == "1")


def serve_public(fn: str):
    """Files texted to an outside number, for voip.ms to fetch."""
    m = FILE.fullmatch(fn or "")
    if not m:
        raise HTTPException(404)
    with M.db() as c:
        r = c.execute("SELECT public FROM message_media WHERE token=?", (m.group(1),)).fetchone()
    if not r or not r["public"]:
        raise HTTPException(404)
    return _file(fn, False)


def wipe_login(c, login_id):
    """Account deletion: the files this user sent."""
    for r in c.execute("SELECT token, ext FROM message_media WHERE login_id=?", (login_id,)).fetchall():
        try:
            os.remove(os.path.join(MEDIA_DIR, f"{r['token']}.{r['ext']}"))
        except OSError:
            pass
    c.execute("DELETE FROM message_media WHERE login_id=?", (login_id,))


def files_for_export(c, login_id):
    """(name in zip, path) for the files this user sent."""
    out = []
    for r in c.execute("SELECT token, ext FROM message_media WHERE login_id=?", (login_id,)).fetchall():
        p = os.path.join(MEDIA_DIR, f"{r['token']}.{r['ext']}")
        if os.path.isfile(p):
            out.append((f"message-media/{r['token']}.{r['ext']}", p))
    return out


def install(app_module):
    global M
    M = app_module
    app = M.app
    app.get("/ucp/media/{fn}")(serve)
    app.get("/api/v1/me/media/{fn}")(serve)
    app.get("/mms/{fn}")(serve_public)
