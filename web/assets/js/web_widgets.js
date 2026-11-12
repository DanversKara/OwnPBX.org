/* Page script of templates/views/web_widgets/index.php */

var WIDGET_DEFAULTS = {
    id: 0, name: '', number: '', is_active: 1, dest_type: 'extension', dest_id: '',
    external_number: '', outbound_route_id: 0, allowed_origins: '',
    call_enabled: 1, callback_enabled: 0, callback_prefixes: '', callback_cid: '',
    max_concurrent: 2, max_call_seconds: 900, ip_hourly_limit: 10, daily_limit: 0,
    button_text: '', color: '#2563eb', position: 'right', language: 'en', layout_json: '', controls_json: ''
};

var WIDGET_LAYOUT_DEFAULTS = {
    mode: 'floating', vertical: 'bottom', offset_x: 20, offset_y: 20, size: 'medium', shape: 'pill',
    show_icon: true, show_text: true, text_color: '#ffffff', panel_width: 320, radius: 16, theme: 'light',
    title: '', greeting: '', show_on_mobile: true, delay: 0
};
var WIDGET_CONTROL_DEFAULTS = { mute: true, keypad: true, keypad_keys: 'digits', hold: false, volume: false, timer: true, name: 'off' };

function parseWidgetJson(text, defaults) {
    let v = {};
    try { v = JSON.parse(text || '{}') || {}; } catch (e) { v = {}; }
    return Object.assign({}, defaults, v);
}

function fillWidgetForm(w) {
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = (v === null || v === undefined) ? '' : String(v); };
    const check = (id, v) => { const el = document.getElementById(id); if (el) el.checked = String(v) === '1'; };
    set('widget_id', w.id);
    set('widget_name', w.name);
    set('widget_number', w.number);
    set('widget_dest_type', w.dest_type);
    set('widget_external_number', w.external_number);
    set('widget_outbound_route_id', w.outbound_route_id || 0);
    set('widget_allowed_origins', w.allowed_origins);
    set('widget_callback_prefixes', w.callback_prefixes);
    set('widget_callback_cid', w.callback_cid);
    set('widget_max_concurrent', w.max_concurrent);
    set('widget_max_call_seconds', w.max_call_seconds);
    set('widget_ip_hourly_limit', w.ip_hourly_limit);
    set('widget_daily_limit', w.daily_limit);
    set('widget_button_text', w.button_text);
    set('widget_color', w.color);
    set('widget_position', w.position);
    set('widget_language', w.language);
    check('widget_is_active', w.is_active);
    check('widget_call_enabled', w.call_enabled);
    check('widget_callback_enabled', w.callback_enabled);
    const layout = parseWidgetJson(w.layout_json, WIDGET_LAYOUT_DEFAULTS);
    Object.keys(WIDGET_LAYOUT_DEFAULTS).forEach(k => {
        if (typeof WIDGET_LAYOUT_DEFAULTS[k] === 'boolean') {
            const el = document.getElementById('widget_layout_' + k);
            if (el) el.checked = !!layout[k];
        } else {
            set('widget_layout_' + k, layout[k]);
        }
    });
    const controls = parseWidgetJson(w.controls_json, WIDGET_CONTROL_DEFAULTS);
    if (!w.controls_json && String(w.ask_name) === '1') controls.name = 'optional';
    Object.keys(WIDGET_CONTROL_DEFAULTS).forEach(k => {
        if (typeof WIDGET_CONTROL_DEFAULTS[k] === 'boolean') {
            const el = document.getElementById('widget_ctl_' + k);
            if (el) el.checked = !!controls[k];
        } else {
            set('widget_ctl_' + k, controls[k]);
        }
    });
    onWidgetDestTypeChange(w.dest_id);
    refreshWidgetPreview(true);
}

function openCreateWidgetModal() {
    document.getElementById('widgetModalTitle').innerHTML = '<i class="fas fa-plus-circle u-primary"></i> ' + escapeHtml(__('js.web_widgets.add'));
    fillWidgetForm(WIDGET_DEFAULTS);
    UIHelper.openOverlayModal('widgetModal');
}

function openEditWidgetModal(w) {
    document.getElementById('widgetModalTitle').innerHTML = '<i class="fas fa-edit u-primary"></i> ' + escapeHtml(__('js.web_widgets.edit') + ' ' + (w.name || ''));
    fillWidgetForm(Object.assign({}, WIDGET_DEFAULTS, w));
    UIHelper.openOverlayModal('widgetModal');
}

