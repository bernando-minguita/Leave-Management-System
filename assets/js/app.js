/**
 * LMS - shared application behaviour.
 *
 * Responsibilities:
 *   1. toast host (flash + AJAX results)
 *   2. confirm dialogs (data-confirm on any link/form)
 *   3. the "Mark Returned" quick modal
 *   4. the "Cancel Leave" modal
 *   5. login password reveal
 *
 * No business rules live here - every mutating call goes to the PHP API,
 * which owns validation and derives the leave status itself.
 */
(function () {
    'use strict';

    var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    // Settings > Display date format, converted server-side to a date()
    // pattern. Without it every date rendered in JS was hard-coded to "d M Y",
    // which is why the Mark Return modal disagreed with the rest of the page
    // as soon as the setting was changed.
    var DATE_FORMAT = (document.querySelector('meta[name="date-format"]') || {}).content || 'd M Y';

    var MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var MONTHS_LONG = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
    var DAYS_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    var DAYS_LONG = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday',
        'Friday', 'Saturday'];

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    /**
     * Render an ISO date (YYYY-MM-DD) using the system date pattern.
     *
     * The pattern arrives via <meta name="date-format"> straight from
     * Settings > Display date format, so a date rendered in JS - the Mark
     * Return modal's Expected Return - matches every server-rendered date on
     * the page instead of a format baked into this file.
     *
     * Falls back unchanged to the old "d M Y" shape when the date is not
     * ISO, when the pattern is missing, or when the date is not a real
     * calendar day (2026-02-31).
     */
    function formatDate(iso, pattern) {
        var parts = String(iso).split('-');
        if (parts.length !== 3) { return iso; }

        var year = parseInt(parts[0], 10);
        var month = parseInt(parts[1], 10);
        var day = parseInt(parts[2], 10);
        if (!year || !month || !day) { return iso; }

        var when = new Date(year, month - 1, day);
        if (when.getFullYear() !== year || when.getMonth() !== month - 1 || when.getDate() !== day) {
            return iso;
        }

        var fmt = pattern || DATE_FORMAT || 'd M Y';
        var out = '';
        var i;
        var ch;

        for (i = 0; i < fmt.length; i++) {
            ch = fmt.charAt(i);

            switch (ch) {
                case 'd': out += pad(day); break;
                case 'j': out += day; break;
                case 'm': out += pad(month); break;
                case 'n': out += month; break;
                case 'M': out += MONTHS_SHORT[month - 1]; break;
                case 'F': out += MONTHS_LONG[month - 1]; break;
                case 'Y': out += year; break;
                case 'y': out += String(year).slice(-2); break;
                case 'D': out += DAYS_SHORT[when.getDay()]; break;
                case 'l': out += DAYS_LONG[when.getDay()]; break;
                case 'S':
                    out += (day % 10 === 1 && day !== 11) ? 'st'
                        : (day % 10 === 2 && day !== 12) ? 'nd'
                        : (day % 10 === 3 && day !== 13) ? 'rd' : 'th';
                    break;
                // Separators and anything not recognised pass through as-is.
                default: out += ch;
            }
        }

        return out;
    }

    /* ------------------------------------------------------------- toasts */

    function toast(type, message) {
        var host = document.getElementById('toastHost');
        if (!host) { return; }

        var bg = { success: 'text-bg-success', danger: 'text-bg-danger',
            warning: 'text-bg-warning', info: 'text-bg-info' }[type] || 'text-bg-info';

        var wrap = document.createElement('div');
        wrap.className = 'toast align-items-center ' + bg + ' border-0 show';
        wrap.setAttribute('role', 'alert');
        wrap.innerHTML =
            '<div class="d-flex">' +
            '  <div class="toast-body">' + escapeHtml(message) + '</div>' +
            '  <button type="button" class="btn-close btn-close-white me-2 m-auto" ' +
            '          data-bs-dismiss="toast" aria-label="Close"></button>' +
            '</div>';

        host.appendChild(wrap);

        setTimeout(function () {
            if (wrap.parentNode) { wrap.parentNode.removeChild(wrap); }
        }, 5000);
    }

    // Exposed for page scripts (dashboard.js, leave.js, calendar.js).
    window.FL = {
        toast: toast,
        escapeHtml: escapeHtml,
        csrf: CSRF,
        pad: pad,
        formatDate: formatDate
    };

    /* ------------------------------------------------- field-level errors */

    function showFieldErrors(form, errors) {
        clearFieldErrors(form);
        Object.keys(errors || {}).forEach(function (field) {
            var input = form.querySelector('[name="' + field + '"]');
            if (!input) { return; }
            input.classList.add('is-invalid');
            var feedback = input.parentNode.querySelector('.invalid-feedback');
            if (feedback) { feedback.textContent = errors[field]; }
        });
    }

    function clearFieldErrors(form) {
        var list = form.querySelectorAll('.is-invalid');
        for (var i = 0; i < list.length; i++) {
            list[i].classList.remove('is-invalid');
        }
    }

    window.FL.showFieldErrors = showFieldErrors;
    window.FL.clearFieldErrors = clearFieldErrors;

    /* -------------------------------------------------- confirm dialogs -- */

    var confirmModalEl = document.getElementById('confirmModal');
    var confirmTarget = null;

    function openConfirm(message, onSubmit) {
        if (!confirmModalEl || !window.bootstrap) {
            if (window.confirm(message)) { onSubmit(); }
            return;
        }

        document.getElementById('confirmBody').textContent = message;
        confirmTarget = onSubmit;
        window.bootstrap.Modal.getOrCreateInstance(confirmModalEl).show();
    }

    var confirmBtn = document.getElementById('confirmProceed');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            var run = confirmTarget;
            confirmTarget = null;

            var modal = window.bootstrap.Modal.getInstance(confirmModalEl);
            if (modal) { modal.hide(); }

            if (run) { run(); }
        });
    }

    // Any element carrying data-confirm asks before acting.
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-confirm]');
        if (!trigger) { return; }

        var message = trigger.getAttribute('data-confirm');

        if (trigger.tagName === 'BUTTON' && trigger.type === 'submit') {
            event.preventDefault();
            var form = trigger.form;
            openConfirm(message, function () {
                HTMLFormElement.prototype.submit.call(form);
            });
            return;
        }

        if (trigger.tagName === 'FORM') {
            event.preventDefault();
            var self = trigger;
            openConfirm(message, function () {
                HTMLFormElement.prototype.submit.call(self);
            });
        }
    });

    function postJson(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            });
        });
    }

    function applyKpis(kpis) {
        var cards = document.querySelectorAll('[data-kpi]');
        for (var i = 0; i < cards.length; i++) {
            var key = cards[i].getAttribute('data-kpi');
            if (Object.prototype.hasOwnProperty.call(kpis, key)) {
                cards[i].textContent = kpis[key];
            }
        }
    }

    window.FL.postJson = postJson;
    window.FL.openConfirm = openConfirm;

    /* --------------------------------------------- mark returned modal --- */

    var mrModalEl = document.getElementById('markReturnedModal');
    var mrForm = document.getElementById('markReturnedForm');

    function markReturnedUrl() {
        if (window.APP_URLS && window.APP_URLS.markReturned) {
            return window.APP_URLS.markReturned;
        }

        var path = window.location.pathname.replace(/\/(leave|drivers|reports|settings|dashboard).*$/, '');
        return path + '/api/leave/mark-returned';
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-action="mark-returned"]');
        if (!trigger || !mrModalEl) { return; }

        document.getElementById('mr_id').value = trigger.getAttribute('data-id') || '';
        document.getElementById('mr_driver').textContent = trigger.getAttribute('data-driver') || '—';
        document.getElementById('mr_badge').textContent = trigger.getAttribute('data-badge') || '—';

        var expected = trigger.getAttribute('data-expected') || '';
        document.getElementById('mr_expected').textContent = expected ? formatDate(expected) : '—';

        // Default to today; never allow a date before the leave start.
        var start = trigger.getAttribute('data-start') || '';
        var now = new Date();
        var iso = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());

        var actual = document.getElementById('mr_actual');
        actual.value = iso;
        actual.min = start || '';
        document.getElementById('mr_time').value = pad(now.getHours()) + ':' + pad(now.getMinutes());
        document.getElementById('mr_remarks').value = '';

        document.getElementById('mr_error').classList.add('d-none');
        clearFieldErrors(mrForm);

        window.bootstrap.Modal.getOrCreateInstance(mrModalEl).show();
    });

    if (mrForm) {
        mrForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var errorBox = document.getElementById('mr_error');
            var submit = document.getElementById('mr_submit');
            var spinner = submit.querySelector('.spinner-border');

            errorBox.classList.add('d-none');
            clearFieldErrors(mrForm);

            var payload = {
                id: parseInt(document.getElementById('mr_id').value, 10),
                actual_arrival_date: document.getElementById('mr_actual').value,
                actual_arrival_time: document.getElementById('mr_time').value || null,
                return_remarks: document.getElementById('mr_remarks').value || null
            };

            submit.disabled = true;
            if (spinner) { spinner.classList.remove('d-none'); }

            postJson(markReturnedUrl(), payload)
                .then(function (result) {
                    if (!result.ok) {
                        errorBox.textContent = result.data.message || 'Could not save the return.';
                        errorBox.classList.remove('d-none');
                        showFieldErrors(mrForm, result.data.errors);
                        return;
                    }

                    toast('success', result.data.message || 'Driver marked as returned.');
                    if (result.data.kpis) { applyKpis(result.data.kpis); }

                    var modal = window.bootstrap.Modal.getInstance(mrModalEl);
                    if (modal) { modal.hide(); }

                    // Let the user read the toast, then refresh lists + panels.
                    // rememberScroll() keeps the viewport on the row we just
                    // acted on - reload() would otherwise reset it to the top.
                    setTimeout(function () { window.rememberScroll(); window.location.reload(); }, 700);
                })
                .catch(function () {
                    errorBox.textContent = 'Network error. Please try again.';
                    errorBox.classList.remove('d-none');
                })
                .finally(function () {
                    submit.disabled = false;
                    if (spinner) { spinner.classList.add('d-none'); }
                });
        });
    }

    /* --------------------------------------------------- cancel modal ---- */

    var clModalEl = document.getElementById('cancelLeaveModal');
    var clForm = document.getElementById('cancelLeaveForm');

    function cancelUrl() {
        var path = window.location.pathname.replace(/\/(leave|drivers|reports|settings|dashboard).*$/, '');
        return path + '/api/leave/cancel';
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-action="cancel-leave"]');
        if (!trigger || !clModalEl) { return; }

        document.getElementById('cl_id').value = trigger.getAttribute('data-id') || '';
        document.getElementById('cl_driver').textContent = trigger.getAttribute('data-driver') || '—';
        document.getElementById('cl_reason').value = '';
        document.getElementById('cl_error').classList.add('d-none');
        clearFieldErrors(clForm);

        window.bootstrap.Modal.getOrCreateInstance(clModalEl).show();
    });

    if (clForm) {
        clForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var errorBox = document.getElementById('cl_error');
            var submit = document.getElementById('cl_submit');
            var spinner = submit.querySelector('.spinner-border');

            errorBox.classList.add('d-none');
            clearFieldErrors(clForm);

            submit.disabled = true;
            if (spinner) { spinner.classList.remove('d-none'); }

            postJson(cancelUrl(), {
                id: parseInt(document.getElementById('cl_id').value, 10),
                cancel_reason: document.getElementById('cl_reason').value
            })
                .then(function (result) {
                    if (!result.ok) {
                        errorBox.textContent = result.data.message || 'Could not cancel the record.';
                        errorBox.classList.remove('d-none');
                        showFieldErrors(clForm, result.data.errors);
                        return;
                    }

                    toast('success', result.data.message || 'Leave record cancelled.');
                    var modal = window.bootstrap.Modal.getInstance(clModalEl);
                    if (modal) { modal.hide(); }
                    setTimeout(function () { window.rememberScroll(); window.location.reload(); }, 700);
                })
                .catch(function () {
                    errorBox.textContent = 'Network error. Please try again.';
                    errorBox.classList.remove('d-none');
                })
                .finally(function () {
                    submit.disabled = false;
                    if (spinner) { spinner.classList.add('d-none'); }
                });
        });
    }

    /* ------------------------------------------------ not-returned modal - */

    var nrModalEl = document.getElementById('notReturnedModal');
    var nrForm = document.getElementById('notReturnedForm');

    function notReturnedUrl() {
        var path = window.location.pathname.replace(/\/(leave|drivers|reports|settings|dashboard).*$/, '');
        return path + '/api/leave/not-returned';
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-action="not-returned"]');
        if (!trigger || !nrModalEl) { return; }

        document.getElementById('nr_id').value = trigger.getAttribute('data-id') || '';
        document.getElementById('nr_driver').textContent = trigger.getAttribute('data-driver') || '—';
        document.getElementById('nr_badge').textContent = trigger.getAttribute('data-badge') || '—';

        var expected = trigger.getAttribute('data-expected') || '';
        document.getElementById('nr_expected').textContent = expected ? formatDate(expected) : '—';

        // Never pre-select a reason: making the operator state one is the
        // entire point of the field, and a defaulted dropdown gets dismissed.
        document.getElementById('nr_reason').value = '';

        // Default the decision to today, but never allow one that predates
        // the leave itself.
        var start = trigger.getAttribute('data-start') || '';
        var now = new Date();
        var iso = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());

        var date = document.getElementById('nr_date');
        date.value = iso;
        date.min = start || '';

        document.getElementById('nr_remarks').value = '';
        document.getElementById('nr_error').classList.add('d-none');
        clearFieldErrors(nrForm);

        window.bootstrap.Modal.getOrCreateInstance(nrModalEl).show();
    });

    if (nrForm) {
        nrForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var errorBox = document.getElementById('nr_error');
            var submit = document.getElementById('nr_submit');
            var spinner = submit.querySelector('.spinner-border');

            errorBox.classList.add('d-none');
            clearFieldErrors(nrForm);

            submit.disabled = true;
            if (spinner) { spinner.classList.remove('d-none'); }

            // No client-side rules: the server owns them, so a message the
            // user sees is always the same one the API would enforce.
            postJson(notReturnedUrl(), {
                id: parseInt(document.getElementById('nr_id').value, 10),
                not_returned_reason: document.getElementById('nr_reason').value,
                not_returned_date: document.getElementById('nr_date').value,
                not_returned_remarks: document.getElementById('nr_remarks').value
            })
                .then(function (result) {
                    if (!result.ok) {
                        errorBox.textContent = result.data.message || 'Could not close this leave.';
                        errorBox.classList.remove('d-none');
                        showFieldErrors(nrForm, result.data.errors);
                        return;
                    }

                    toast('success', result.data.message || 'Leave closed as Not Returned.');
                    if (result.data.kpis) { applyKpis(result.data.kpis); }

                    var modal = window.bootstrap.Modal.getInstance(nrModalEl);
                    if (modal) { modal.hide(); }
                    setTimeout(function () { window.rememberScroll(); window.location.reload(); }, 700);
                })
                .catch(function () {
                    errorBox.textContent = 'Network error. Please try again.';
                    errorBox.classList.remove('d-none');
                })
                .finally(function () {
                    submit.disabled = false;
                    if (spinner) { spinner.classList.add('d-none'); }
                });
        });
    }

    /* -------------------------------------------- change status modal --- */

    var csModalEl = document.getElementById('changeStatusModal');
    var csForm = document.getElementById('changeStatusForm');

    function changeStatusUrl() {
        var path = window.location.pathname.replace(/\/(leave|drivers|reports|settings|dashboard).*$/, '');
        return path + '/api/leave/change-status';
    }

    // The date fields belong to the chosen target: arrival only for
    // Returned, a new expected date only for reopening as On leave.
    function csSyncFields() {
        var target = document.getElementById('cs_target').value;
        document.getElementById('cs_actual_group').classList.toggle('d-none', target !== 'returned');
        document.getElementById('cs_expected_group').classList.toggle('d-none', target !== 'on_leave');
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-action="change-status"]');
        if (!trigger || !csModalEl) { return; }

        document.getElementById('cs_id').value = trigger.getAttribute('data-id') || '';
        document.getElementById('cs_driver').textContent = trigger.getAttribute('data-driver') || '—';
        document.getElementById('cs_badge').textContent = trigger.getAttribute('data-badge') || '—';
        document.getElementById('cs_current').textContent = trigger.getAttribute('data-status-label') || '—';

        document.getElementById('cs_target').value = 'returned';

        // Arrival defaults to today, never before the leave started.
        var start = trigger.getAttribute('data-start') || '';
        var now = new Date();
        var iso = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
        var actual = document.getElementById('cs_actual');
        actual.value = iso;
        actual.min = start || '';

        // Empty means "keep the current expected date".
        var expected = document.getElementById('cs_expected');
        expected.value = '';
        expected.min = iso;

        document.getElementById('cs_remarks').value = '';
        document.getElementById('cs_error').classList.add('d-none');
        clearFieldErrors(csForm);
        csSyncFields();

        window.bootstrap.Modal.getOrCreateInstance(csModalEl).show();
    });

    if (csForm) {
        document.getElementById('cs_target').addEventListener('change', csSyncFields);

        csForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var errorBox = document.getElementById('cs_error');
            var submit = document.getElementById('cs_submit');
            var spinner = submit.querySelector('.spinner-border');

            errorBox.classList.add('d-none');
            clearFieldErrors(csForm);

            submit.disabled = true;
            if (spinner) { spinner.classList.remove('d-none'); }

            var target = document.getElementById('cs_target').value;
            var payload = {
                id: parseInt(document.getElementById('cs_id').value, 10),
                new_status: target,
                remarks: document.getElementById('cs_remarks').value
            };

            if (target === 'returned') {
                payload.actual_arrival_date = document.getElementById('cs_actual').value;
            }
            if (target === 'on_leave') {
                payload.expected_arrival_date = document.getElementById('cs_expected').value;
            }

            // No client-side rules beyond the field wiring above: the server
            // owns validity, so the message shown is the one the API enforces.
            postJson(changeStatusUrl(), payload)
                .then(function (result) {
                    if (!result.ok) {
                        errorBox.textContent = result.data.message || 'Could not change the status.';
                        errorBox.classList.remove('d-none');
                        showFieldErrors(csForm, result.data.errors);
                        return;
                    }

                    toast('success', result.data.message || 'Leave status changed.');
                    if (result.data.kpis) { applyKpis(result.data.kpis); }

                    var modal = window.bootstrap.Modal.getInstance(csModalEl);
                    if (modal) { modal.hide(); }

                    setTimeout(function () { window.rememberScroll(); window.location.reload(); }, 700);
                })
                .catch(function () {
                    errorBox.textContent = 'Network error. Please try again.';
                    errorBox.classList.remove('d-none');
                })
                .finally(function () {
                    submit.disabled = false;
                    if (spinner) { spinner.classList.add('d-none'); }
                });
        });
    }

    /* ------------------------------------------------- login reveal ----- */

    var togglePassword = document.getElementById('togglePassword');
    if (togglePassword) {
        togglePassword.addEventListener('click', function () {
            var input = document.getElementById('password');
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            togglePassword.innerHTML = showing
                ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
        });
    }

    /* ------------------------------------------------- date shortcuts --- */

    // Keep "expected return" always on/after "leave start" in the browser.
    // The leave application form has no leave start date - its anchor is the
    // last working day, which the server checks the same way (LWD on/before
    // expected return) - so falling back to it lets one rule cover both
    // screens, including the Duration field each of them renders.
    var startInput = document.getElementById('leave_start_date')
        || document.getElementById('last_working_date');
    var endInput = document.getElementById('expected_arrival_date');
    var durationInput = document.getElementById('daysAway');

    function syncLeaveDates() {
        if (startInput && endInput && startInput.value) {
            // Constrain the native picker so it will not offer an arrival
            // before the leave starts...
            endInput.min = startInput.value;

            // ...but never rewrite a value the user has already picked.
            // Overwriting it is what made the date change under their hands
            // whenever focus moved off another control. The server applies
            // the same rule and reports the conflict instead.
            var tooEarly = !!endInput.value && endInput.value < startInput.value;

            endInput.setCustomValidity(tooEarly
                ? 'Expected return date cannot be before the leave start date.'
                : '');
            endInput.classList.toggle('is-invalid', tooEarly);
        }

        if (durationInput && startInput && endInput) {
            if (startInput.value && endInput.value) {
                var a = new Date(startInput.value + 'T00:00:00');
                var b = new Date(endInput.value + 'T00:00:00');
                var days = Math.round((b - a) / 86400000);
                durationInput.value = days >= 0 ? (days + ' days') : '—';
            } else {
                // Expected return is optional on an application, so clearing
                // it must clear the duration too instead of leaving the old
                // figure on screen.
                durationInput.value = '—';
            }
        }
    }

    if (startInput) { startInput.addEventListener('change', syncLeaveDates); }
    if (endInput) { endInput.addEventListener('change', syncLeaveDates); }
    syncLeaveDates();
})();




