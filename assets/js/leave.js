/**
 * New Leave / Vacation form helpers:
 *   - driver type-ahead (AJAX search + detail card)
 *   - live overlap check against existing leave records
 *
 * The form still posts normally to PHP; these are enhancements only, so the
 * screen keeps working with JavaScript disabled (minus search/preview).
 */
(function () {
    'use strict';

    var urls = window.LEAVE_URLS || {};

    /* ------------------------------------------------ no-return types --- */
    /**
     * Resignation, Terminated and Absconded leave types mean the driver never
     * comes back, so both forms drop the three questions that only describe a
     * return journey - Ticket Date, Expected Return Date and Duration - while
     * one of them is selected. The inputs are disabled as well as hidden, so
     * their values cannot be posted.
     *
     * Presentation only: LeaveApplication::NO_RETURN_PATTERNS is the same rule
     * server side, and both controllers apply it, so the screen still ends up
     * correct with JavaScript off or with a hand-crafted POST.
     */
    var typeSelect   = document.getElementById('leave_type_id');
    var arrivalInput = document.getElementById('expected_arrival_date');
    var ticketInput  = document.getElementById('ticket_date');
    // All three describe a return journey: the ticket, the arrival date and
    // the Duration worked out from that date. Each may be marked on its own,
    // and #expectedArrivalWrap is named directly so a form is covered whether
    // or not it marks its wrapper.
    var returnWraps  = document.querySelectorAll('[data-no-return-wrap], #expectedArrivalWrap');

    if (typeSelect && returnWraps.length) {
        var NO_RETURN_TYPE = /resign|terminat|abscond/i;

        var syncReturnType = function () {
            var option   = typeSelect.options[typeSelect.selectedIndex];
            var label    = option ? String(option.textContent || '').trim() : '';
            var noReturn = NO_RETURN_TYPE.test(label);

            returnWraps.forEach(function (wrap) {
                wrap.classList.toggle('d-none', noReturn);
            });

            // Disabled inputs are neither validated nor posted, which is what
            // keeps a date picked before the switch from surviving the round
            // trip - and what lets the record save at all, because the server
            // only requires an expected return from a type that makes one.
            [arrivalInput, ticketInput].forEach(function (input) {
                if (!input) { return; }

                input.disabled = noReturn;

                if (noReturn) {
                    input.value = '';
                }
            });

            // The Duration is derived from those two dates, so an emptied
            // arrival date must not leave a stale day count behind.
            var daysAway = document.getElementById('daysAway');
            if (daysAway && noReturn) {
                daysAway.value = '';
            }
        };

        typeSelect.addEventListener('change', syncReturnType);
        syncReturnType();
    }

    /**
     * app.js defines window.FL and the layout loads it *after* the page
     * content, so it is not guaranteed to exist when this file parses.
     * Resolving it per call (with a self-contained fallback) matters: caching
     * it once captured undefined, so renderResults threw and the type-ahead
     * never displayed - leaving driver_id empty and the submit looking dead.
     */
    function esc(value) {
        var api = window.FL;

        if (api && api.escapeHtml) { return api.escapeHtml(value); }

        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtDate(iso) {
        var api = window.FL;

        return (api && api.formatDate) ? api.formatDate(iso) : String(iso || '');
    }

    function toast(kind, message) {
        var api = window.FL;

        if (api && api.toast) { api.toast(kind, message); }
    }

    /* -------------------------------------------------- driver search --- */

    var picker = document.getElementById('driverPicker');
    var results = document.getElementById('driverResults');
    var hiddenId = document.getElementById('driver_id');
    var card = document.getElementById('driverCard');

    if (!picker || !results) { return; }

    var debounce = null;
    var activeIndex = -1;
    var currentRows = [];

    function hideResults() {
        results.classList.remove('show');
        results.innerHTML = '';
        activeIndex = -1;
        currentRows = [];
    }

    function renderResults(rows) {
        currentRows = rows;

        if (!rows.length) {
            results.innerHTML = '<div class="dropdown-item-text small text-body-secondary">'
                + 'No matching drivers.</div>';
            results.classList.add('show');
            return;
        }

        results.innerHTML = rows.map(function (row, index) {
            return '<button type="button" class="dropdown-item text-start py-2" data-index="' + index + '">'
                + '<div class="fw-semibold">' + esc(row.driver_name) + '</div>'
                + '<div class="small text-body-secondary">'
                + esc(row.badge_number)
                + ' · Iqama ' + esc(row.iqama_number)
                + (row.mobile_number ? ' · ' + esc(row.mobile_number) : '')
                + (row.branch ? ' · ' + esc(row.branch) : '')
                + '</div></button>';
        }).join('');

        results.classList.add('show');
        activeIndex = -1;
    }

    function search(term) {
        if (!urls.search) { return; }

        fetch(urls.search + '?q=' + encodeURIComponent(term) + '&limit=12',
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                renderResults((data && data.rows) || []);
            })
            .catch(function () {
                hideResults();
            });
    }

    picker.addEventListener('input', function () {
        hiddenId.value = '';
        resetCard();
        clearPickerError();

        var term = picker.value.trim();
        window.clearTimeout(debounce);

        if (term.length < 2) {
            hideResults();
            return;
        }

        debounce = window.setTimeout(function () { search(term); }, 220);
    });

    picker.addEventListener('keydown', function (event) {
        if (!results.classList.contains('show')) { return; }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            activeIndex = Math.min(activeIndex + 1, currentRows.length - 1);
            highlight();
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
            highlight();
        } else if (event.key === 'Enter' && activeIndex >= 0) {
            event.preventDefault();
            pick(currentRows[activeIndex]);
        } else if (event.key === 'Escape') {
            hideResults();
        }
    });

    function highlight() {
        var items = results.querySelectorAll('.dropdown-item');
        items.forEach(function (item, index) {
            item.classList.toggle('active', index === activeIndex);
        });
    }

    results.addEventListener('click', function (event) {
        var button = event.target.closest('[data-index]');
        if (!button) { return; }
        pick(currentRows[parseInt(button.getAttribute('data-index'), 10)]);
    });

    function pick(row) {
        if (!row) { return; }

        hiddenId.value = row.id;
        // Tell the rest of the page (the application picker) that the driver
        // changed: a link picked for the previous driver must not survive.
        hiddenId.dispatchEvent(new Event('change'));
        picker.value = row.driver_name + ' (' + row.badge_number + ')';
        hideResults();
        clearPickerError();

        loadDetail(row.id);
        checkOverlap();
    }

    document.addEventListener('click', function (event) {
        if (!results.classList.contains('show')) { return; }
        if (results.contains(event.target) || picker === event.target) { return; }
        hideResults();
    });

    var clearBtn = document.getElementById('clearDriver');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            hiddenId.value = '';
            picker.value = '';
            picker.readOnly = false;
            picker.focus();
            resetCard();
            clearPickerError();
        });
    }

    /* --------------------------------------------------- driver card ---- */

    // id -> key on the payload returned by /api/drivers/detail
    var CARD_FIELDS = [
        ['dc_name', 'driver_name'],
        ['dc_badge', 'badge_number'],
        ['dc_iqama', 'iqama_number'],
        ['dc_passport', 'passport_number'],
        ['dc_nationality', 'nationality'],
        ['dc_position', 'position'],
        ['dc_agency', 'agency'],
        ['dc_branch', 'branch'],
        ['dc_mobile', 'mobile_number'],
        ['dc_status', 'status']
    ];

    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) { el.textContent = value ? String(value) : '—'; }
    }

    /** Back to placeholders: no driver picked (or the search box was cleared). */
    function resetCard() {
        CARD_FIELDS.forEach(function (pair) { setText(pair[0], ''); });

        var hint = document.getElementById('dc_hint');
        if (hint) { hint.classList.remove('d-none'); }

        setState('Not selected', false);
    }

    function setState(label, selected) {
        var state = document.getElementById('dc_state');
        if (!state) { return; }

        state.textContent = label;
        state.classList.toggle('text-bg-success', selected);
        state.classList.toggle('text-bg-secondary', !selected);
    }

    /**
     * The hidden driver_id input can't show an invalid state, so the message
     * is surfaced on the visible search box instead - otherwise submitting
     * with no driver picked looks like a dead button.
     */
    function setPickerError(on) {
        var msg = document.getElementById('driverPickerError');

        if (picker) { picker.classList.toggle('is-invalid', on); }
        if (msg) { msg.hidden = !on; }
    }

    function clearPickerError() {
        setPickerError(false);
    }

    function loadDetail(id) {
        if (!urls.detail || !card) { return; }

        fetch(urls.detail + '?id=' + encodeURIComponent(id))
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.ok) { return; }

                var driver = data.driver;
                CARD_FIELDS.forEach(function (pair) {
                    setText(pair[0], driver[pair[1]]);
                });

                // Prefill the snapshot inputs with this driver's current
                // passport/branch. They stay editable: whatever is submitted
                // is what gets frozen onto the record.
                var passInput = document.getElementById('passport_number');
                if (passInput) { passInput.value = driver.passport_number || ''; }

                var branchSelect = document.getElementById('branch_id');
                if (branchSelect) {
                    branchSelect.value = driver.branch_id ? String(driver.branch_id) : '';
                }

                var hint = document.getElementById('dc_hint');
                if (hint) { hint.classList.add('d-none'); }

                setState('Selected', true);
            })
            .catch(function () { /* placeholders stay until a retry */ });
    }

    /* ------------------------------------------------ overlap check ----- */

    var startEl = document.getElementById('leave_start_date');
    var endEl = document.getElementById('expected_arrival_date');
    var warning = document.getElementById('overlapWarning');
    var warningText = document.getElementById('overlapText');
    var form = document.getElementById('leaveForm');

    var overlapTimer = null;

    function editId() {
        if (!form) { return 0; }
        return parseInt(form.getAttribute('data-edit-id') || '0', 10) || 0;
    }

    function checkOverlap() {
        if (!urls.overlap || !warning || !warningText) { return; }
        if (!hiddenId.value || !startEl || !endEl) { return; }
        if (!startEl.value || !endEl.value) { return; }

        warning.classList.add('d-none');
        warningText.textContent = '';

        var params = 'driver_id=' + encodeURIComponent(hiddenId.value)
            + '&start=' + encodeURIComponent(startEl.value)
            + '&end=' + encodeURIComponent(endEl.value)
            + '&id=' + editId();

        fetch(urls.overlap + '?' + params)
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.ok || !data.overlap) { return; }

                warningText.textContent = 'This driver already has leave from '
                    + fmtDate(data.overlap.start) + ' to ' + fmtDate(data.overlap.end)
                    + ' (' + data.overlap.leave_type + '). Adjust the dates or open the '
                    + 'existing record.';
                warning.classList.remove('d-none');
            })
            .catch(function () { /* server still validates on submit */ });
    }

    function queueOverlapCheck() {
        window.clearTimeout(overlapTimer);
        overlapTimer = window.setTimeout(checkOverlap, 350);
    }

    if (startEl) { startEl.addEventListener('change', queueOverlapCheck); }
    if (endEl) { endEl.addEventListener('change', queueOverlapCheck); }
    if (hiddenId) {
        // Fires after a driver is picked from the type-ahead.
        hiddenId.addEventListener('change', queueOverlapCheck);
    }

    /* ------------------------------------------------ client validation - */

    if (form) {
        form.addEventListener('submit', function (event) {
            var valid = true;

            // Required fields are read from the markup, not a hardcoded list.
            // New Leave / Vacation and New Leave Application do not demand the
            // same dates - the application's Expected Return is optional - and
            // hardcoding it here flagged a perfectly valid submission as
            // invalid (red border + Bootstrap's "!" badge) and blocked it.
            // driver_id is a hidden input carrying no `required`, so it is
            // still handled separately below via setPickerError().
            // DISABLED inputs are skipped, matching what the browser does:
            // leave.js disables what it hides for a no-return leave type, so
            // that field is empty AND never posted. Treating it as missing
            // made every Resignation / Terminated / Absconded submission
            // fail - and the toast then blamed the driver picker, which was
            // perfectly fine.
            Array.prototype.forEach.call(form.querySelectorAll('[required]'), function (input) {
                if (input.disabled) { return; }

                input.classList.remove('is-invalid');

                if (!String(input.value).trim()) {
                    input.classList.add('is-invalid');
                    valid = false;
                }
            });

            var start = startEl ? startEl.value : '';
            var end = endEl ? endEl.value : '';

            if (start && end && end < start) {
                endEl.classList.add('is-invalid');
                valid = false;
            }

            // driver_id lives in a hidden input, which cannot display an
            // invalid state - flag the visible search box instead so the
            // submit does not look like it silently did nothing.
            var driverIdInput = form.querySelector('[name="driver_id"]');

            if (driverIdInput && !String(driverIdInput.value).trim()) {
                setPickerError(true);
                valid = false;
            } else {
                clearPickerError();
            }

            // A new leave is filed against an approved application, so on the
            // CREATE screen the hidden link must not be empty. Editing stays
            // open: a legacy record that never had an application is still
            // correctable, and its link may be cleared. The server enforces
            // the same rule - this only stops the click looking ignored.
            var linkInput   = document.getElementById('leave_application_id');
            var requiredMsg = document.getElementById('applicationRequired');
            var missingLink = editId() === 0 && linkInput
                && !String(linkInput.value || '').trim();

            if (requiredMsg) {
                requiredMsg.classList[missingLink ? 'add' : 'remove']('d-block');
            }

            if (missingLink) {
                valid = false;
            }

            if (!valid) {
                event.preventDefault();

                // Name the field that is actually wrong. The old message
                // always blamed the driver picker, so a missing Leave Start
                // Date (or a hidden Expected Return) sent the user chasing a
                // picker that was perfectly fine.
                var pickerIsBad = picker && picker.classList.contains('is-invalid');

                if (pickerIsBad) {
                    picker.focus();
                    toast('warning',
                        'Choose a driver from the search results and complete the highlighted fields.');
                    return;
                }

                if (missingLink) {
                    var appPicker = document.getElementById('applicationPicker');

                    if (appPicker) { appPicker.focus(); }
                    toast('warning',
                        'Link the approved application this leave was filed under.');
                    return;
                }

                var first = form.querySelector('.is-invalid');
                var labelText = '';

                if (first && first.id) {
                    var lab = form.querySelector('label[for="' + first.id + '"]');
                    labelText = lab ? lab.textContent.replace(/[*:]/g, '').trim() : '';
                }

                toast('warning', labelText
                    ? 'Complete the highlighted field: ' + labelText + '.'
                    : 'Complete the highlighted fields.');
            }
        });
    }

    // Pre-filled entry point: "Add Leave" on the Address Book / driver profile
    // (and a re-rendered form after a failed submit) arrive with driver_id set,
    // so query the details panel straight away instead of showing placeholders.
    // Declared last so CARD_FIELDS above is already assigned when it runs.
    if (hiddenId && hiddenId.value) {
        loadDetail(parseInt(hiddenId.value, 10));
    }

    // Re-run the overlap check once on load (edit screen / prefilled form).
    queueOverlapCheck();
})();


