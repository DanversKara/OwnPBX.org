/*
 * OwnPBX website call widget.
 *
 * Embed with one line (Integrations -> Web widgets shows it):
 *   <script src="https://pbx.example.com/widget.js" data-widget="w_xxxxxxxxxxxx" async></script>
 * In-page instead of a floating button: add data-target="#some-element" (or leave it out and the
 * widget is drawn where the script tag is, when the widget's layout is "In the page").
 *
 * Draws a button (or an in-page card). "Call" talks to the company over WebRTC in the browser
 * (JsSIP, loaded from the same PBX only when a call starts); "Call me back" leaves a number and the
 * PBX calls the visitor. The PBX checks the website, the limits and where the call goes; this
 * script only asks. Which in-call buttons the visitor gets (mute, keypad, hold, volume) is set per
 * widget on the PBX; transferring a call is never offered to a website visitor.
 *
 * It does not slow the page: nothing runs until the page has loaded and the browser is idle, the
 * settings are kept for 5 minutes in the tab (sessionStorage), and the 900 KB phone library is
 * only fetched when the visitor presses Call.
 */
(function () {
    'use strict';

    var script = document.currentScript || document.querySelector('script[data-widget],script[data-preview]');
    if (!script) {
        return;
    }
    var PREVIEW = script.hasAttribute('data-preview');
    var WIDGET_ID = script.getAttribute('data-widget') || '';
    // The same widget twice on a page (a site that adds the code again) shows once. A page that
    // re-inserts the code after removing the old one (dashboards, start pages, SPAs) gets it back.
    if (!PREVIEW && document.querySelector('[data-ownpbx-widget="' + WIDGET_ID.replace(/[^\w-]/g, '') + '"]')) {
        return;
    }

    var BASE = new URL(script.src, document.baseURI).origin;
    var TARGET = script.getAttribute('data-target') || '';
    var CACHE_KEY = 'ownpbx-widget-' + WIDGET_ID;
    var CACHE_MS = 5 * 60 * 1000;

    var TEXTS = {
        en: {
            button: 'Call us', title: 'Talk to us', call: 'Call now', callback: 'Call me back',
            name: 'Your name (optional)', number: 'Your phone number', send: 'Call me',
            call_hint: 'Free call from your browser. Your microphone will be used.',
            callback_hint: 'Leave your number and we call you right away.',
            connecting: 'Connecting…', ringing: 'Ringing…', in_call: 'In call', ended: 'Call ended',
            hangup: 'Hang up', mute: 'Mute', unmute: 'Unmute', keypad: 'Keypad', close: 'Close',
            calling_you: 'We are calling you now.',
            err_mic: 'Microphone access is needed for the call.',
            err_mic_policy: 'This website does not allow the microphone (its Permissions-Policy header says microphone=()). The site owner has to allow it: microphone=(self).', err_mic_denied: 'The browser blocked the microphone. Allow it for this site (lock icon next to the address) and try again.', err_mic_none: 'No microphone was found on this device.', err_mic_busy: 'The microphone is in use by another program.', err_mic_insecure: 'Calls need a secure (https) page.',
            err_busy: 'All lines are busy. Please try again later.',
            err_failed: 'The call could not be connected.',
            err_rate_ip: 'Too many attempts. Please try again later.',
            err_limit_daily: 'No more calls can be taken today.',
            err_bad_number: 'This number cannot be called back.',
            err_disabled: 'Calls are not available right now.',
            err_default: 'Something went wrong. Please try again later.'
        },
        tr: {
            button: 'Bizi arayın', title: 'Bizimle konuşun', call: 'Hemen ara', callback: 'Beni arayın',
            name: 'Adınız (isteğe bağlı)', number: 'Telefon numaranız', send: 'Beni ara',
            call_hint: 'Tarayıcınızdan ücretsiz arama. Mikrofonunuz kullanılacak.',
            callback_hint: 'Numaranızı bırakın, sizi hemen arayalım.',
            connecting: 'Bağlanıyor…', ringing: 'Çalıyor…', in_call: 'Görüşmede', ended: 'Görüşme bitti',
            hangup: 'Kapat', mute: 'Sessiz', unmute: 'Sesi aç', keypad: 'Tuşlar', close: 'Kapat',
            calling_you: 'Sizi şimdi arıyoruz.',
            err_mic: 'Arama için mikrofon izni gerekiyor.',
            err_mic_policy: 'Bu web sitesi mikrofona izin vermiyor (Permissions-Policy başlığında microphone=() var). Site sahibinin izin vermesi gerekir: microphone=(self).', err_mic_denied: 'Tarayıcı mikrofonu engelledi. Bu site için izin verin (adres çubuğundaki kilit simgesi) ve tekrar deneyin.', err_mic_none: 'Bu cihazda mikrofon bulunamadı.', err_mic_busy: 'Mikrofon başka bir program tarafından kullanılıyor.', err_mic_insecure: 'Arama için güvenli (https) bir sayfa gerekir.',
            err_busy: 'Tüm hatlar meşgul. Lütfen daha sonra tekrar deneyin.',
            err_failed: 'Arama bağlanamadı.',
            err_rate_ip: 'Çok fazla deneme. Lütfen daha sonra tekrar deneyin.',
            err_limit_daily: 'Bugün daha fazla arama alınamıyor.',
            err_bad_number: 'Bu numara geri aranamıyor.',
            err_disabled: 'Arama şu an kullanılamıyor.',
            err_default: 'Bir sorun oluştu. Lütfen daha sonra tekrar deneyin.'
        },
        de: {
            button: 'Rufen Sie uns an', title: 'Sprechen Sie mit uns', call: 'Jetzt anrufen', callback: 'Rückruf',
            name: 'Ihr Name (optional)', number: 'Ihre Telefonnummer', send: 'Bitte zurückrufen',
            call_hint: 'Kostenloser Anruf aus dem Browser. Ihr Mikrofon wird verwendet.',
            callback_hint: 'Hinterlassen Sie Ihre Nummer, wir rufen sofort zurück.',
            connecting: 'Verbinde…', ringing: 'Es klingelt…', in_call: 'Im Gespräch', ended: 'Gespräch beendet',
            hangup: 'Auflegen', mute: 'Stumm', unmute: 'Ton an', keypad: 'Tastatur', close: 'Schließen',
            calling_you: 'Wir rufen Sie jetzt an.',
            err_mic: 'Für den Anruf wird das Mikrofon benötigt.',
            err_mic_policy: 'Diese Website erlaubt das Mikrofon nicht (ihr Permissions-Policy-Header enthält microphone=()). Der Betreiber muss es erlauben: microphone=(self).', err_mic_denied: 'Der Browser hat das Mikrofon blockiert. Erlauben Sie es für diese Seite (Schloss-Symbol neben der Adresse) und versuchen Sie es erneut.', err_mic_none: 'Auf diesem Gerät wurde kein Mikrofon gefunden.', err_mic_busy: 'Das Mikrofon wird von einem anderen Programm verwendet.', err_mic_insecure: 'Anrufe brauchen eine sichere (https) Seite.',
            err_busy: 'Alle Leitungen sind besetzt. Bitte später erneut versuchen.',
            err_failed: 'Der Anruf konnte nicht verbunden werden.',
            err_rate_ip: 'Zu viele Versuche. Bitte später erneut versuchen.',
            err_limit_daily: 'Heute sind keine Anrufe mehr möglich.',
            err_bad_number: 'Diese Nummer kann nicht zurückgerufen werden.',
            err_disabled: 'Anrufe sind gerade nicht möglich.',
            err_default: 'Etwas ist schiefgelaufen. Bitte später erneut versuchen.'
        },
        fr: {
            button: 'Appelez-nous', title: 'Parlez-nous', call: 'Appeler', callback: 'Être rappelé',
            name: 'Votre nom (facultatif)', number: 'Votre numéro', send: 'Me rappeler',
            call_hint: 'Appel gratuit depuis votre navigateur. Votre micro sera utilisé.',
            callback_hint: 'Laissez votre numéro, nous vous rappelons tout de suite.',
            connecting: 'Connexion…', ringing: 'Ça sonne…', in_call: 'En communication', ended: 'Appel terminé',
            hangup: 'Raccrocher', mute: 'Muet', unmute: 'Son', keypad: 'Clavier', close: 'Fermer',
            calling_you: 'Nous vous appelons maintenant.',
            err_mic: "L'accès au micro est nécessaire pour l'appel.",
            err_mic_policy: 'Ce site n\'autorise pas le micro (son en-tête Permissions-Policy contient microphone=()). Le propriétaire du site doit l\'autoriser : microphone=(self).', err_mic_denied: 'Le navigateur a bloqué le micro. Autorisez-le pour ce site (cadenas à côté de l\'adresse) et réessayez.', err_mic_none: 'Aucun micro n\'a été trouvé sur cet appareil.', err_mic_busy: 'Le micro est utilisé par un autre programme.', err_mic_insecure: 'Les appels nécessitent une page sécurisée (https).',
            err_busy: 'Toutes les lignes sont occupées. Réessayez plus tard.',
            err_failed: "L'appel n'a pas pu être établi.",
            err_rate_ip: 'Trop de tentatives. Réessayez plus tard.',
            err_limit_daily: "Plus aucun appel possible aujourd'hui.",
            err_bad_number: 'Ce numéro ne peut pas être rappelé.',
            err_disabled: 'Les appels ne sont pas disponibles pour le moment.',
            err_default: 'Une erreur est survenue. Réessayez plus tard.'
        },
        es: {
            button: 'Llámenos', title: 'Hable con nosotros', call: 'Llamar ahora', callback: 'Que me llamen',
            name: 'Su nombre (opcional)', number: 'Su número de teléfono', send: 'Llámenme',
            call_hint: 'Llamada gratuita desde el navegador. Se usará su micrófono.',
            callback_hint: 'Deje su número y le llamamos enseguida.',
            connecting: 'Conectando…', ringing: 'Sonando…', in_call: 'En llamada', ended: 'Llamada finalizada',
            hangup: 'Colgar', mute: 'Silenciar', unmute: 'Activar sonido', keypad: 'Teclado', close: 'Cerrar',
            calling_you: 'Le estamos llamando ahora.',
            err_mic: 'Se necesita acceso al micrófono para la llamada.',
            err_mic_policy: 'Este sitio web no permite el micrófono (su cabecera Permissions-Policy contiene microphone=()). El propietario del sitio debe permitirlo: microphone=(self).', err_mic_denied: 'El navegador bloqueó el micrófono. Permítalo para este sitio (candado junto a la dirección) e inténtelo de nuevo.', err_mic_none: 'No se encontró ningún micrófono en este dispositivo.', err_mic_busy: 'El micrófono está siendo usado por otro programa.', err_mic_insecure: 'Las llamadas necesitan una página segura (https).',
            err_busy: 'Todas las líneas están ocupadas. Inténtelo más tarde.',
            err_failed: 'No se pudo conectar la llamada.',
            err_rate_ip: 'Demasiados intentos. Inténtelo más tarde.',
            err_limit_daily: 'Hoy no se pueden atender más llamadas.',
            err_bad_number: 'No se puede devolver la llamada a este número.',
            err_disabled: 'Las llamadas no están disponibles ahora.',
            err_default: 'Algo salió mal. Inténtelo más tarde.'
        }
    };

    // Texts added with the layout builder; other languages fall back to these.
    var EXTRA = {
        en: { hold: 'Hold', resume: 'Resume', on_hold: 'On hold', volume: 'Volume', name_required: 'Your name',
            err_name: 'Please enter your name.', preview: 'Preview: calls are not placed here. Use "Try it" for a real test call.' },
        tr: { hold: 'Beklet', resume: 'Devam', on_hold: 'Beklemede', volume: 'Ses', name_required: 'Adınız', err_name: 'Lütfen adınızı yazın.' },
        de: { hold: 'Halten', resume: 'Fortsetzen', on_hold: 'Gehalten', volume: 'Lautstärke', name_required: 'Ihr Name', err_name: 'Bitte geben Sie Ihren Namen ein.' },
        fr: { hold: 'Attente', resume: 'Reprendre', on_hold: 'En attente', volume: 'Volume', name_required: 'Votre nom', err_name: 'Veuillez saisir votre nom.' },
        es: { hold: 'Espera', resume: 'Reanudar', on_hold: 'En espera', volume: 'Volumen', name_required: 'Su nombre', err_name: 'Escriba su nombre, por favor.' }
    };

    // Defaults match WebWidgetService::LAYOUT_DEFAULTS / CONTROLS_DEFAULTS.
    var LAYOUT_DEFAULTS = {
        mode: 'floating', vertical: 'bottom', offset_x: 20, offset_y: 20, size: 'medium', shape: 'pill',
        show_icon: true, show_text: true, text_color: '#ffffff', panel_width: 320, radius: 16,
        theme: 'light', title: '', greeting: '', show_on_mobile: true, delay: 0
    };
    var CONTROLS_DEFAULTS = { mute: true, keypad: true, keypad_keys: 'digits', hold: false, volume: false, timer: true, name: 'off' };

    var cfg = null;
    var L = LAYOUT_DEFAULTS;
    var C = CONTROLS_DEFAULTS;
    var T = TEXTS.en;
    var ui = {};
    var host = null;
    var ua = null;
    var session = null;
    var stream = null;
    var timer = null;
    var muted = false;
    var held = false;

    function merge(a, b) {
        var o = {};
        Object.keys(a).forEach(function (k) { o[k] = a[k]; });
        Object.keys(b || {}).forEach(function (k) { if (b[k] !== undefined && b[k] !== null) { o[k] = b[k]; } });
        return o;
    }

    function api(path, params, method) {
        var url = BASE + '/widget-api/' + path;
        var body = new URLSearchParams(params);
        var opts = { method: method || 'POST', mode: 'cors', credentials: 'omit' };
        if (opts.method === 'GET') {
            url += '?' + body.toString();
        } else {
            opts.body = body;
        }
        return fetch(url, opts).then(function (r) {
            return r.json().catch(function () { return { success: false, error: 'default' }; });
        });
    }

    function errorText(code) {
        return T['err_' + code] || T.err_default;
    }

    function el(tag, attrs, children) {
        var n = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') { n.textContent = attrs[k]; } else if (k === 'onclick') { n.addEventListener('click', attrs[k]); } else { n.setAttribute(k, attrs[k]); }
        });
        (children || []).forEach(function (c) { if (c) { n.appendChild(c); } });
        return n;
    }

    function hex(c, fallback) {
        return /^#[0-9a-f]{6}$/i.test(c || '') ? c : fallback;
    }

    function css() {
        var color = hex(cfg.color, '#2563eb');
        var fg = hex(L.text_color, '#ffffff');
        var side = cfg.position === 'left' ? 'left' : 'right';
        var vert = L.vertical === 'top' ? 'top' : 'bottom';
        var ox = Math.max(0, Math.min(400, +L.offset_x || 0)) + 'px';
        var oy = Math.max(0, Math.min(400, +L.offset_y || 0)) + 'px';
        var sz = { small: ['9px 14px', '13px', '15px', '40px'], medium: ['14px 20px', '15px', '18px', '52px'], large: ['18px 26px', '17px', '22px', '64px'] }[L.size] || ['14px 20px', '15px', '18px', '52px'];
        var round = L.shape === 'round';
        var radius = L.shape === 'square' ? '10px' : '999px';
        var pr = Math.max(0, Math.min(32, +L.radius || 0)) + 'px';
        var width = Math.max(240, Math.min(560, +L.panel_width || 320)) + 'px';
        var dark = L.theme === 'dark';
        var bg = dark ? '#111827' : '#fff', text = dark ? '#f3f4f6' : '#111827', muted = dark ? '#9ca3af' : '#6b7280';
        var field = dark ? '#1f2937' : '#fff', border = dark ? '#374151' : '#d1d5db', soft = dark ? '#1f2937' : '#f3f4f6';
        var inline = L.mode === 'inline';
        var gap = (parseInt(sz[3], 10) + 12 + (+L.offset_y || 0)) + 'px';
        var auto = L.theme === 'auto' ? '@media (prefers-color-scheme:dark){.panel{background:#111827;color:#f3f4f6}.hint{color:#9ca3af}input{background:#1f2937;color:#f3f4f6;border-color:#374151}.sec,.pad button,.vol{background:#1f2937;color:#f3f4f6}.tabs{border-color:#374151}}' : '';
        return ':host{all:initial}' +
            '*{box-sizing:border-box;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}' +
            '.btn{position:fixed;' + vert + ':' + oy + ';' + side + ':' + ox + ';z-index:2147483000;background:' + color + ';color:' + fg + ';border:0;border-radius:' + radius + ';' +
                (round ? 'width:' + sz[3] + ';height:' + sz[3] + ';padding:0;justify-content:center;' : 'padding:' + sz[0] + ';') +
                'font-size:' + sz[1] + ';font-weight:600;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.25);display:flex;align-items:center;gap:8px}' +
            '.btn svg{width:' + sz[2] + ';height:' + sz[2] + ';fill:currentColor}' +
            '.panel{' + (inline ? 'position:relative;display:block;width:100%;max-width:' + width + ';box-shadow:0 2px 12px rgba(0,0,0,.12);' :
                'position:fixed;' + vert + ':' + gap + ';' + side + ':' + ox + ';z-index:2147483001;width:min(' + width + ',calc(100vw - 24px));box-shadow:0 12px 40px rgba(0,0,0,.25);display:none;') +
                'background:' + bg + ';color:' + text + ';border-radius:' + pr + ';overflow:hidden}' +
            '@media (max-width:480px){.panel{' + (inline ? '' : side + ':12px;') + '}.btn{' + side + ':12px}}' +
            '.panel.open{display:block}' +
            '.head{background:' + color + ';color:' + fg + ';padding:14px 16px;font-weight:600;display:flex;justify-content:space-between;align-items:center}' +
            '.x{background:none;border:0;color:' + fg + ';font-size:20px;cursor:pointer;line-height:1}' +
            '.tabs{display:flex;border-bottom:1px solid ' + border + '}' +
            '.tab{flex:1;background:none;border:0;padding:10px;font-size:14px;cursor:pointer;color:' + muted + '}' +
            '.tab.on{color:' + color + ';font-weight:600;box-shadow:inset 0 -2px 0 ' + color + '}' +
            '.body{padding:16px;display:flex;flex-direction:column;gap:10px}' +
            '.hint{font-size:13px;color:' + muted + ';margin:0}' +
            'input{width:100%;padding:10px 12px;border:1px solid ' + border + ';border-radius:8px;font-size:15px;color:' + text + ';background:' + field + '}' +
            '.go{background:' + color + ';color:' + fg + ';border:0;border-radius:8px;padding:11px;font-size:15px;font-weight:600;cursor:pointer}' +
            '.go[disabled]{opacity:.6;cursor:default}' +
            '.status{font-size:14px;text-align:center;min-height:20px}' +
            '.err{color:#dc2626}' +
            '.row{display:flex;gap:8px;flex-wrap:wrap}.row button{flex:1}' +
            '.sec{background:' + soft + ';color:' + text + ';border:0;border-radius:8px;padding:10px;font-size:14px;cursor:pointer}' +
            '.red{background:#dc2626;color:#fff}' +
            '.vol{display:flex;align-items:center;gap:8px;font-size:13px;background:' + soft + ';border-radius:8px;padding:6px 10px}.vol input{padding:0;border:0;background:none}' +
            '.pad{display:none;grid-template-columns:repeat(3,1fr);gap:6px}.pad.open{display:grid}' +
            '.pad button{background:' + soft + ';color:' + text + ';border:0;border-radius:8px;padding:10px;font-size:16px;cursor:pointer}' +
            '.note{font-size:12px;color:' + muted + ';text-align:center;margin:0}' +
            '.hidden{display:none}' + auto;
    }

    var PHONE_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z"/></svg>';

    function build() {
        if (host) {
            host.remove();
        }
        ui = {};
        var inline = L.mode === 'inline';
        host = el('div', { id: 'aipbx-widget', 'data-ownpbx-widget': WIDGET_ID });
        var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
        var style = el('style');
        style.textContent = css();
        root.appendChild(style);

        if (!inline) {
            var label = cfg.button_text || T.button;
            ui.button = el('button', { class: 'btn', type: 'button', 'aria-label': label, title: label });
            if (L.show_icon || L.shape === 'round' || !L.show_text) {
                ui.button.innerHTML = PHONE_ICON;
            }
            if (L.show_text && L.shape !== 'round') {
                ui.button.appendChild(el('span', { text: label }));
            }
            ui.button.addEventListener('click', togglePanel);
        }

        ui.panel = el('div', { class: 'panel' + (inline ? ' open' : ''), role: inline ? 'region' : 'dialog', 'aria-label': L.title || T.title });
        ui.panel.appendChild(el('div', { class: 'head' }, [
            el('span', { text: L.title || T.title }),
            inline ? null : el('button', { class: 'x', type: 'button', 'aria-label': T.close, text: '×', onclick: togglePanel })
        ]));

        if (cfg.call && cfg.callback) {
            ui.tabCall = el('button', { class: 'tab on', type: 'button', text: T.call, onclick: function () { showTab('call'); } });
            ui.tabCallback = el('button', { class: 'tab', type: 'button', text: T.callback, onclick: function () { showTab('callback'); } });
            ui.panel.appendChild(el('div', { class: 'tabs' }, [ui.tabCall, ui.tabCallback]));
        }

        var namePh = C.name === 'required' ? T.name_required : T.name;
        if (cfg.call) {
            ui.callName = C.name === 'off' ? null : el('input', { type: 'text', maxlength: '60', placeholder: namePh, autocomplete: 'name' });
            ui.callGo = el('button', { class: 'go', type: 'button', text: T.call, onclick: startCall });
            ui.callStatus = el('div', { class: 'status', 'aria-live': 'polite' });
            ui.hangBtn = el('button', { class: 'sec red', type: 'button', text: T.hangup, onclick: hangup });
            ui.muteBtn = C.mute ? el('button', { class: 'sec', type: 'button', text: T.mute, onclick: toggleMute }) : null;
            ui.holdBtn = C.hold ? el('button', { class: 'sec', type: 'button', text: T.hold, onclick: toggleHold }) : null;
            ui.padBtn = C.keypad ? el('button', { class: 'sec', type: 'button', text: T.keypad, onclick: function () { ui.pad.classList.toggle('open'); } }) : null;
            ui.inCall = el('div', { class: 'row hidden' }, [ui.muteBtn, ui.holdBtn, ui.padBtn, ui.hangBtn]);
            if (C.keypad) {
                ui.pad = el('div', { class: 'pad' });
                (C.keypad_keys === 'all' ? '123456789*0#' : '1234567890').split('').forEach(function (d) {
                    ui.pad.appendChild(el('button', { type: 'button', text: d, onclick: function () { if (session) { session.sendDTMF(d); } } }));
                });
            }
            if (C.volume) {
                ui.volRange = el('input', { type: 'range', min: '0', max: '100', value: '100', 'aria-label': T.volume });
                ui.volRange.addEventListener('input', function () { if (ui.audio) { ui.audio.volume = ui.volRange.value / 100; } });
                ui.vol = el('label', { class: 'vol hidden' }, [el('span', { text: T.volume }), ui.volRange]);
            }
            ui.callBody = el('div', { class: 'body' }, [
                el('p', { class: 'hint', text: L.greeting || T.call_hint }),
                ui.callName, ui.callGo, ui.callStatus, ui.inCall, ui.vol || null, ui.pad || null,
                PREVIEW ? el('p', { class: 'note', text: T.preview }) : null
            ]);
            ui.panel.appendChild(ui.callBody);
        }

        if (cfg.callback) {
            ui.cbName = C.name === 'off' ? null : el('input', { type: 'text', maxlength: '60', placeholder: namePh, autocomplete: 'name' });
            ui.cbNumber = el('input', { type: 'tel', maxlength: '20', placeholder: T.number, autocomplete: 'tel', required: 'required' });
            ui.cbGo = el('button', { class: 'go', type: 'button', text: T.send, onclick: requestCallback });
            ui.cbStatus = el('div', { class: 'status', 'aria-live': 'polite' });
            ui.cbBody = el('div', { class: 'body' + (cfg.call ? ' hidden' : '') }, [
                el('p', { class: 'hint', text: L.greeting || T.callback_hint }), ui.cbName, ui.cbNumber, ui.cbGo, ui.cbStatus
            ]);
            ui.panel.appendChild(ui.cbBody);
        }

        root.appendChild(ui.panel);
        if (ui.button) {
            root.appendChild(ui.button);
        }
        var place = TARGET ? document.querySelector(TARGET) : null;
        if (inline && place) {
            place.appendChild(host);
        } else if (inline && script.parentNode && script.parentNode !== document.head) {
            script.parentNode.insertBefore(host, script.nextSibling);
        } else {
            document.body.appendChild(host);
        }
    }

    function togglePanel() {
        ui.panel.classList.toggle('open');
    }

    function showTab(which) {
        ui.tabCall.classList.toggle('on', which === 'call');
        ui.tabCallback.classList.toggle('on', which === 'callback');
        ui.callBody.classList.toggle('hidden', which !== 'call');
        ui.cbBody.classList.toggle('hidden', which !== 'callback');
    }

    function setStatus(node, text, isError) {
        node.textContent = text || '';
        node.className = 'status' + (isError ? ' err' : '');
    }

    function nameOk(input, status) {
        if (C.name === 'required' && input && input.value.trim() === '') {
            setStatus(status, T.err_name, true);
            input.focus();
            return false;
        }
        return true;
    }

    // ------------------------------------------------------------ call

    function loadJsSip() {
        if (window.JsSIP) {
            return Promise.resolve(window.JsSIP);
        }
        return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = cfg.jssip;
            s.async = true;
            s.onload = function () { window.JsSIP ? resolve(window.JsSIP) : reject(new Error('jssip')); };
            s.onerror = function () { reject(new Error('jssip')); };
            document.head.appendChild(s);
        });
    }

    function startCall() {
        if (session || ui.callGo.disabled) {
            return;
        }
        if (PREVIEW) {
            // Show the in-call controls the visitor would get, without calling anyone.
            ui.callGo.classList.add('hidden');
            ui.inCall.classList.remove('hidden');
            if (ui.vol) {
                ui.vol.classList.remove('hidden');
            }
            setStatus(ui.callStatus, C.timer ? T.in_call + ' 0:42' : T.in_call);
            return;
        }
        if (!nameOk(ui.callName, ui.callStatus)) {
            return;
        }
        ui.callGo.disabled = true;
        setStatus(ui.callStatus, T.connecting);

        // Say which microphone problem it is: "allow it" does not help when there is none.
        if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            finish(T.err_mic_insecure, true);
            return;
        }
        // The page itself may forbid the microphone (Permissions-Policy: microphone=()):
        // then no browser setting helps, only the site owner can (Chromium tells us so).
        var policy = document.permissionsPolicy || document.featurePolicy;
        if (policy && typeof policy.allowsFeature === 'function' && !policy.allowsFeature('microphone')) {
            finish(T.err_mic_policy, true);
            return;
        }
        navigator.mediaDevices.getUserMedia({ audio: true, video: false }).then(function (s) {
            stream = s;
            return api('call', { w: WIDGET_ID, name: ui.callName ? ui.callName.value : '' });
        }, function (e) {
            var n = e && e.name;
            throw new Error(n === 'NotAllowedError' || n === 'SecurityError' ? 'mic_denied'
                : n === 'NotFoundError' || n === 'OverconstrainedError' ? 'mic_none'
                : n === 'NotReadableError' || n === 'AbortError' ? 'mic_busy' : 'mic');
        }).then(function (res) {
            if (!res || !res.success) {
                throw new Error((res && res.error) || 'default');
            }
            return loadJsSip().then(function (JsSIP) { dial(JsSIP, res); });
        }).catch(function (e) {
            finish(e && e.message ? errorText(e.message) : T.err_default, true);
        });
    }

    function dial(JsSIP, res) {
        if (JsSIP.debug && JsSIP.debug.disable) {
            JsSIP.debug.disable();
        }
        ua = new JsSIP.UA({
            sockets: [new JsSIP.WebSocketInterface(res.ws_url)],
            uri: res.uri,
            password: res.password,
            display_name: res.display_name || '',
            register: false,
            session_timers: false
        });
        ua.on('connected', function () {
            if (session) {
                return;
            }
            session = ua.call(res.target, {
                mediaStream: stream,
                pcConfig: { iceServers: res.ice_servers || [] },
                eventHandlers: {
                    peerconnection: function (e) {
                        e.peerconnection.addEventListener('track', function (ev) {
                            if (!ui.audio) {
                                ui.audio = new Audio();
                                ui.audio.autoplay = true;
                            }
                            ui.audio.srcObject = ev.streams[0];
                            if (ui.volRange) {
                                ui.audio.volume = ui.volRange.value / 100;
                            }
                            ui.audio.play().catch(function () {});
                        });
                    },
                    progress: function () { setStatus(ui.callStatus, T.ringing); },
                    confirmed: onConfirmed,
                    ended: function () { finish(T.ended, false); },
                    failed: function (e) {
                        var busy = e && (e.cause === JsSIP.C.causes.BUSY || e.cause === JsSIP.C.causes.UNAVAILABLE);
                        finish(busy ? T.err_busy : T.err_failed, true);
                    }
                }
            });
            ui.callGo.classList.add('hidden');
            ui.inCall.classList.remove('hidden');
            if (ui.vol) {
                ui.vol.classList.remove('hidden');
            }
        });
        var thisUa = ua;
        ua.on('disconnected', function () {
            // finish() stops the UA it ends with (ua is null by then): that
            // disconnect is expected and must not turn "Call ended" into an error.
            if (ua === thisUa && !session) {
                finish(T.err_failed, true);
            }
        });
        ua.start();
    }

    function onConfirmed() {
        var started = Date.now();
        var limit = (cfg.max_seconds || 0) * 1000;
        timer = setInterval(function () {
            var s = Math.floor((Date.now() - started) / 1000);
            if (!held) {
                setStatus(ui.callStatus, C.timer ? T.in_call + ' ' + Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2) : T.in_call);
            }
            if (limit && Date.now() - started > limit + 5000) {
                hangup();
            }
        }, 1000);
        setStatus(ui.callStatus, T.in_call);
    }

    function toggleMute() {
        if (!session && !PREVIEW) {
            return;
        }
        muted = !muted;
        if (session) {
            if (muted) { session.mute({ audio: true }); } else { session.unmute({ audio: true }); }
        }
        ui.muteBtn.textContent = muted ? T.unmute : T.mute;
    }

    function toggleHold() {
        if (!session && !PREVIEW) {
            return;
        }
        held = !held;
        if (session) {
            try { if (held) { session.hold(); } else { session.unhold(); } } catch (e) { held = !held; return; }
        }
        ui.holdBtn.textContent = held ? T.resume : T.hold;
        if (held) {
            setStatus(ui.callStatus, T.on_hold);
        }
    }

    function hangup() {
        if (session) {
            try { session.terminate(); } catch (e) { /* already ended */ }
        } else {
            finish(T.ended, false);
        }
    }

    function finish(text, isError) {
        clearInterval(timer);
        timer = null;
        if (stream) {
            stream.getTracks().forEach(function (t) { t.stop(); });
            stream = null;
        }
        if (ua) {
            var old = ua;
            ua = null;
            setTimeout(function () { try { old.stop(); } catch (e) { /* stopped */ } }, 0);
        }
        session = null;
        muted = false;
        held = false;
        if (ui.muteBtn) { ui.muteBtn.textContent = T.mute; }
        if (ui.holdBtn) { ui.holdBtn.textContent = T.hold; }
        if (ui.pad) { ui.pad.classList.remove('open'); }
        if (ui.vol) { ui.vol.classList.add('hidden'); }
        ui.inCall.classList.add('hidden');
        ui.callGo.classList.remove('hidden');
        ui.callGo.disabled = false;
        setStatus(ui.callStatus, text, isError);
    }

    // ------------------------------------------------------------ call-back

    function requestCallback() {
        if (PREVIEW) {
            setStatus(ui.cbStatus, T.preview, false);
            return;
        }
        if (!nameOk(ui.cbName, ui.cbStatus)) {
            return;
        }
        var number = ui.cbNumber.value.replace(/[^0-9+]/g, '');
        if (number.length < 6) {
            setStatus(ui.cbStatus, T.err_bad_number, true);
            return;
        }
        ui.cbGo.disabled = true;
        setStatus(ui.cbStatus, T.connecting);
        api('callback', { w: WIDGET_ID, number: number, name: ui.cbName ? ui.cbName.value : '' }).then(function (res) {
            if (res && res.success) {
                setStatus(ui.cbStatus, T.calling_you);
                ui.cbNumber.value = '';
                setTimeout(function () { ui.cbGo.disabled = false; }, 30000);
            } else {
                setStatus(ui.cbStatus, errorText(res && res.error), true);
                ui.cbGo.disabled = false;
            }
        }).catch(function () {
            setStatus(ui.cbStatus, T.err_default, true);
            ui.cbGo.disabled = false;
        });
    }

    // ------------------------------------------------------------ start

    function apply(res) {
        cfg = res;
        L = merge(LAYOUT_DEFAULTS, res.layout);
        C = merge(CONTROLS_DEFAULTS, res.controls);
        T = merge(merge(TEXTS.en, EXTRA.en), merge(TEXTS[cfg.language] || {}, EXTRA[cfg.language] || {}));
        // No navigator.mediaDevices on a plain http:// page: still show the widget, and Call says
        // why the microphone cannot be used there (err_mic_insecure) instead of vanishing.
        if (cfg.call && !PREVIEW && !window.RTCPeerConnection) {
            cfg.call = false;
            warn('this browser cannot make calls (no WebRTC); only the call-back form is shown');
        }
        if (!cfg.call && !cfg.callback) {
            return;
        }
        if (!PREVIEW && !L.show_on_mobile && window.matchMedia && window.matchMedia('(max-width: 480px)').matches) {
            return;
        }
        var delay = PREVIEW ? 0 : Math.max(0, Math.min(60, +L.delay || 0)) * 1000;
        setTimeout(build, delay);
    }

    function warn(text) {
        if (window.console && console.warn) {
            console.warn('[OwnPBX widget ' + WIDGET_ID + '] ' + text);
        }
    }

    function cached() {
        try {
            var c = JSON.parse(sessionStorage.getItem(CACHE_KEY) || 'null');
            return c && Date.now() - c.t < CACHE_MS ? c.cfg : null;
        } catch (e) {
            return null;
        }
    }

    function start() {
        var c = cached();
        if (c) {
            apply(c);
            return;
        }
        api('config', { w: WIDGET_ID }, 'GET').then(function (res) {
            if (!res || !res.success) {
                warn('the PBX refused: ' + ((res && res.error) || 'unknown') + (res && res.error === 'not_found' ? ' (wrong widget id, or the widget is switched off)' : ''));
                return;
            }
            if (!res.call && !res.callback) {
                warn('neither Browser call nor Call-back form is turned on for this widget');
                return;
            }
            try { sessionStorage.setItem(CACHE_KEY, JSON.stringify({ t: Date.now(), cfg: res })); } catch (e) { /* storage off */ }
            apply(res);
        }).catch(function () {
            // A refused origin gets no CORS header, so the browser hides the answer: say what it usually is.
            warn('could not load its settings from ' + BASE + '. Add "' + location.hostname
                + '" to the widget\'s Allowed websites in the PBX (Web Widgets > Edit), or check that the PBX is reachable.');
        });
    }

    // Preview (the PBX's own widget editor): the page sends the settings being edited.
    if (PREVIEW) {
        window.addEventListener('message', function (e) {
            if (e.origin !== BASE || !e.data || e.data.type !== 'ownpbx-widget-preview') {
                return;
            }
            apply(e.data.config || {});
        });
        if (window.parent !== window) {
            window.parent.postMessage({ type: 'ownpbx-widget-preview-ready' }, BASE);
        }
        return;
    }

    // Never compete with the page: wait for it to finish loading, then for an idle moment.
    function whenIdle() {
        if (window.requestIdleCallback) {
            window.requestIdleCallback(start, { timeout: 3000 });
        } else {
            setTimeout(start, 200);
        }
    }
    if (document.readyState === 'complete') {
        whenIdle();
    } else {
        window.addEventListener('load', whenIdle);
    }
})();