/* ==========================================================================
 * Multi-select checkbox filters (Branch / Agency / Status / Nationality ...)
 * --------------------------------------------------------------------------
 * The choice travels in one hidden input as a comma-separated value, so the
 * server receives exactly what it always did for a single selection.
 * ======================================================================== */
(function () {
    document.querySelectorAll('[data-check-filter]').forEach(function (wrap) {
        var input = wrap.querySelector('[data-cf-input]');
        var label = wrap.querySelector('[data-cf-label]');
        var badge = wrap.querySelector('[data-cf-count]');
        var boxes = Array.prototype.slice.call(wrap.querySelectorAll('input[type="checkbox"]'));
        var all   = wrap.querySelector('[data-cf-all]');
        var none  = wrap.querySelector('[data-cf-none]');

        if (!input || !label || boxes.length === 0) {
            return;
        }

        var placeholder = label.getAttribute('data-cf-placeholder') || 'All';
        var texts = {};

        boxes.forEach(function (box) {
            var span = box.parentNode ? box.parentNode.querySelector('span') : null;
            texts[box.value] = span && span.textContent ? span.textContent.trim() : box.value;
        });

        function sync() {
            var picked = boxes.filter(function (box) { return box.checked; });

            input.value = picked.map(function (box) { return box.value; }).join(',');

            if (badge) {
                badge.textContent = String(picked.length);
                badge.hidden = picked.length === 0;
            }

            if (picked.length === 0) {
                label.textContent = placeholder;
            } else if (picked.length === 1) {
                label.textContent = texts[picked[0].value] || picked[0].value;
            } else {
                label.textContent = picked.length + ' selected';
            }
        }

        boxes.forEach(function (box) {
            box.addEventListener('change', sync);
        });

        if (all) {
            all.addEventListener('click', function (event) {
                event.preventDefault();
                boxes.forEach(function (box) { box.checked = true; });
                sync();
            });
        }

        if (none) {
            none.addEventListener('click', function (event) {
                event.preventDefault();
                boxes.forEach(function (box) { box.checked = false; });
                sync();
            });
        }

        sync();
    });
})();