/** Shows the fields the chosen destination and the call-back need. */
function onWidgetDestTypeChange(selectedId) {
    const type = document.getElementById('widget_dest_type').value;
    const external = type === 'external';
    const callback = document.getElementById('widget_callback_enabled').checked;
    document.getElementById('widget_dest_id_group').style.display = external ? 'none' : '';
    document.getElementById('widget_external_group').style.display = external ? '' : 'none';
    document.getElementById('widget_external_warning').style.display = (external || callback) ? '' : 'none';
    document.getElementById('widget_callback_group').style.display = callback ? '' : 'none';
    document.getElementById('widget_route_group').style.display = (external || callback) ? '' : 'none';
    sendWidgetPreview();
    if (!external) {
        loadDestinationOptions('widget_dest_type', 'widget_dest_id', typeof selectedId === 'string' ? selectedId : '');
    }
}

function copyWidgetCode(btn) {
    UIHelper.copyToClipboard(btn.getAttribute('data-code') || '', __('js.web_widgets.code_copied'));
}

// ------------------------------------------------------------ live preview

/** The settings as the form has them now, shaped like /widget-api/config's answer. */
function widgetPreviewConfig() {
    const val = id => { const el = document.getElementById(id); return el ? el.value : ''; };
    const on = id => { const el = document.getElementById(id); return !!(el && el.checked); };
    const layout = {};
    Object.keys(WIDGET_LAYOUT_DEFAULTS).forEach(k => {
        layout[k] = typeof WIDGET_LAYOUT_DEFAULTS[k] === 'boolean' ? on('widget_layout_' + k)
            : (typeof WIDGET_LAYOUT_DEFAULTS[k] === 'number' ? Number(val('widget_layout_' + k) || 0) : val('widget_layout_' + k));
    });
    const controls = {};
    Object.keys(WIDGET_CONTROL_DEFAULTS).forEach(k => {
        controls[k] = typeof WIDGET_CONTROL_DEFAULTS[k] === 'boolean' ? on('widget_ctl_' + k) : val('widget_ctl_' + k);
    });
    return {
        call: on('widget_call_enabled'),
        callback: on('widget_callback_enabled'),
        button_text: val('widget_button_text'),
        color: val('widget_color') || '#2563eb',
        position: val('widget_position') || 'right',
        language: val('widget_language') || 'en',
        ask_name: controls.name !== 'off',
        layout: layout,
        controls: controls
    };
}

function sendWidgetPreview() {
    const floating = (document.getElementById('widget_layout_mode') || {}).value !== 'inline';
    document.querySelectorAll('#widgetModal .ww-floating').forEach(el => { el.style.display = floating ? '' : 'none'; });
    const frame = document.getElementById('widgetPreview');
    if (frame && frame.contentWindow && frame.dataset.ready === '1') {
        frame.contentWindow.postMessage({ type: 'ownpbx-widget-preview', config: widgetPreviewConfig() }, location.origin);
    }
}

/** (Re)loads the preview page; it says when it is ready, and gets the settings then. */
function refreshWidgetPreview(reload) {
    const frame = document.getElementById('widgetPreview');
    if (!frame) return;
    if (reload || !frame.dataset.loaded) {
        frame.dataset.ready = '0';
        frame.dataset.loaded = '1';
        frame.srcdoc = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            + '<style>body{margin:0;font-family:system-ui,sans-serif;background:repeating-linear-gradient(45deg,#f5f6f8 0 12px,#eef0f3 12px 24px);min-height:100vh}'
            + '.fake{margin:16px;padding:14px;background:#fff;border-radius:8px;color:#9aa0a6;font-size:12px}#ownpbx-call-preview{margin:16px}</style></head><body>'
            + '<div class="fake">Your web page</div><div id="ownpbx-call-preview"></div>'
            + '<script src="' + location.origin + '/widget.js" data-preview="1" data-target="#ownpbx-call-preview"><\/script></body></html>';
    }
    sendWidgetPreview();
}

window.addEventListener('message', e => {
    if (e.origin !== location.origin || !e.data || e.data.type !== 'ownpbx-widget-preview-ready') return;
    const frame = document.getElementById('widgetPreview');
    if (frame && e.source === frame.contentWindow) {
        frame.dataset.ready = '1';
        sendWidgetPreview();
    }
});

document.addEventListener('input', e => { if (e.target.closest && e.target.closest('#widgetModal') && e.target.classList.contains('ww-live')) sendWidgetPreview(); });
document.addEventListener('change', e => { if (e.target.closest && e.target.closest('#widgetModal') && (e.target.classList.contains('ww-live') || e.target.id === 'widget_call_enabled')) sendWidgetPreview(); });
