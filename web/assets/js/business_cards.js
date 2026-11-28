/**
 * Integrations -> Business cards.
 *
 * One job: add another blank line to the card's field list. The rows are plain inputs named
 * as arrays (field_kind[], field_label[], field_value[]), so the form needs no scripting to
 * submit and the page still works with this file blocked - you just get the lines the card
 * already had.
 *
 * The new row is built by cloning the first one and clearing it, rather than by writing
 * markup from a string, so the option list stays in step with whatever the template rendered
 * and there is no HTML built out of values at runtime.
 */
(function () {
    'use strict';

    var addBtn = document.getElementById('cardAddLine');
    var host = document.getElementById('cardFields');
    if (!addBtn || !host) {
        return;
    }

    addBtn.addEventListener('click', function () {
        var first = host.querySelector('.form-row');
        if (!first) {
            return;
        }
        var row = first.cloneNode(true);
        row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        row.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
        host.appendChild(row);
        var firstInput = row.querySelector('input');
        if (firstInput) {
            firstInput.focus();
        }
    });
}());