/* Typed confirmation for the destructive "restore seeded state" button on
   Settings > System: the submit stays disabled until RESET is typed exactly.
   The controller re-checks it server side - this only prevents a slip. */
(function () {
    'use strict';

    document.querySelectorAll('[data-reset-confirm]').forEach(function (input) {
        var form = input.closest('form');
        var submit = form ? form.querySelector('[data-reset-submit]') : null;

        if (!submit) {
            return;
        }

        // Each modal declares its own word (RESET / DELETE) via data-confirm-word.
        var word = (input.getAttribute('data-confirm-word') || 'RESET').toUpperCase();

        var sync = function () {
            submit.disabled = input.value.trim().toUpperCase() !== word;
        };

        input.addEventListener('input', sync);
        input.addEventListener('change', sync);
        sync();

        var modal = input.closest('.modal');

        if (modal) {
            modal.addEventListener('hidden.bs.modal', function () {
                input.value = '';
                sync();
            });
        }
    });
})();


/* ------------------------------------------------- bulk row selection --- */
/* Checkbox grids (Address Book / Leave Records): select-all, a live count on
   the submit button and a confirm message that quotes the real selection.
   The grid is still a plain POST, so it works without JS - the button simply
   stays disabled until something is ticked. */
(function () {
    'use strict';

    document.querySelectorAll('[data-bulk-select]').forEach(function (form) {
        // The tick boxes sit OUTSIDE the form (a <form> inside a <form> is
        // dropped by HTML parsers), so they are linked with form="<id>" and
        // matched by that attribute rather than by being descendants.
        var boxes = form.id
            ? document.querySelectorAll('[data-row-check][form="' + form.id + '"]')
            : form.querySelectorAll('[data-row-check]');
        var all = document.querySelector('[data-check-all][form="' + form.id + '"]')
            || document.querySelector('[data-check-all]');
        var btn = form.querySelector('[data-bulk-submit]');
        var out = form.querySelector('[data-bulk-count]');

        if (!boxes.length || !btn) { return; }

        // The confirmation sits on the SUBMIT BUTTON, never on the form: the
        // form wraps the whole table, so a data-confirm there fired on every
        // click anywhere in the grid. Captured once, before we rewrite it.
        var template = btn.getAttribute('data-confirm') || 'Delete the selected items?';

        function sync() {
            var n = 0;

            boxes.forEach(function (box) {
                if (box.checked) { n++; }
            });

            if (out) { out.textContent = String(n); }
            if (btn) { btn.disabled = n === 0; }

            if (all) {
                all.checked = n === boxes.length;
                all.indeterminate = n > 0 && n < boxes.length;
            }

            if (btn) {
                btn.setAttribute('data-confirm', template + ' (' + n + ' selected)');
            }
        }

        if (all) {
            all.addEventListener('change', function () {
                boxes.forEach(function (box) { box.checked = all.checked; });
                sync();
            });
        }

        boxes.forEach(function (box) {
            box.addEventListener('change', sync);
        });

        sync();
    });
})();

