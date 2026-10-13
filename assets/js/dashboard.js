/**
 * Dashboard charts (Chart.js) - rendering only, no data logic.
 * Data arrives as window.DASHBOARD_DATA from the PHP view.
 */
(function () {
    'use strict';

    var data = window.DASHBOARD_DATA || {};

    if (typeof window.Chart === 'undefined') { return; }

    Chart.defaults.font.family = '"Segoe UI", system-ui, -apple-system, sans-serif';
    Chart.defaults.font.size = 12;
    Chart.defaults.color = '#475569';

    var palette = ['#1e4b7a', '#2563eb', '#0d9488', '#f59e0b', '#7c3aed',
        '#dc2626', '#0891b2', '#65a30d', '#db2777', '#64748b'];

    /**
     * Fixed colours for the "Current Leave by Branch" bars, as requested by
     * operations. Matched on the branch NAME (case-insensitive), so a branch
     * keeps its own colour however the bars happen to sort. Any branch not
     * listed here falls back to the chart's default colour - adding a branch
     * later needs no code change.
     */
    var branchColours = {
        'dammam': '#1e3a8a',   // dark blue
        'riyadh': '#16a34a',   // green
        'jeddah': '#f97316'    // orange
    };

    /** Horizontal bar chart: current leave by branch / agency. */
    function barChart(canvasId, rows, colour, colourMap) {
        var canvas = document.getElementById(canvasId);
        if (!canvas || !rows || !rows.length) {
            if (canvas) {
                canvas.parentNode.innerHTML =
                    '<div class="text-center text-body-secondary py-5">' +
                    '<i class="bi bi-bar-chart d-block mb-2" style="font-size:2rem;opacity:.4"></i>' +
                    'No drivers are currently on leave.</div>';
            }
            return;
        }

        var labels = rows.map(function (r) { return r.label; });
        var values = rows.map(function (r) { return r.total; });

        // One colour per bar: a named match wins, otherwise the default.
        var colours = rows.map(function (r) {
            if (colourMap) {
                var key = String(r.label === null || r.label === undefined ? '' : r.label)
                    .trim().toLowerCase();
                if (Object.prototype.hasOwnProperty.call(colourMap, key)) {
                    return colourMap[key];
                }
            }
            return colour;
        });

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Drivers away',
                    data: values,
                    backgroundColor: colours,
                    borderRadius: 4,
                    barThickness: 22
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ' ' + ctx.parsed.x + ' driver(s)';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { precision: 0, stepSize: 1 },
                        grid: { color: '#eef2f7' }
                    },
                    y: { grid: { display: false } }
                }
            }
        });
    }

    /** Monthly leave trend line. */
    function trendChart(canvasId, rows) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) { return; }

        if (!rows || !rows.length) {
            canvas.parentNode.innerHTML =
                '<div class="text-center text-body-secondary py-5">' +
                '<i class="bi bi-graph-up d-block mb-2" style="font-size:2rem;opacity:.4"></i>' +
                'No leave records in the selected period.</div>';
            return;
        }

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: rows.map(function (r) { return r.label; }),
                datasets: [{
                    label: 'Leave records',
                    data: rows.map(function (r) { return r.total; }),
                    borderColor: '#1e4b7a',
                    backgroundColor: 'rgba(30, 75, 122, .12)',
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true,
                    pointRadius: 3,
                    pointBackgroundColor: '#1e4b7a'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ' ' + ctx.parsed.y + ' record(s)';
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                        grid: { color: '#eef2f7' }
                    }
                }
            }
        });
    }

    /**
     * Multi-series monthly trend: "Applications & Arrivals".
     *
     * The three lines are built from ONE month axis (the server guarantees
     * they are index-aligned), so the legend is what labels them. Points are
     * kept visible and lines are not filled - with three overlapping series a
     * fill hides whichever line sits underneath it.
     */
    function multiTrendChart(canvasId, payload) {
        var canvas = document.getElementById(canvasId);
        var labels = (payload && payload.labels) || [];

        if (!canvas) { return; }

        if (!labels.length) {
            canvas.parentNode.innerHTML =
                '<div class="text-center text-body-secondary py-5">' +
                '<i class="bi bi-graph-up d-block mb-2" style="font-size:2rem;opacity:.4"></i>' +
                'No applications or arrivals in the selected period.</div>';
            return;
        }

        var series = [
            { label: 'Pending applications', colour: '#f59e0b', data: payload.pending || [] },
            { label: 'Approved applications', colour: '#0d9488', data: payload.approved || [] },
            { label: 'Expected returns', colour: '#1e4b7a', data: payload.arrivals || [] }
        ];

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: series.map(function (s) {
                    return {
                        label: s.label,
                        data: s.data,
                        borderColor: s.colour,
                        backgroundColor: s.colour,
                        borderWidth: 2,
                        tension: 0.3,
                        fill: false,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        pointBackgroundColor: s.colour
                    };
                })
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, padding: 10 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                        grid: { color: '#eef2f7' }
                    }
                }
            }
        });
    }

    /* ------------------------------------ stacked modal (summary cards) -- */

    var stackedInstance = null;

    /**
     * Colour for one stack segment. Named branches keep the exact palette the
     * "Current Leave by Branch" chart uses, so the same branch is always the
     * same colour across the dashboard; anything else (an extra branch, or the
     * "-" column for records with no branch) takes the next palette slot.
     */
    function stackedColour(name, index) {
        var key = String(name === null || name === undefined ? '' : name).trim().toLowerCase();

        if (Object.prototype.hasOwnProperty.call(branchColours, key)) {
            return branchColours[key];
        }

        return palette[index % palette.length];
    }

    /**
     * Stacked bar chart for one summary card: months on X, one segment per
     * branch, base first. `payload` is DASHBOARD_DATA.stacked - the same month
     * axis and branch columns the tables are printed from, so the stack total
     * and the Total column are the same number by construction.
     */
    function stackedChart(payload, key) {
        var canvas = document.getElementById('stackedChart');
        var box    = document.getElementById('smChartBox');
        var empty  = document.getElementById('smEmpty');

        if (!canvas || !box || !empty || !payload) { return; }

        if (stackedInstance) {
            stackedInstance.destroy();
            stackedInstance = null;
        }

        var labels   = payload.labels || [];
        var branches = payload.branches || {};
        var codes    = Object.keys(branches);
        var series   = (payload.series || {})[key] || {};

        if (!labels.length || !codes.length) {
            box.classList.add('d-none');
            empty.classList.remove('d-none');
            return;
        }

        box.classList.remove('d-none');
        empty.classList.add('d-none');

        // Headroom: a stack whose top sits exactly on the top gridline reads
        // as clipped, and small integer counts (0..3) have nowhere to grow.
        // Cap the scale a little above the tallest stack so the axis labels
        // run on past it - the tallest bar then never touches the border.
        var axisMax = 0;

        labels.forEach(function (unused, i) {
            var total = 0;

            codes.forEach(function (code) {
                var values = series[code];
                total += values ? (Number(values[i]) || 0) : 0;
            });

            axisMax = Math.max(axisMax, total);
        });

        axisMax += Math.max(1, Math.ceil(axisMax / 4));

        stackedInstance = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: codes.map(function (code, i) {
                    var name = branches[code];

                    return {
                        label: name,
                        data: series[code] || labels.map(function () { return 0; }),
                        backgroundColor: stackedColour(name, i),
                        borderRadius: 2
                    };
                })
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, padding: 10 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y;
                            },
                            footer: function (items) {
                                var total = 0;
                                items.forEach(function (item) { total += item.parsed.y; });
                                return 'Month total: ' + total;
                            }
                        }
                    }
                },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        max: axisMax,
                        ticks: { precision: 0 },
                        grid: { color: '#eef2f7' }
                    }
                }
            }
        });
    }

    var stackedModal = document.getElementById('stackedModal');

    if (stackedModal) {
        stackedModal.addEventListener('shown.bs.modal', function (event) {
            // relatedTarget is the icon that opened the dialog.
            var trigger = event.relatedTarget;
            if (!trigger) { return; }

            var payload = data.stacked || {};
            var glyph   = trigger.getAttribute('data-stacked-icon') || 'bi-bar-chart-steps';
            var tone    = trigger.getAttribute('data-stacked-tone') || 'primary';

            document.getElementById('smTitle').textContent =
                trigger.getAttribute('data-stacked-title') || '';
            document.getElementById('smRange').textContent = payload.range || '';
            document.getElementById('smIcon').className =
                'bi ' + glyph + ' me-2 text-' + tone;

            stackedChart(payload, trigger.getAttribute('data-stacked-series') || '');
        });

        stackedModal.addEventListener('hidden.bs.modal', function () {
            if (stackedInstance) {
                stackedInstance.destroy();
                stackedInstance = null;
            }
        });
    }

    // Give the canvas a real height so maintainAspectRatio:false works.
    document.querySelectorAll('.chart-box').forEach(function (box) {
        box.style.height = '260px';
    });

    barChart('chartBranch', data.branch, palette[0], branchColours);
    barChart('chartAgency', data.agency, palette[1]);
    trendChart('chartTrend', data.trend);
    multiTrendChart('chartArrivals', data.arrivals);

    /* -------------------------- summary row drill-down ------------------ */

    // Clicking a month row in any of the three summary tables lists the
    // drivers behind that row in one shared modal. Rendering only: the API
    // (Dashboard::summaryDrivers) owns the numbers, and its count always
    // equals the cell that was clicked.
    var drillModal = document.getElementById('summaryDrillModal');

    if (drillModal) {
        var sdHeading = document.getElementById('sdHeading');
        var sdMeta    = document.getElementById('sdMeta');
        var sdLoading = document.getElementById('sdLoading');
        var sdError   = document.getElementById('sdError');
        var sdEmpty   = document.getElementById('sdEmpty');
        var sdWrap    = document.getElementById('sdTableWrap');
        var sdTable   = document.getElementById('sdTable');
        var sdIcon    = document.getElementById('sdIcon');

        // Present only for a role holding reports.export (the view omits it).
        // It points at the SAME rows the modal renders, so the download cannot
        // ask for a different slice than the reader is looking at.
        var sdExport = document.getElementById('sdExport');

        function setExport(url) {
            if (!sdExport) { return; }

            if (url) {
                sdExport.setAttribute('href', url);
                sdExport.classList.remove('disabled');
                sdExport.removeAttribute('aria-disabled');
                sdExport.removeAttribute('tabindex');
            } else {
                // Bootstrap's .disabled sets pointer-events: none, so this also
                // stops a click while nothing is loaded.
                sdExport.setAttribute('href', '#');
                sdExport.classList.add('disabled');
                sdExport.setAttribute('aria-disabled', 'true');
                sdExport.setAttribute('tabindex', '-1');
            }
        }

        var glyphs = {
            arrivals: 'bi-box-arrow-in-right',
            resignations: 'bi-person-dash',
            vacations: 'bi-airplane'
        };
        var tones = {
            arrivals: 'primary',
            resignations: 'warning',
            vacations: 'success'
        };

        /** Strip the current area off the path, same rule app.js uses. */
        function drillBase() {
            return window.location.pathname
                .replace(/\/(leave|drivers|reports|settings|dashboard).*$/, '');
        }

        function show(name, on) {
            var el = document.getElementById(name);
            if (el) { el.classList.toggle('d-none', !on); }
        }

        function statusTone(status) {
            switch (status) {
                case 'approved': case 'returned': return 'success';
                // "active" is an EMPLOYMENT status, not a leave one, and it is
                // only ever drawn by the Total Active Drivers card. Address
                // Book colours the same value text-bg-success, so a driver
                // green in one screen must not turn grey in the other.
                case 'active':                    return 'success';
                case 'pending': case 'planned':    return 'info';
                case 'on_leave':                   return 'primary';
                case 'overdue': case 'rejected':   return 'danger';
                // Leave Status values this modal only recently started
                // drawing. Tones mirror status_badge_class() in
                // helpers/functions.php - the palette the register, the
                // reports and every server-rendered badge read - so one
                // status is never two colours depending on the screen.
                case 'not_returned':               return 'warning';
                case 'cancelled': case 'closed':   return 'dark';
                default:                           return 'secondary';
            }
        }

        function cell(tr, text, className) {
            var td = document.createElement('td');
            td.className = className || '';
            td.textContent = text;
            tr.appendChild(td);
            return td;
        }

        /**
         * One status badge cell. An ABSENT status says so with a plain em
         * dash and NO badge - the rule status_badge('') applies server-side -
         * so an arrivals record with no filing behind it can never show an
         * empty pill that reads as a real state.
         */
        function statusCell(tr, raw) {
            var text = String(raw || '').replace(/_/g, ' ');
            var td = document.createElement('td');

            if (text === '') {
                td.className = 'text-body-secondary';
                td.textContent = '—';
                tr.appendChild(td);
                return td;
            }

            var badge = document.createElement('span');
            badge.className = 'badge text-bg-' + statusTone(raw);
            badge.textContent = text;
            td.appendChild(badge);
            tr.appendChild(td);
            return td;
        }

        function renderRows(payload) {
            // The header lives in its own <thead>, so emptying the body can
            // no longer take it with it (it used to be a bare <tr> sharing an
            // implicit tbody with these rows).
            var body = sdTable.tBodies[0];
            body.textContent = '';

            // Row numbers run on across pages: page 2 starts at size + 1, so
            // "#14" still means the fourteenth row of this card's list - the
            // same number the Excel download prints beside it.
            var offset = (drill.page - 1) * drill.size;

            // "Total Active Drivers" is a population, not a series of events:
            // every row has an empty event date, so the Date column would be
            // nothing but dashes. Drop header and cell together.
            var noDate = payload.kpi === 'total_drivers';
            var dateHead = document.getElementById('sdDateHead');
            if (dateHead) { dateHead.style.display = noDate ? 'none' : ''; }

            // The three summary modals carry BOTH status axes (Application
            // Status + Leave Status); the KPI-card drill carries one status
            // value only, so it keeps the single Status column it has always
            // had - header text and cells drop together, exactly like the
            // Date column above.
            var twoAxis = drill.mode === 'month';
            var appHead = document.getElementById('sdAppStatusHead');
            if (appHead) { appHead.textContent = twoAxis ? 'Application Status' : 'Status'; }
            var leaveHead = document.getElementById('sdLeaveStatusHead');
            if (leaveHead) { leaveHead.style.display = twoAxis ? '' : 'none'; }

            (payload.rows || []).forEach(function (row, i) {
                var tr = document.createElement('tr');

                cell(tr, String(offset + i + 1), 'text-center');

                cell(tr, row.badge_number || '—', 'text-nowrap');

                var nameTd = cell(tr, '', 'fw-semibold');
                if (row.driver_id) {
                    var link = document.createElement('a');
                    link.className = 'link-dark text-decoration-none';
                    link.href = drillBase() + '/drivers/' + row.driver_id;
                    link.textContent = row.driver_name || '—';
                    nameTd.appendChild(link);
                } else {
                    nameTd.textContent = row.driver_name || '—';
                }

                // month drills carry branch_code (possibly "-" for none);
                // KPI card rows carry only branch_name, so neither alone may
                // be taken as proof there is no branch.
                var branch = row.branch_name
                    ? row.branch_name
                    : (row.branch_code && row.branch_code !== '-'
                        ? row.branch_code
                        : 'No branch');
                cell(tr, branch, 'text-nowrap');
                if (!noDate) {
                    cell(tr, row.event_date_fmt || row.event_date || '—', 'text-nowrap');
                }

                // Month drills: both axes, in the order the header names them.
                // KPI drills: the single status the card always showed.
                if (twoAxis) {
                    statusCell(tr, row.application_status);
                    statusCell(tr, row.leave_status);
                } else {
                    statusCell(tr, row.status);
                }

                body.appendChild(tr);
            });
        }

        // One page of state shared by both drill shapes: 'month' serves the
        // three summary tables and 'kpi' a KPI card. Both page at 10 rows -
        // a busy month or a whole fleet is far longer than the rows one modal
        // can draw - and both keep `total` as the WHOLE list, so the heading
        // still reads what the clicked cell printed.
        var drill = { mode: 'month', page: 1, size: 10, pages: 1, total: 0, url: '' };

        /** Show/hide the pager and say which page of how many is on screen. */
        function setPager() {
            var pager = document.getElementById('sdPager');
            if (!pager) { return; }

            var on = drill.pages > 1;

            // #sdPager ships d-flex, and Bootstrap's .d-flex is
            // `display: flex !important` - which outranks the UA rule for the
            // [hidden] attribute. Setting .hidden alone therefore NEVER hid
            // it, and because the label was only rewritten when `on`, a card
            // with a single page kept displaying the previous card's
            // "Page 1 of 3 - 24 row(s)" over its own two rows. Toggle the
            // utility classes instead: d-flex XOR d-none, never both.
            pager.classList.toggle('d-flex', on);
            pager.classList.toggle('d-none', !on);
            pager.hidden = !on;

            if (!on) {
                // Clear the stale label too - otherwise it is still sitting
                // in the DOM for anyone reading it with a screen reader.
                var stale = document.getElementById('sdPageLabel');
                if (stale) { stale.textContent = ''; }
            }

            if (on) {
                var plabel = document.getElementById('sdPageLabel');
                if (plabel) {
                    plabel.textContent = 'Page ' + drill.page + ' of ' + drill.pages
                        + ' · ' + drill.total + ' row(s)';
                }
            }

            var prev = document.getElementById('sdPrev');
            var next = document.getElementById('sdNext');
            if (prev) { prev.disabled = drill.page <= 1; }
            if (next) { next.disabled = drill.page >= drill.pages; }
        }

        /** Reset the body to "loading" and show the modal. */
        function beginDrill(title, iconClass, meta) {
            sdHeading.textContent = title;
            if (sdIcon) { sdIcon.className = iconClass; }
            sdMeta.textContent = meta;
            sdMeta.classList.remove('d-none');

            show('sdLoading', true);
            show('sdError', false);
            show('sdEmpty', false);
            sdWrap.hidden = true;
            sdTable.tBodies[0].textContent = '';

            // The pager belongs to the drill that is about to arrive, never
            // to the one this modal is replacing - hide it now rather than
            // leaving the last card's page count up while this loads.
            setPager();

            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                bootstrap.Modal.getOrCreateInstance(drillModal).show();
            }
        }

        /**
         * Fetch a drill and paint it. `url` is reused verbatim by the Excel
         * link so the download cannot ask for a different slice than the one
         * on screen - a card and a month row both point at their own endpoint,
         * and both export exactly what they are showing.
         */
        function loadDrill(url, title, label) {
            setExport(null);   // nothing to export until rows actually arrive

            // The modal is reusable, so a reader can open a second drill while
            // this one is still in flight. The drill object is REPLACED on
            // every open, which makes identity the generation token: a reply
            // for an object that is no longer `drill` belongs to a modal that
            // is no longer on screen, and painting it would put the previous
            // card's rows and page count under the new card's heading.
            var mine = drill;

            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (res) {
                    return res.json().catch(function () { return {}; })
                        .then(function (body) { return { ok: res.ok, body: body }; });
                })
                .then(function (out) {
                    if (drill !== mine) { return; }   // stale reply, dropped

                    show('sdLoading', false);

                    if (!out.ok || !out.body.ok) {
                        show('sdError', true);
                        sdError.textContent = out.body.error
                            || 'Could not load the drivers behind this row.';
                        setExport(null);
                        return;
                    }

                    var payload = out.body;
                    drill.total = payload.total || 0;
                    drill.pages = payload.pages || 1;
                    drill.page  = payload.page || drill.page;
                    setPager();

                    sdHeading.textContent = payload.title || title;
                    sdMeta.textContent = (payload.label || label) + ' · '
                        + payload.total + ' driver' + (payload.total === 1 ? '' : 's');
                    sdMeta.classList.remove('d-none');

                    if (!payload.rows || !payload.rows.length) {
                        show('sdEmpty', true);
                        setExport(null);
                        return;
                    }

                    renderRows(payload);
                    sdWrap.hidden = false;

                    // Rows exist, so the download is now meaningful. A KPI
                    // card points at its own endpoint, which exports the page
                    // on screen - the month drill has always worked this way,
                    // and a card with a disabled Excel was simply a button
                    // that could never be clicked.
                    setExport(url + '&export=xls');

                    // Rebuilt tbody: re-read the cells the column prefs holds
                    // so a hidden column stays hidden after the drill-down.
                    if (window.ddPrefsBoot) { window.ddPrefsBoot(); }
                })
                .catch(function () {
                    if (drill !== mine) { return; }   // stale reply, dropped

                    show('sdLoading', false);
                    show('sdError', true);
                    sdError.textContent = 'Could not reach the server.';
                    setExport(null);
                });
        }


        function openDrill(rowEl) {
            var series = rowEl.getAttribute('data-summary-series') || '';
            var month  = rowEl.getAttribute('data-summary-month') || '';
            var title  = rowEl.getAttribute('data-summary-title') || '';
            var label  = rowEl.getAttribute('data-summary-label') || '';

            if (!series || !month) { return; }

            drill = { mode: 'month', page: 1, size: 10, pages: 1, total: 0,
                      series: series, month: month, title: title, label: label,
                      url: '' };
            setPager();

            beginDrill(title,
                'bi ' + (glyphs[series] || 'bi-list-ul') + ' me-2 text-'
                + (tones[series] || 'primary'), label);

            monthFetch();
        }

        /**
         * Load the month's current page. The series and month are STORED on
         * the drill rather than rebuilt from the DOM, so paging re-asks the
         * same question one page further on - and the Excel link (built from
         * this same url) can never ask for a different month than the one on
         * screen.
         */
        function monthFetch() {
            var query = '?series=' + encodeURIComponent(drill.series || '')
                + '&month=' + encodeURIComponent(drill.month || '')
                + '&page=' + drill.page + '&size=' + drill.size;

            drill.url = drillBase() + '/api/dashboard/summary-drivers' + query;

            loadDrill(drill.url, drill.title || sdHeading.textContent, drill.label || '');
            setPager();
        }

        /**
         * A KPI card's own "View": the rows behind THAT card, paged in place.
         *
         * This replaced the plain links the cards used to carry. Those
         * navigated to a page whose filters had to be rebuilt by hand to reach
         * the same number; here the card's key IS the query, so the list that
         * opens always totals the figure printed above it.
         */
        function openKpiDrill(key, label, range) {
            if (!key) { return; }

            drill = { mode: 'kpi', page: 1, size: 10, pages: 1, total: 0,
                      kpi: key, range: range || '', url: '' };

            beginDrill(label, 'bi bi-list-ul me-2 text-primary', '');
            kpiFetch();
        }

        /** Load the card's current page. Heading never changes under the reader. */
        function kpiFetch() {
            var q = '?kpi=' + encodeURIComponent(drill.kpi || '')
                + '&page=' + drill.page + '&size=' + drill.size;

            // Range-backed cards were built from a window, not from today, so
            // they send that window: otherwise the modal would list some other
            // period than the one the number came from.
            var parts = (drill.range || '').split(',');
            if (parts.length === 2 && parts[0] && parts[1]) {
                q += '&from=' + encodeURIComponent(parts[0])
                   + '&to=' + encodeURIComponent(parts[1]);
            }

            drill.url = drillBase() + '/api/dashboard/kpi-drivers' + q;

            loadDrill(drill.url, sdHeading.textContent, '');
            setPager();
        }

        var sdPrev = document.getElementById('sdPrev');
        var sdNext = document.getElementById('sdNext');

        /**
         * Step the shared pager one page. A month drill and a KPI drill page
         * exactly the same way - only the endpoint they re-ask differs.
         */
        function stepDrill(delta) {
            if (drill.pages <= 1) { return; }

            var page = drill.page + delta;
            if (page < 1 || page > drill.pages) { return; }

            drill.page = page;
            show('sdLoading', true);
            show('sdError', false);
            show('sdEmpty', false);

            if (drill.mode === 'kpi') {
                kpiFetch();
            } else {
                monthFetch();
            }
        }

        if (sdPrev) { sdPrev.addEventListener('click', function () { stepDrill(-1); }); }
        if (sdNext) { sdNext.addEventListener('click', function () { stepDrill(1); }); }

        // The cards' "View": open that card's rows instead of navigating.
        document.querySelectorAll('.sd-kpi').forEach(function (el) {
            el.addEventListener('click', function (event) {
                event.preventDefault();

                var card  = el.closest('.kpi-card');
                var found = card ? card.querySelector('.kpi-label') : null;
                var label = found ? (found.textContent || '') : '';

                openKpiDrill(el.getAttribute('data-kpi-card') || '',
                             label.trim(),
                             el.getAttribute('data-kpi-range') || '');
            });
        });

        document.querySelectorAll('.summary-drill-row').forEach(function (rowEl) {
            rowEl.addEventListener('click', function () { openDrill(rowEl); });
            rowEl.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openDrill(rowEl);
                }
            });
        });
    }
})();
