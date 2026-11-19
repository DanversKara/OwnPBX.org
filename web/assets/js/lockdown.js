/* Page script of templates/views/lockdown/index.php */

// Asking before the irreversible ones. The question text lives in a data-confirm attribute
// rather than inside a quoted confirm('...') in the markup: a translation containing an
// apostrophe would break the handler, and a broken handler does not ask at all - it lets the
// click straight through, which on this page means signing everyone out by accident.
(function () {
    let submitter = null;
    document.addEventListener('click', e => {
        const b = e.target.closest('button[type=submit]');
        if (b) { submitter = b; }
    });
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', e => {
            const msg = (submitter && submitter.form === form && submitter.dataset.confirm)
                || form.dataset.confirm;
            if (msg && !window.confirm(msg)) {
                e.preventDefault();
                return;
            }
            if (submitter && submitter.name && submitter.form === form) {
                // A button is disabled below, and a disabled button is not submitted: carry the
                // action it chose over by hand, or the form arrives with no action at all.
                const h = document.createElement('input');
                h.type = 'hidden';
                h.name = submitter.name;
                h.value = submitter.value;
                form.appendChild(h);
            }
            form.querySelectorAll('button[type=submit]').forEach(b => { b.disabled = true; });
        });
    });
})();