/* ------------------------------------------------------- scroll memory --- */
// Filter dropdowns auto-submit and the AJAX actions call location.reload().
// Both replace the document, and a fresh document always starts at scroll
// offset 0 - which is what made every action look like it "jumped to the top".
// Stash the offset just before navigating and put it back on the next paint.

var LMS_SCROLL_KEY = 'lms:scrollY';

(function () {
    'use strict';

    var raw = sessionStorage.getItem(LMS_SCROLL_KEY);

    if (raw === null) {
        return;
    }

    sessionStorage.removeItem(LMS_SCROLL_KEY);

    try {
        var saved = JSON.parse(raw);
        var stale = Date.now() - (saved.t || 0) > 15000;

        // Only put the offset back on the screen it came from. Paging and
        // filtering change the query string but not the path, so those restore
        // while arriving at a different screen starts at the top.
        var sameScreen = !saved.p || saved.p === window.location.pathname;

        if (!saved || !(saved.y > 0) || stale || !sameScreen) {
            return;
        }

        var restore = function () { window.scrollTo(0, saved.y); };

        // Once after layout, and once when everything has finished loading -
        // tall tables can change height, which would clamp an early scroll.
        window.requestAnimationFrame(restore);
        window.addEventListener('load', restore);
    } catch (e) {
        // Corrupt entry: drop it and carry on at the top.
    }
})();

