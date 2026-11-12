# Website call widget and call-back form (Plugin)

> **Plugin (`web-widgets`)**: Optional plugin from the official catalog. Enable it under **Integration → Plugin Catalog** (see [Plugin system & developer guide](plugin-development.md)).

**Integration → Web widgets** puts a "Call us" button on any website. A visitor either talks to the
company from the browser over WebRTC, without a phone or an app, or leaves a number and the PBX
calls them back. The website only needs one line of code; a WordPress plugin inserts the same line.

The page is admin-only, like *Phones* and *Firewall*: an external destination or the call-back
form can cost money.

## A widget is a virtual trunk

Each widget enters the PBX like a call from a trunk, with its own **number** acting as the DID.
Reports and the CDR show these calls with that number as the called number and `widget-<id>` as
the inbound trunk, so routing, time conditions, queues and reports work as for carrier calls.

The **destination** is fixed by the administrator: an extension, ring group, queue, IVR or time
condition. The visitor never chooses or types where the call goes.

**External number** (destination type): the call leaves through the chosen outbound route, e.g. to
the duty phone of a support team. The outside party sees the *caller ID shown to the visitor*
(or the widget number), never what the visitor typed. This invites toll fraud, so an outbound
route and a **daily limit are required**.

## Settings

| Setting | Meaning |
|---------|---------|
| Name, number | Shown in the list; the number is the DID of these calls (2-20 digits, unique) |
| Destination | Where calls go (see above) |
| Browser call (WebRTC) | The visitor talks from the browser |
| Call-back form | The visitor leaves a number; the PBX calls them through the outbound route and, once they answer, sends them to the destination |
| Allowed number prefixes | Call-back only: a number is called only when it starts with one of these (e.g. `05` for Turkish mobiles). Required |
| Caller ID shown to the visitor | Call-back and external destination; empty means the widget number |
| Outbound route | Call-back and external destination |
| Allowed websites | One host name per line (`www.example.com`); `*.example.com` also allows sub-domains. Requests from other sites are refused. The portal itself is always allowed (*Try it*) |
| Concurrent calls | Calls of this widget at the same time; more get a busy signal |
| Max. call length | Seconds; the call is hung up after it |
| Requests per IP per hour | Requests from one visitor address; more are refused (not logged) |
| Calls per day | Connected calls and call-backs since midnight; 0 = no limit (only without an outside line) |
| Look | Floating button or a card in the page; corner, distance from the edges, size, shape, icon and text, colours, panel width, rounding, light/dark theme, title, message, language, showing on phones, delay before showing. The live preview beside the settings shows the result as you change it (it never places calls) |
| Visitor controls | What the visitor gets during a call: mute, hold, volume slider, call timer, keypad (digits only, or with `*` and `#`), and asking for their name (off, optional, required; shown to you with "(web)" after it) |

Save, then press **Apply** (pending changes): the widget's WebRTC endpoint and dialplan are written
then. **Switch off** (the power button) refuses new calls at once, before Apply.

## What visitors can and cannot do

A visitor is a stranger with a microphone, so the widget is locked down whatever is ticked:

| Never possible | How |
|----------------|-----|
| Transfer the call (blind `#` or attended), to an extension or an outside number | The visitor's leg is dialled without the caller-transfer option (`T`) everywhere (extensions, ring groups, queues, follow-me, outbound routes) when `AIPBX_WIDGET=1` is set on the channel; any transfer lands in the empty `widget-no-transfer` context; the WebRTC endpoint refuses SIP REFER (`allow_transfer=no`) |
| Star codes, voicemail of others, other extensions or outbound routes | The endpoint's context is `widget-<id>-route`, which only goes to the chosen destination |
| See who answered, or the number a call was forwarded to (e.g. a mobile) | Connected-line, P-Asserted-Identity and Remote-Party-ID updates are not sent to the visitor (`send_connected_line=no`, `send_pai=no`, `send_rpid=no`, `trust_id_outbound=no`) |
| Pretend to be someone (caller ID spoofing) | Identity headers from the browser are ignored (`trust_id_inbound=no`); the name they type is marked "(web)" |
| Video, recording, conferencing, presence | Not offered by the endpoint (`allow_subscribe=no`, audio only) |
| Run up costs | Max. concurrent calls, max. call length, per-IP and per-day limits, call-back number prefixes |

The keypad sends 0-9 by default, which is enough for IVR menus; `*` and `#` can be allowed for
menus that need them. Staff who answer keep their own transfer key (`t`), so they can still pass
the visitor on.

## Putting it on a website

**Copy code** gives one line:

```html
<script src="https://pbx.example.com/widget.js" data-widget="w_8f3k2a9c1d7e" async></script>
```

Paste it before `</body>` on the website, and add the site to the widget's allowed websites.
With **In the page** chosen, the code also has a `<div>`: the widget appears where the `<div>` is.

The script does not slow the page: it is loaded `async`, starts only after the page has finished
loading (and the browser is idle), keeps its settings for 5 minutes per visitor, and loads the
phone library only when the visitor presses *Call*.
**Try it** (▶) opens a page on the portal with the same code.

**WordPress**: the plugin is in [`plugins/web-widgets/wordpress/aipbx-call-widget`](../plugins/web-widgets/wordpress/aipbx-call-widget).
Zip the folder, upload it under *Plugins → Add New → Upload*, activate it, and paste the embed code
under *Settings → OwnPBX Call Widget*. The button shows on every page, or only on pages with the
`[aipbx_call_widget]` shortcode.

## How a call works

1. `widget.js` asks the portal for the widget's look (`/widget-api/config`). The portal answers only
   an allowed website (Origin header, CORS).
2. On *Call now* the browser asks for the microphone, then for a call (`/widget-api/call`). The
   portal checks the website and the limits and hands out the widget's SIP account and a
   **one-time token** valid for 5 minutes.
3. The browser loads JsSIP from the PBX and calls `w<token>` over the WebSocket on port 443 (TURNS
   for the media, as the web phone does). The account is not registered.
4. The endpoint's context (`[widget-<id>]`) holds nothing but the token check:
   `bin/widget_claim.php` (linked to `/usr/local/bin`) spends the token once. Without a valid token
   the call is refused, so copying the account from the page lets nobody call, and even with a
   token the call can only reach the widget's own destination.
5. `[widget-<id>-route]` applies the concurrent-call limit and the maximum length and sends the
   call to the destination.

The call-back form posts the number to `/widget-api/callback`. After the same checks and the
prefix check, a call file dials `Local/<number>@widget-<id>-out` (the outbound route, with the
caller ID above); when the visitor answers, the call continues in `[widget-<id>-callback]` and
reaches the destination like a widget call.

Every request (call or call-back, served or refused) is in the **Recent widget requests** list
with the website, the visitor's address and browser: the abuse log. Rows older than 90 days are
removed.

## Notes

- The visitor's browser needs WebRTC and a secure page (HTTPS) for the microphone; on sites without
  it only the call-back form is shown.
- The widget's media uses the TURN server like the web phone; see
  [WebRTC, NAT and TURN](webrtc-nat.md) when calls connect but stay silent.
- Requests over the per-IP limit are refused without a log row, so a flood cannot fill the log.
- Not done yet: a message outside office hours (use a time condition as the destination for now),
  guest chat and video.
