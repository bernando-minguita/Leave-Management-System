/**
 * Driver Dashboard - rendering only (charts, table, filters, details).
 *
 * Every number arrives from PHP in window.DRIVER_DASHBOARD_DATA, already
 * classified: each row carries its op_status, so filtering, sorting and paging
 * here can never disagree with the KPI cards above (spec sections 15 and 16).
 * Follows the dashboard.js / capacity.js conventions: one IIFE, no build step,
 * Chart.js already vendored, FL helpers from app.js when present.
 */
(function () {
    'use strict';

    var data = window.DRIVER_DASHBOARD_DATA || {};
    var rows = data.rows || [];

    var CATEGORIES = data.categories || ['active', 'on_leave', 'idle', 'gosi', 'other'];
    var LABELS = data.labels || {};
    var TONES = data.tones || {};
    var COLOURS = data.colours || {};
    // The dropdown's value => label, shipped by PHP. The browser filters on
    // what the column SHOWS (the status override can make a blank flag read
    // "Sick / GOSI"), so it has to be able to turn a slug back into the
    // label - exactly as filterRows() does on the server.
    var OPTION_LABELS = data.statusOptions || {};

    function esc(value) {
        if (window.FL && typeof FL.escapeHtml === 'function') {
            return FL.escapeHtml(String(value === null || value === undefined ? '' : value));
        }
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /** Y-m-d -> d M Y; a blank date reads as an em dash, never as 0000-00-00. */
    function fmtDate(iso) {
        if (!iso) { return '&mdash;'; }
        if (window.FL && typeof FL.formatDate === 'function') {
            return esc(FL.formatDate(iso));
        }
        var p = String(iso).split('-');
        if (p.length !== 3) { return esc(iso); }
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
            'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return parseInt(p[2], 10) + ' ' + months[parseInt(p[1], 10) - 1] + ' ' + p[0];
    }

    function text(value) {
        var s = String(value === null || value === undefined ? '' : value).trim();
        return s === '' ? '&mdash;' : esc(s);
    }

    /* --------------------------------------------------------------- state -- */

    var initial = data.initial || {};

    var state = {
        // Two status keys, deliberately. op_status is the availability
        // BUCKET the five KPI cards and the two charts count; the
        // operational_status flag is what the dropdown offers from Settings.
        // Sharing one key is what made that dropdown five buckets with four
        // of them unusable.
        op_status:          initial.op_status || '',
        operational_status: initial.operational_status || '',
        branch_id:          initial.branch_id || '',
        q:                  initial.q || '',
        sort:       'driver_name',
        dir:        1,          // 1 ascending, -1 descending
        page:       1,
        size:       10
    };

    var el = function (id) { return document.getElementById(id); };

    /**
     * The browser half of DriverDashboardService::filterRows(). The four keys
     * - op_status, operational_status, branch_id and q - are the same four in
     * the same order in both files, so an export and the screen can never
     * show a different set of drivers (spec section 18).
     *
     * __unset__ is the dropdown's "Not set": '' already means "no filter",
     * and most drivers have no flag at all.
     */
    function matches(row) {
        if (state.op_status !== '' && row.op_status !== state.op_status) {
            return false;
        }

        if (state.operational_status !== '') {
            // Match what the column SHOWS, not what is stored - the status
            // override can rename a blank flag to "Sick / GOSI", "On Leave"
            // or "Exit". filterRows() does the same thing, so an export and
            // the screen cannot show different sets of drivers (spec 18).
            var want = state.operational_status === '__unset__'
                ? '—'
                : (OPTION_LABELS[state.operational_status] || state.operational_status);
            if ((row.op_flag || '') !== want) { return false; }
        }

        if (state.branch_id !== '') {
            var id = row.branch_id === null || row.branch_id === undefined
                ? '0' : String(row.branch_id);
            if (id !== state.branch_id) { return false; }
        }

        if (state.q !== '') {
            var haystack = (String(row.badge_number || '') + ' '
                + String(row.driver_name || '') + ' '
                + String(row.nationality || '')).toLowerCase();
            if (haystack.indexOf(state.q) === -1) { return false; }
        }

        return true;
    }

    function visibleRows() {
        return rows.filter(matches);
    }

    /** Column sort. Text compares case-insensitively; dates compare as dates. */
    function sortRows(list) {
        var key = state.sort;
        var dir = state.dir;

        return list.slice().sort(function (a, b) {
            var av = a[key];
            var bv = b[key];

            if (key === 'expected_return') {
                av = av || '';
                bv = bv || '';
                return av === bv ? 0 : (av < bv ? -dir : dir);
            }

            if (typeof av === 'number' || typeof bv === 'number') {
                av = Number(av || 0);
                bv = Number(bv || 0);
                return av === bv ? 0 : (av < bv ? -dir : dir);
            }

            av = String(av === null || av === undefined ? '' : av).toLowerCase();
            bv = String(bv === null || bv === undefined ? '' : bv).toLowerCase();
            return av === bv ? 0 : (av < bv ? -dir : dir);
        });
    }

    /* -------------------------------------------------------------- render -- */

    /**
     * The Driver List's "Operational Status" cell: the driver's own flag from
     * Settings, NOT the availability bucket.
     *
     * The list only ever holds the active population, so the bucket read
     * "Active" for 412 of 455 drivers and told the reader nothing. The flag
     * is what the Address Book shows, so the two screens now agree, and an
     * unset flag is an em dash there - so it is one here too.
     */
    function flag(row) {
        var value = row.op_flag || '';
        if (value === '' || value === '—') {
            return '<span class="text-body-secondary">&mdash;</span>';
        }
        return '<span class="badge text-bg-info text-body">' + esc(value) + '</span>';
    }

    /**
     * The availability BUCKET: colour AND text, never colour alone (spec
     * section 12). Still what the KPI cards, both charts and the details
     * panel are drawn from - it is only the list column that changed.
     */
    function badge(row) {
        var key = row.op_status || 'other';
        var tone = TONES[key] || 'secondary';
        var label = row.op_label || LABELS[key] || key;
        return '<span class="badge text-bg-' + esc(tone) + ' dd-badge">'
            + '<i class="bi ' + esc({ active: 'bi-person-check', on_leave: 'bi-airplane',
                idle: 'bi-hourglass-split', gosi: 'bi-heart-pulse',
                other: 'bi-person-x' }[key] || 'bi-circle') + ' me-1"></i>'
            + esc(label) + '</span>';
    }

    function renderBody(list) {
        var body = el('ddBody');
        var table = el('ddTable');
        var empty = el('ddEmpty');

        if (!body) { return; }

        // Spec section 17: an empty result replaces the grid rather than
        // sitting under KPI totals that no longer describe what is on screen.
        if (!list.length) {
            body.innerHTML = '';
            if (table) { table.hidden = true; }
            if (empty) { empty.hidden = false; }
            return;
        }

        if (table) { table.hidden = false; }
        if (empty) { empty.hidden = true; }

        var start = (state.page - 1) * state.size;
        var pageRows = list.slice(start, start + state.size);
        var html = '';

        pageRows.forEach(function (row, i) {
            html += '<tr class="dd-row" tabindex="0" data-dd-id="' + esc(row.id) + '">'
                // Position in the current view: recounted for every sort and
                // page, so page 2 reads 11-20 rather than carrying page 1's
                // numbers over.
                + '<td class="text-center">' + (start + i + 1) + '</td>'
                + '<td class="text-nowrap fw-semibold">' + text(row.badge_number) + '</td>'
                + '<td class="fw-semibold text-nowrap">' + text(row.driver_name) + '</td>'
                + '<td class="text-nowrap">' + text(row.branch) + '</td>'
                + '<td>' + flag(row) + '</td>'
                + '<td>' + text(row.agency) + '</td>'
                + '<td>' + text(row.nationality) + '</td>'
                + '<td class="text-nowrap">' + fmtDate(row.expected_return) + '</td>'
                + '<td class="dd-remarks">' + text(row.remarks) + '</td>'
                + '</tr>';
        });

        body.innerHTML = html;

        body.querySelectorAll('.dd-row').forEach(function (tr) {
            tr.addEventListener('click', function () { openDriver(tr); });
            tr.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openDriver(tr);
                }
            });
        });

        // This tbody was just rebuilt, so every cell the column prefs was
        // holding is detached - without re-reading them a hidden column
        // silently comes back on the first sort or page change.
        if (window.ddPrefsBoot) { window.ddPrefsBoot(); }
    }

    /** "Showing 1-10 of 463" plus the page links, rebuilt on every change. */
    function renderPager(total) {
        var pages = Math.max(1, Math.ceil(total / state.size));
        state.page = Math.min(Math.max(1, state.page), pages);

        var first = total === 0 ? 0 : ((state.page - 1) * state.size) + 1;
        var last = Math.min(total, state.page * state.size);

        var showing = el('ddShowing');
        if (showing) {
            showing.innerHTML = 'Showing <strong>' + first.toLocaleString()
                + '</strong>&ndash;<strong>' + last.toLocaleString()
                + '</strong> of <strong>' + total.toLocaleString() + '</strong>';
        }

        var count = el('ddCount');
        if (count) { count.textContent = total.toLocaleString() + ' shown'; }

        var pager = el('ddPager');
        if (!pager) { return; }

        if (pages < 2) {
            pager.innerHTML = '';
            return;
        }

        var numbers = [];
        for (var i = 1; i <= pages; i++) {
            if (i === 1 || i === pages || Math.abs(i - state.page) <= 2) { numbers.push(i); }
        }

        var html = state.page > 1
            ? '<li class="page-item"><button type="button" class="page-link" data-dd-page="'
              + (state.page - 1) + '">&lsaquo;</button></li>'
            : '<li class="page-item disabled"><span class="page-link">&lsaquo;</span></li>';

        var previous = null;
        numbers.forEach(function (number) {
            if (previous !== null && number - previous > 1) {
                html += '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }
            html += number === state.page
                ? '<li class="page-item active"><span class="page-link">' + number + '</span></li>'
                : '<li class="page-item"><button type="button" class="page-link" data-dd-page="'
                  + number + '">' + number + '</button></li>';
            previous = number;
        });

        html += state.page < pages
            ? '<li class="page-item"><button type="button" class="page-link" data-dd-page="'
              + (state.page + 1) + '">&rsaquo;</button></li>'
            : '<li class="page-item disabled"><span class="page-link">&rsaquo;</span></li>';

        pager.innerHTML = html;

        pager.querySelectorAll('[data-dd-page]').forEach(function (button) {
            button.addEventListener('click', function () {
                state.page = parseInt(button.getAttribute('data-dd-page'), 10) || 1;
                apply();
            });
        });
    }

    /** One entry point for every change: recompute, redraw, resync the chrome. */
    function apply() {
        var list = sortRows(visibleRows());

        renderBody(list);
        renderPager(list.length);
        syncKpis();
        syncExport();
    }

    /* ---------------------------------------------------------- KPI cards -- */

    function anyFilter() {
        return state.op_status !== '' || state.operational_status !== ''
            || state.branch_id !== '' || state.q !== '';
    }

    /** Reflect the current filter in the cards (spec section 9). */
    function syncKpis() {
        document.querySelectorAll('.dd-kpi').forEach(function (button) {
            var key = button.getAttribute('data-dd-status') || '';
            // Total is the resting state: it reads as selected when nothing
            // is filtered, and clicking it clears everything.
            var on = key === '' ? !anyFilter() : state.op_status === key;

            button.classList.toggle('is-active', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    /**
     * Keep the two export links pointing at exactly what is on screen. The
     * server applies the same four filters through filterRows() (spec 18).
     */
    function syncExport() {
        var params = [];

        if (state.op_status !== '') { params.push('op_status=' + encodeURIComponent(state.op_status)); }
        if (state.operational_status !== '') {
            params.push('operational_status=' + encodeURIComponent(state.operational_status));
        }
        if (state.branch_id !== '') { params.push('branch_id=' + encodeURIComponent(state.branch_id)); }
        if (state.q !== '') { params.push('q=' + encodeURIComponent(state.q)); }

        ['ddExportCsv', 'ddExportXls'].forEach(function (id) {
            var link = el(id);
            if (!link) { return; }

            var base = link.getAttribute('data-dd-base');
            if (!base) {
                base = link.getAttribute('href') || '';
                link.setAttribute('data-dd-base', base);
            }

            link.setAttribute('href', base + (params.length ? '&' + params.join('&') : ''));
        });
    }

    /* ------------------------------------------------------- filter state -- */

    /** Push the state back into the controls so they never lag behind. */
    function syncControls() {
        if (el('ddStatus')) { el('ddStatus').value = state.operational_status; }
        if (el('ddBranch')) { el('ddBranch').value = state.branch_id; }
        if (el('ddSearch')) { el('ddSearch').value = state.q; }
    }

    function setFilters(status, branch) {
        if (status !== null && status !== undefined) { state.op_status = status; }
        if (branch !== null && branch !== undefined) { state.branch_id = branch; }
        state.page = 1;
        syncControls();
        apply();
    }

    function branchKey(branch) {
        var id = branch.branch_id;
        return (id === null || id === undefined || id === 0) ? '0' : String(id);
    }

    /* ------------------------------------------------------------- charts -- */

    function renderCharts() {
        if (typeof window.Chart === 'undefined') { return; }

        Chart.defaults.font.family = '"Segoe UI", system-ui, -apple-system, sans-serif';
        Chart.defaults.font.size = 12;
        Chart.defaults.color = '#475569';

        var branches = data.branches || [];

        // Stacked bar: one dataset per category, so a click tells us BOTH the
        // branch and the status (spec section 10).
        var bar = el('ddBranchChart');
        if (bar && branches.length) {
            new Chart(bar, {
                type: 'bar',
                data: {
                    labels: branches.map(function (b) { return b.branch; }),
                    datasets: CATEGORIES.map(function (key) {
                        return {
                            label: LABELS[key] || key,
                            data: branches.map(function (b) { return Number(b[key] || 0); }),
                            backgroundColor: COLOURS[key] || '#94a3b8',
                            stack: 'dd'
                        };
                    })
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick: function (event, elements) {
                        if (!elements.length) { return; }

                        var branch = branches[elements[0].index];
                        var status = CATEGORIES[elements[0].datasetIndex];
                        var same = state.op_status === status
                            && state.branch_id === branchKey(branch);

                        // Clicking what is already selected clears it.
                        setFilters(same ? '' : status, same ? '' : branchKey(branch));
                    },
                    scales: {
                        x: { stacked: true },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            ticks: {
                                precision: 0,
                                callback: function (v) { return Number(v).toLocaleString(); }
                            }
                        }
                    },
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });
        }

        // Horizontal bars: agency names are long and there are few of them,
        // so the label reads far better on the axis than rotated under a
        // column. The server sorts largest-first, so the shape does not dance
        // between refreshes. Display-only - this page has no agency filter,
        // so a click would have nothing to drive.
        var agencyBar = el('ddAgencyChart');
        if (agencyBar) {
            var agencies = data.agencies || [];

            if (!agencies.length) {
                agencyBar.parentNode.innerHTML =
                    '<div class="text-center text-body-secondary py-5">'
                    + '<i class="bi bi-building d-block mb-2" style="font-size:2rem;opacity:.4"></i>'
                    + 'No drivers to show.</div>';
            } else {
                // Summed from the bars themselves: the tooltip percentage can
                // never be computed against a different total than the chart.
                var agencyTotal = agencies.reduce(function (sum, a) {
                    return sum + Number(a.total || 0);
                }, 0);

                new Chart(agencyBar, {
                    type: 'bar',
                    data: {
                        labels: agencies.map(function (a) { return a.agency; }),
                        datasets: [{
                            label: 'Drivers',
                            data: agencies.map(function (a) { return Number(a.total || 0); }),
                            backgroundColor: '#1e4b7a',
                            hoverBackgroundColor: '#2563eb',
                            borderRadius: 3
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0,
                                    callback: function (v) { return Number(v).toLocaleString(); }
                                }
                            },
                            // Agency names run long: never let Chart.js skip one
                            // to make the axis fit, or a small agency vanishes.
                            y: { ticks: { autoSkip: false, font: { size: 11 } } }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        var n = Number(ctx.parsed.x) || 0;
                                        var pct = agencyTotal
                                            ? (100 * n / agencyTotal).toFixed(1) : '0.0';

                                        return n + ' driver' + (n === 1 ? '' : 's')
                                            + ' (' + pct + '% of ' + agencyTotal + ')';
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }

        // Donut: every category with a count, so the slices always foot to the
        // Total Drivers figure above (spec section 14).
        var donut = el('ddOverallChart');
        if (donut) {
            var overall = data.overall || {};
            var present = CATEGORIES.filter(function (key) {
                return Number(overall[key] || 0) > 0;
            });

            if (!present.length) {
                donut.parentNode.innerHTML =
                    '<div class="text-center text-body-secondary py-5">'
                    + '<i class="bi bi-pie-chart d-block mb-2" style="font-size:2rem;opacity:.4"></i>'
                    + 'No drivers to show.</div>';
                return;
            }

            new Chart(donut, {
                type: 'doughnut',
                data: {
                    labels: present.map(function (key) { return LABELS[key] || key; }),
                    datasets: [{
                        data: present.map(function (key) { return Number(overall[key] || 0); }),
                        backgroundColor: present.map(function (key) {
                            return COLOURS[key] || '#94a3b8';
                        }),
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    onClick: function (event, elements) {
                        if (!elements.length) { return; }
                        var status = present[elements[0].index];
                        setFilters(state.op_status === status ? '' : status, null);
                    },
                    plugins: { legend: { position: 'bottom' } }
                }
            });
        }
    }

    /* ------------------------------------------------------------ details -- */

    function field(label, value) {
        return '<dt class="col-sm-4 text-body-secondary fw-normal">' + esc(label) + '</dt>'
            + '<dd class="col-sm-8 mb-2">' + value + '</dd>';
    }

    function rowById(id) {
        for (var i = 0; i < rows.length; i++) {
            if (Number(rows[i].id) === id) { return rows[i]; }
        }
        return null;
    }

    /**
     * Read-only details (spec section 11). Everything shown is already in the
     * page payload, so opening it costs no request; nothing here can edit the
     * driver, which stays the address book's job.
     */
    function openDriver(tr) {
        var row = rowById(parseInt(tr.getAttribute('data-dd-id'), 10) || 0);
        if (!row) { return; }

        el('ddDriverTitle').textContent = row.driver_name || 'Driver';
        el('ddDriverSub').textContent = (row.badge_number || '') + ' · ' + (row.branch || '');

        el('ddInfoDriver').innerHTML =
            field('Badge Number', text(row.badge_number))
            + field('Driver Name', text(row.driver_name))
            + field('Nationality', text(row.nationality))
            + field('Agency', text(row.agency))
            + field('Branch / Terminal', text(row.branch));

        el('ddInfoOps').innerHTML =
            field('Operational Status', flag(row))
            + field('Availability', badge(row))
            + field('Employment Status', text(row.employment))
            + '<dt class="col-sm-4 text-body-secondary fw-normal">Tractor / Attachment / Customer</dt>'
            + '<dd class="col-sm-8 mb-2 small text-body-secondary">Not tracked in this system.</dd>';

        el('ddInfoAvail').innerHTML =
            field('Last Working Date', fmtDate(row.last_working_date))
            + field('Expected Return', fmtDate(row.expected_return))
            + field('Vacation / Leave Status',
                row.op_status === 'on_leave'
                    ? '<span class="badge text-bg-info">On leave now</span>'
                    : '<span class="text-body-secondary">Not on leave</span>')
            + field('Remarks', text(row.remarks));

        var base = (data.urls && data.urls.driver) || '/drivers/';
        el('ddDriverProfile').setAttribute('href', base + encodeURIComponent(String(row.id)));

        var modalEl = el('ddDriverModal');
        if (window.bootstrap && modalEl) {
            // Bootstrap loads after this file, so the instance is created on
            // first open rather than at parse time.
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }

    /* ------------------------------------------------- branch summary sort -- */

    /**
     * Sort the server-rendered branch summary in place. Values are read from
     * the cells rather than re-derived, so the table can never show a total
     * that disagrees with the charts drawn from the same array.
     */
    function sortBranchTable(key) {
        var table = el('ddBranchTable');
        var body = table && table.tBodies[0];
        if (!body) { return; }

        // cell 0 = branch, 1 = total, 2..6 = the five categories, 7 = active %
        var index = { branch: 0, total: 1, active_pct: 7 };
        CATEGORIES.forEach(function (cat, i) { index[cat] = 2 + i; });

        var column = index[key] === undefined ? 1 : index[key];
        var numeric = column !== 0;

        var list = Array.prototype.slice.call(body.querySelectorAll('tr.dd-branch-row'));

        list.sort(function (a, b) {
            var av = a.cells[column] ? a.cells[column].textContent.trim() : '';
            var bv = b.cells[column] ? b.cells[column].textContent.trim() : '';

            if (numeric) {
                var an = parseFloat(av.replace(/[%,\s]/g, '')) || 0;
                var bn = parseFloat(bv.replace(/[%,\s]/g, '')) || 0;
                return state.dir === 1 ? an - bn : bn - an;
            }

            av = av.toLowerCase();
            bv = bv.toLowerCase();
            if (av === bv) { return 0; }
            return state.dir === 1 ? (av < bv ? -1 : 1) : (av > bv ? -1 : 1);
        });

        list.forEach(function (tr) { body.appendChild(tr); });
    }

    /* -------------------------------------------------------------- wiring -- */

    function markSortHeaders() {
        document.querySelectorAll('.dd-sort').forEach(function (th) {
            var on = th.getAttribute('data-dd-sort') === state.sort;
            var icon = th.querySelector('.dd-sort-icon');

            th.setAttribute('aria-sort',
                on ? (state.dir === 1 ? 'ascending' : 'descending') : 'none');

            if (icon) {
                icon.className = 'bi ms-1 dd-sort-icon '
                    + (on ? (state.dir === 1 ? 'bi-arrow-up' : 'bi-arrow-down')
                          : 'bi-arrow-down-up');
            }
        });
    }

    function toggleSort(key, isBranchTable) {
        if (state.sort === key) {
            state.dir = -state.dir;
        } else {
            state.sort = key;
            state.dir = 1;
        }
        state.page = 1;
        markSortHeaders();

        if (isBranchTable) {
            sortBranchTable(key);
        } else {
            apply();
        }
    }

    function clearFilters() {
        state.op_status = '';
        state.operational_status = '';
        state.branch_id = '';
        state.q = '';
        state.page = 1;
        syncControls();
        apply();
    }

    /** Click or Enter/Space on a sortable header. */
    function wireSortHeaders(selector, isBranchTable) {
        document.querySelectorAll(selector).forEach(function (th) {
            var run = function () {
                toggleSort(th.getAttribute('data-dd-sort'), isBranchTable);
            };

            th.addEventListener('click', run);
            th.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    run();
                }
            });
        });
    }

    /** Click or Enter/Space on a row that carries a filter of its own. */
    function wireRow(element, run) {
        element.addEventListener('click', run);
        element.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                run();
            }
        });
    }

    function init() {
        syncControls();

        // KPI cards: Total clears, the four buckets toggle (spec section 9).
        document.querySelectorAll('.dd-kpi').forEach(function (button) {
            button.addEventListener('click', function () {
                var key = button.getAttribute('data-dd-status') || '';

                if (key === '') {
                    clearFilters();
                    return;
                }

                setFilters(state.op_status === key ? '' : key, null);
            });
        });

        [['ddStatus', 'operational_status'], ['ddBranch', 'branch_id']]
            .forEach(function (pair) {
                var control = el(pair[0]);
                if (!control) { return; }

                control.addEventListener('change', function () {
                    state[pair[1]] = control.value;
                    state.page = 1;
                    apply();
                });
            });

        var search = el('ddSearch');
        if (search) {
            search.addEventListener('input', function () {
                state.q = search.value.trim().toLowerCase();
                state.page = 1;
                apply();
            });
        }

        var size = el('ddSize');
        if (size) {
            size.addEventListener('change', function () {
                state.size = parseInt(size.value, 10) || 10;
                state.page = 1;
                apply();
            });
        }

        if (el('ddClear')) { el('ddClear').addEventListener('click', clearFilters); }
        if (el('ddEmptyClear')) { el('ddEmptyClear').addEventListener('click', clearFilters); }

        wireSortHeaders('#ddTable .dd-sort', false);
        wireSortHeaders('#ddBranchTable .dd-sort', true);

        // A branch row filters the list to that branch (spec section 10).
        document.querySelectorAll('.dd-branch-row').forEach(function (tr) {
            wireRow(tr, function () {
                var key = tr.getAttribute('data-dd-branch') || '';
                setFilters(null, state.branch_id === key ? '' : key);
            });
        });

        markSortHeaders();
        apply();
        renderCharts();
    }

    init();
})();