window.rememberScroll = function () {
    try {
        sessionStorage.setItem(LMS_SCROLL_KEY, JSON.stringify({
            y: Math.round(window.scrollY || window.pageYOffset || 0),
            t: Date.now(),
            p: window.location.pathname,
        }));
    } catch (e) {
        // Storage unavailable (private mode/quota) - degrade to top of page.
    }
};

/* --------------------------------------------------- scroll on paging --- */
// Page links, sort headers and the filter form are plain navigations: the new
// document always starts at offset 0, which is why "next page" threw the
// viewport back to the top. Stash the offset whenever the destination stays
// on the same screen (same path, different query) and the restore above puts
// it back. Navigating to another screen deliberately stores nothing, so a
// profile or a settings page never inherits the previous scroll.

(function () {
    'use strict';

    function sameScreen(href) {
        try {
            return new URL(href, window.location.href).pathname === window.location.pathname;
        } catch (e) {
            return false;
        }
    }

    function usableLink(link) {
        if (!link) { return false; }

        var href = link.getAttribute('href');

        if (!href || href.charAt(0) === '#') { return false; }
        if (link.hasAttribute('download')) { return false; }
        if (link.target && link.target !== '_self') { return false; }

        return sameScreen(href);
    }

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0) { return; }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }

        var link = event.target.closest('a[href]');

        if (usableLink(link)) {
            window.rememberScroll();
        }
    });

    // The filter bar and the pager submit GET forms back to the same URL.
    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!form || form.tagName !== 'FORM') { return; }
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get') { return; }

        var action = form.getAttribute('action') || window.location.pathname;

        if (sameScreen(action)) {
            window.rememberScroll();
        }
    });
    /* -------------------------------------------- deactivate driver ---- */
    // The Deactivate action asks for the Last Working Date before it writes
    // anything (automated deactivation rules). The trigger is a button, not a
    // data-confirm form, because a confirmation dialog has nowhere to put a
    // required date - so this modal carries the form itself and posts back to
    // the same server endpoint the old confirm flow used.
    var ddModalEl = document.getElementById('deactivateDriverModal');
    var ddForm    = document.getElementById('deactivateDriverForm');
    var ddError   = document.getElementById('dd_error');

    if (ddModalEl && ddForm) {
        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-action="deactivate-driver"]');

            if (!trigger || !ddModalEl) { return; }

            event.preventDefault();

            var today = new Date();
            var iso   = today.getFullYear() + '-'
                + (today.getMonth() + 1 < 10 ? '0' : '') + (today.getMonth() + 1) + '-'
                + (today.getDate() < 10 ? '0' : '') + today.getDate();

            document.getElementById('dd_driver').textContent = trigger.getAttribute('data-driver') || '—';
            document.getElementById('dd_badge').textContent  = trigger.getAttribute('data-badge') || '—';
            document.getElementById('dd_return_to').value    = trigger.getAttribute('data-return') || '';
            document.getElementById('dd_lwd').value          = trigger.getAttribute('data-lwd') || iso;

            if (ddError) { ddError.classList.add('d-none'); }

            ddForm.setAttribute('action', trigger.getAttribute('data-url') || '');

            window.bootstrap.Modal.getOrCreateInstance(ddModalEl).show();
        });

        ddForm.addEventListener('submit', function (event) {
            var lwd = document.getElementById('dd_lwd').value;

            if (!lwd) {
                event.preventDefault();

                if (ddError) {
                    ddError.textContent = 'Enter the last working date - the last day this driver worked.';
                    ddError.classList.remove('d-none');
                }
            }
        });
    }



    /* ---------------------------------------- sync mobile from SIMLedger --- */

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-action="sync-simledger"]');
        if (!trigger) { return; }

        event.preventDefault();

        var url = trigger.getAttribute('data-url');
        if (!url || !window.FL || !FL.postJson) { return; }

        var original = trigger.innerHTML;
        trigger.disabled = true;
        trigger.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Syncing...';

        FL.postJson(url, {
            id: parseInt(trigger.getAttribute('data-driver-id'), 10) || 0
        }).then(function (r) {
            trigger.disabled = false;
            trigger.innerHTML = original;

            var d = (r && r.data) || {};
            var ok = !!(r && r.ok && d.ok);

            FL.toast(ok ? 'success' : 'danger', d.message || 'SIMLedger sync failed.');

            if (ok && d.mobile_number) {
                var dd = document.getElementById('driverMobile');
                if (dd) {
                    dd.innerHTML = '<a href="tel:' + FL.escapeHtml(d.mobile_number) + '" dir="ltr">'
                        + FL.escapeHtml(d.mobile_number) + '</a>';
                }
            }
        }).catch(function () {
            trigger.disabled = false;
            trigger.innerHTML = original;
            FL.toast('danger', 'SIMLedger sync failed (network error).');
        });
    });


    /* ------------------------------- sync ALL mobiles from SIMLedger ------- */

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-action="sync-simledger-all"]');
        if (!trigger) { return; }

        event.preventDefault();

        var url = trigger.getAttribute('data-url');
        if (!url || !window.FL || !FL.postJson) { return; }

        var really = window.confirm(
            "Sync ALL drivers' mobile numbers from SIMLedger?\n\n"
            + 'One roster fetch, then every driver with a badge is checked. '
            + 'Only numbers that differ are updated.'
        );
        if (!really) { return; }

        var original = trigger.innerHTML;
        trigger.disabled = true;
        trigger.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Syncing all...';

        FL.postJson(url, {}).then(function (r) {
            trigger.disabled = false;
            trigger.innerHTML = original;

            var d = (r && r.data) || {};
            var ok = !!(r && r.ok && d.ok);

            if (ok) {
                FL.toast('success', d.message || ('SIMLedger sync: ' + d.changed + ' updated.'));
            } else {
                FL.toast('danger', d.message || 'SIMLedger sync failed.');
            }
        }).catch(function () {
            trigger.disabled = false;
            trigger.innerHTML = original;
            FL.toast('danger', 'SIMLedger sync failed (network error).');
        });
    });
})();