/* --------------------------------------------- application picker ------ */
// Linking the record to the approved application it was applied for
// (requirements 1-2). Enhancement only: the form always posts the hidden
// leave_application_id field, so with JavaScript off the link survives an
// edit and ?application_id= still prefills on the way in.
(function () {
    'use strict';

    var urls    = window.LEAVE_URLS || {};
    var picker  = document.getElementById('applicationPicker');
    var results = document.getElementById('applicationResults');
    var hidden  = document.getElementById('leave_application_id');
    var search  = document.getElementById('applicationSearch');
    var error   = document.getElementById('applicationError');
    // The create screen gives the hidden driver field an id; the edit screen
    // used to give it only a name. Fall back to a name search so the picker
    // also works while editing an existing record - which is where a legacy
    // leave picks up its application.
    var driver  = document.getElementById('driver_id')
        || document.querySelector('input[name="driver_id"]');

    /** The driver the form is posting for, whatever field carries it. */
    function driverValue() {
        var field = document.getElementById('driver_id')
            || document.querySelector('input[name="driver_id"]');

        return field ? String(field.value || '') : '';
    }

    if (!picker || !results || !hidden) { return; }

    var chip     = document.getElementById('applicationChip');
    var chipTx   = document.getElementById('applicationChipText');
    var debounce = null;
    var rows     = [];

    function esc(value) {
        var api = window.FL;

        if (api && api.escapeHtml) { return api.escapeHtml(value); }

        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function hideResults() {
        results.classList.remove('show');
        results.innerHTML = '';
        rows = [];
    }

    function hideError() {
        if (error) { error.hidden = true; }
    }

    function ensureChip() {
        if (chip) { return; }

        chip = document.createElement('div');
        chip.className = 'alert alert-info d-flex align-items-center gap-2 mb-0 py-2 small';
        chip.id = 'applicationChip';
        chip.innerHTML = '<i class="bi bi-link-45deg"></i><strong id="applicationChipText"></strong>';

        (search || picker.parentNode).appendChild(chip);
        chipTx = document.getElementById('applicationChipText');
    }

    function showChip(app) {
        ensureChip();

        if (chipTx) {
            chipTx.textContent = 'Application #' + app.id + ' · ' + app.leave_type
                + ' · applied ' + app.application_date;
        }

        chip.classList.remove('d-none');
    }

    function hideChip() {
        if (chip) { chip.classList.add('d-none'); }
    }

    // Picking an application offers its details as a starting point. Fields
    // the user already filled in are left alone - the picker suggests, the
    // user decides.
    function fillIfEmpty(id, value) {
        var el = document.getElementById(id);

        if (!el || value === null || value === undefined || value === '') { return; }
        if (String(el.value || '') !== '') { return; }

        if (el.tagName === 'SELECT') {
            el.value = String(value);

            if (el.value !== String(value)) { el.value = ''; }

            return;
        }

        el.value = value;
    }

    /**
     * Destination is a country <select>, and an application can hold a
     * destination the current Settings list does not carry - filed before the
     * list existed, or trimmed since. fillIfEmpty() leaves such a value on
     * "Not set", which is right for a leave type (an unknown one must not be
     * picked) and wrong here, where it would drop what the application says.
     * Add the missing option instead, exactly as the server-rendered edit
     * form does when it re-displays a stored value.
     */
    function fillDestination(value) {
        var el = document.getElementById('destination');

        if (!el || el.tagName !== 'SELECT' || !value) { return; }
        if (String(el.value || '') !== '') { return; }

        el.value = String(value);

        if (el.value === String(value)) { return; }

        el.appendChild(new Option(String(value), String(value)));
        el.value = String(value);
    }

    function prefill(app) {
        fillIfEmpty('leave_type_id', app.leave_type_id);
        fillIfEmpty('last_working_date', app.last_working_date);
        fillIfEmpty('expected_arrival_date', app.expected_arrival_date);
        fillDestination(app.destination);
        fillIfEmpty('reason', app.reason);
        fillIfEmpty('remarks', app.remarks);
    }

    /** The link message under the picker only matters while nothing is linked. */
    function hideRequired() {
        var msg = document.getElementById('applicationRequired');

        if (msg) { msg.classList.remove('d-block'); }
    }

    /**
     * Branch is the one field the APPLICATION decides rather than suggests:
     * it is the branch the leave was filed under, snapshotted on the row, and
     * loadDetail() has already put the driver's current branch in the select.
     * Filling only when empty would therefore never let it through - and a
     * branch is not something a filing is retyped for, so it is set outright
     * (still editable afterwards).
     */
    function fillBranch(branchId) {
        if (!branchId) { return; }

        var el = document.getElementById('branch_id');

        if (!el || el.tagName !== 'SELECT') { return; }

        var previous = el.value;

        el.value = String(branchId);

        // A branch the branches table no longer lists: keep what was there
        // rather than blanking the form's own value.
        if (el.value !== String(branchId)) {
            el.value = previous;
        }
    }

    function select(app) {
        hidden.value = String(app.id);
        picker.value = 'Application #' + app.id + ' · ' + app.leave_type;

        showChip(app);
        hideResults();
        hideError();
        hideRequired();
        prefill(app);
        fillBranch(app.branch_id);
    }

    function reset() {
        hidden.value = '';
        picker.value = '';
        hideChip();
        hideResults();
        hideError();
        hideRequired();
    }


    function renderRows(list) {
        rows = list;

        if (!list.length) {
            results.innerHTML = '<div class="dropdown-item-text small text-body-secondary">'
                + 'No approved application is waiting for this driver.</div>';
            results.classList.add('show');
            return;
        }

        results.innerHTML = list.map(function (app, index) {
            return '<button type="button" class="dropdown-item text-start" data-index="' + index + '">'
                + '<strong>#' + esc(app.id) + '</strong> · ' + esc(app.leave_type)
                + '<div class="small text-body-secondary">Applied ' + esc(app.application_date)
                + (app.expected_arrival_date ? ' · back ' + esc(app.expected_arrival_date) : '')
                + (app.destination ? ' · ' + esc(app.destination) : '')
                + '</div></button>';
        }).join('');

        results.classList.add('show');
    }

    function load(term) {
        if (!urls.openApplications) { return; }

        var driverId = driverValue();

        if (!driverId) {
            results.innerHTML = '<div class="dropdown-item-text small text-body-secondary">'
                + 'Choose a driver first - applications belong to one driver.</div>';
            results.classList.add('show');
            return;
        }

        fetch(urls.openApplications + '?driver_id=' + encodeURIComponent(driverId)
                + '&q=' + encodeURIComponent(term || ''),
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                renderRows((data && data.applications) || []);
            })
            .catch(function () {
                hideResults();
            });
    }

    picker.addEventListener('input', function () {
        window.clearTimeout(debounce);

        debounce = window.setTimeout(function () {
            load(picker.value);
        }, 200);
    });

    picker.addEventListener('focus', function () {
        load(picker.value);
    });

    results.addEventListener('click', function (event) {
        var item = event.target.closest('[data-index]');

        if (!item) { return; }

        var app = rows[parseInt(item.getAttribute('data-index'), 10)];

        if (app) { select(app); }
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('#applicationSearch')) { hideResults(); }
    });

    // Changing the driver invalidates the link: an application belongs to the
    // driver it was filed for, never to the new one.
    if (driver) {
        driver.addEventListener('change', function () { reset(); });
    }

    var clearChip = document.getElementById('clearApplication');

    if (clearChip) {
        clearChip.addEventListener('click', function () { reset(); });
    }

    var clearInput = document.getElementById('clearApplication2');

    if (clearInput) {
        clearInput.addEventListener('click', function () { reset(); });
    }
})();

