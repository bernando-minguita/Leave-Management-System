/**
 * Driver Capacity Planning - rendering only (chart + drill-down modal).
 *
 * The numbers arrive from PHP in window.CAPACITY_DATA; nothing here decides a
 * business figure. Follows the dashboard.js conventions: one IIFE, no build
 * step, Chart.js already vendored, FL helpers from app.js when present.
 */
(function () {
    'use strict';

    var data  = window.CAPACITY_DATA || {};
    var urls  = window.CAPACITY_URLS || {};
    var query = window.CAPACITY_QUERY || {};

    function esc(value) {
        if (window.FL && typeof FL.escapeHtml === 'function') {
            return FL.escapeHtml(String(value === null || value === undefined ? '' : value));
        }
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmtDate(iso) {
        if (window.FL && typeof FL.formatDate === 'function') {
            return FL.formatDate(iso);
        }
        return String(iso || '');
    }

    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    function shortDate(iso) {
        var p = String(iso).split('-');
        if (p.length !== 3) { return String(iso); }
        return parseInt(p[2], 10) + ' ' + MONTHS[parseInt(p[1], 10) - 1];
    }

    /* ------------------------------------------------------------- chart -- */

    /**
     * How a percentage reads on this screen (round 6): rounded to two
     * decimals and printed with the trailing zeros trimmed, which is exactly
     * what JavaScript does on its own - 5.75 -> "5.75%", 5.70 -> "5.7%",
     * 45 -> "45%". Mirrors pct_label() in PHP so the chart and the cards
     * above it can never disagree about the same day.
     */
    function pctText(value) {
        var n = Math.round(Number(value) * 100) / 100;
        if (n === 0) { n = 0; }   // normalise -0 so it never prints "-0%"
        return n + '%';
    }

    /**
     * The series the dropdown can select. Same chart format for both -
     * only the data, the title, the axis label and the tooltip wording
     * move. `percent` keeps the 0-100 style ceiling for the percentage
     * view; the count view scales to its own busiest day.
     */
    var METRICS = {
        onLeave: {
            title: 'Daily Driver On-Leave %',
            lines: [{ key: 'onLeavePct', label: 'On-leave %', colour: '#1e4b7a' }],
            yTitle: 'On-leave %',
            percent: true,
            // Two lines: the share, then the headcount it stands for. A
            // percentage on its own leaves the reader dividing by a fleet size
            // that appears nowhere on this screen. Returning an ARRAY is what
            // makes Chart.js stack the second line under the first.
            tooltip: function (value, index) {
                var line = ' ' + pctText(value) + ' of drivers on leave';
                var counts = data.onLeave || [];

                // Guarded: an older payload without the count still shows the
                // percentage rather than a broken tooltip.
                if (typeof counts[index] === 'undefined' || counts[index] === null) {
                    return line;
                }
                var n = Number(counts[index]);
                return [line, ' ' + n + ' driver' + (n === 1 ? '' : 's') + ' on leave'];
            }
        },
        returns: {
            title: 'Daily Expected Returns / Returns',
            lines: [{ key: 'expectedReturns', label: 'Expected returns', colour: '#0d9488' }],
            yTitle: 'Drivers expected to return',
            percent: false,
            tooltip: function (value) { return ' ' + value + ' driver(s) expected to return'; }
        },
        expectedLeave: {
            title: 'Daily Expected Leave',
            lines: [{ key: 'expectedLeave', label: 'Expected leave', colour: '#f59e0b' }],
            yTitle: 'Drivers expected to leave',
            percent: false,
            tooltip: function (value) { return ' ' + value + ' driver(s) expected to leave'; }
        }
    };

    var chartInstance = null;

    function drawChart(metricKey) {
        var canvas = document.getElementById('capacityChart');
        var labels = data.labels || [];
        var metric = METRICS[metricKey] || METRICS.onLeave;

        if (!canvas || !labels.length || typeof window.Chart === 'undefined') { return; }

        var multi = metric.lines.length > 1;

        // Same line style for every metric; only a multi-series view swaps
        // the flat fill for per-series colours and shows the legend.
        var datasets = metric.lines.map(function (line) {
            var series = data[line.key] || [];
            var points = labels.map(function (unused, i) {
                return typeof series[i] === 'undefined' ? 0 : series[i];
            });

            return {
                label: line.label,
                data: points,
                borderColor: line.colour,
                backgroundColor: multi ? line.colour : 'rgba(30, 75, 122, .12)',
                borderWidth: 2,
                tension: 0.3,
                fill: !multi,
                pointRadius: labels.length > 62 ? 0 : 3,
                pointHitRadius: 10,
                pointBackgroundColor: line.colour
            };
        });

        var peak = 0;
        datasets.forEach(function (ds) {
            ds.data.forEach(function (v) { peak = Math.max(peak, v); });
        });

        // Percent view: the ceiling hugs the peak instead of sitting at
        // 100% - single-digit percentages would flatten into a straight
        // line on a fixed 0-100 axis. Rounded up to the next 5, never
        // above the natural 100%. Count views scale the same way but in
        // whole drivers, with no 100 cap.
        var upper = metric.percent
            ? Math.min(100, Math.max(5, Math.ceil(((peak || 0) * 1.25) / 5) * 5))
            : Math.max(1, Math.ceil((peak || 0) * 1.25));

        // Only the TICK TEXT is thinned on a long range - the data points
        // are never sampled, so a spike on any single day still draws.
        var step = Math.max(1, Math.ceil(labels.length / 12));

        if (chartInstance) {
            chartInstance.destroy();
            chartInstance = null;
        }

        // The screen reader hears the selected series, same as the eye...
        canvas.setAttribute('aria-label', metric.yTitle + ' per day');

        // ...and the card title names what is on screen right now.
        var titleEl = document.getElementById('capacityChartTitleText');
        if (titleEl) {
            titleEl.textContent = metric.title;
        }

        chartInstance = new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    // One line keeps the old look: the legend appears only
                    // once there is more than one series to name.
                    legend: { display: multi },
                    tooltip: {
                        callbacks: {
                            title: function (items) {
                                if (!items.length) { return ''; }
                                return fmtDate(labels[items[0].dataIndex]);
                            },
                            label: function (ctx) {
                                if (metric.tooltip) {
                                    // The index is what lets a tooltip reach a
                                    // companion series - the headcount that a
                                    // percentage is standing for - without the
                                    // metric having to look it up itself.
                                    return metric.tooltip(ctx.parsed.y, ctx.dataIndex);
                                }
                                return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            autoSkip: false,
                            maxRotation: 0,
                            callback: function (value, index) {
                                return index % step === 0 ? shortDate(labels[index]) : '';
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        // No fixed max: the axis stops just above the peak,
                        // which is what makes a 4% -> 7% movement visible.
                        suggestedMax: upper,
                        title: {
                            display: true,
                            text: metric.yTitle
                        },
                        ticks: {
                            precision: 0,
                            callback: function (value) {
                                return metric.percent ? pctText(value) : value;
                            }
                        },
                        grid: { color: '#eef2f7' }
                    }
                }
            }
        });
    }

    // The dropdown swaps the series only - range, mode indicators and the
    // card layout belong to the page and never move with it.
    var metricSelect = document.getElementById('capacityChartMetric');

    if (metricSelect) {
        metricSelect.addEventListener('change', function () {
            drawChart(metricSelect.value);
        });
    }

    /* --------------------------------------------------------- drill-down -- */

    function queryString(params) {
        var parts = [];
        Object.keys(params).forEach(function (key) {
            var value = params[key];
            if (value === '' || value === null || value === undefined) { return; }
            parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
        });
        return parts.join('&');
    }

    function setModalState(state) {
        var loading = document.getElementById('capModalLoading');
        var error   = document.getElementById('capModalError');
        var table   = document.getElementById('capModalTable');
        var empty   = document.getElementById('capModalEmpty');
        var pager   = document.getElementById('capModalPager');

        if (!loading) { return; }

        loading.classList.toggle('d-none', state !== 'loading');
        error.classList.add('d-none');
        table.hidden = state !== 'table';
        empty.classList.toggle('d-none', state !== 'empty');

        // The pager describes rows that are on screen, so it leaves with the
        // table. renderPager() puts it back when there is more than one page.
        // It toggles d-flex / d-none rather than [hidden], which Bootstrap's
        // .d-flex (`display: flex !important`) outranks - the pair could never
        // actually hide it.
        if (pager && state !== 'table') {
            pager.classList.remove('d-flex');
            pager.classList.add('d-none');
        }

        if (state === 'error') { error.classList.remove('d-none'); }
    }

    // The tone and glyph for a status. The server sends them per row
    // (badge_class / badge_icon, from helpers/functions.php) so the modal
    // and Capacity Movement share one palette; the map below is only a
    // fallback for a payload that predates those keys.
    function badgeClass(status) {
        var known = {
            planned: 'secondary', on_leave: 'primary', returned: 'success',
            overdue: 'danger', cancelled: 'dark', not_returned: 'warning',
            pending: 'secondary', approved: 'success', rejected: 'danger',
            not_started: 'secondary', expected: 'info'
        };
        return 'text-bg-' + (known[status] || 'secondary');
    }

    function badgeIcon(status) {
        var known = {
            planned: 'bi-calendar-event', on_leave: 'bi-airplane',
            returned: 'bi-check-circle-fill', overdue: 'bi-exclamation-triangle-fill',
            cancelled: 'bi-slash-circle', not_returned: 'bi-person-x',
            pending: 'bi-clock-history', approved: 'bi-check-circle-fill',
            rejected: 'bi-x-circle', not_started: 'bi-hourglass-split',
            expected: 'bi-calendar-check'
        };
        return known[status] || 'bi-circle';
    }

    /**
     * One chip, shaped exactly like the server-rendered status_badge():
     * rounded pill, the shared tone, the glyph, then the label. Overdue
     * wins over the stored status because a record can be past its
     * expected return on this date while its status still reads
     * "on_leave".
     */
    function statusChip(row) {
        var overdue = !!row.is_overdue;
        var status  = overdue ? 'overdue' : (row.status || '');
        var tone    = overdue ? badgeClass('overdue')
                              : (row.badge_class || badgeClass(status));
        var icon    = overdue ? badgeIcon('overdue')
                              : (row.badge_icon || badgeIcon(status));
        var label   = overdue ? 'Overdue' : (row.status_label || 'On leave');

        return '<span class="badge rounded-pill ' + esc(tone) + ' status-badge">'
            + '<i class="bi ' + esc(icon) + ' me-1"></i>' + esc(label) + '</span>';
    }

    function renderRows(rows) {
        var body = document.getElementById('capModalBody');
        if (!body) { return; }

        // Row numbers run on across pages, the same way they do in the other
        // two drill modals: page 2 starts at size + 1, so "#14" means the
        // fourteenth driver of that day and the same row in the Excel file.
        var offset = (drill.page - 1) * drill.size;

        body.innerHTML = rows.map(function (row, i) {
            var driverUrl = (urls.driver || 'drivers/') + encodeURIComponent(row.driver_id);
            var status = statusChip(row);

            return '<tr>'
                + '<td class="text-center">' + (offset + i + 1) + '</td>'
                + '<td class="text-nowrap"><a class="link-dark text-decoration-none fw-semibold" href="'
                + esc(driverUrl) + '">' + esc(row.driver_name) + '</a></td>'
                + '<td class="text-nowrap">' + esc(row.badge_number) + '</td>'
                + '<td class="text-nowrap">' + esc(row.leave_type) + '</td>'
                + '<td class="text-nowrap">' + esc(fmtDate(row.leave_start_date)) + '</td>'
                + '<td class="text-nowrap">' + esc(fmtDate(row.expected_arrival_date)) + '</td>'
                // No Actual column: a driver with an arrival on record is
                // already out of this list (endFor() ends their away window at
                // min(actual, expected)), so the cell would always read "—".
                + '<td>' + status + '</td>'
                + '</tr>';
        }).join('');
    }

    /**
     * The modal pages through ONE date (round 6): a long trip shows on every
     * day it covers, so a busy day is far longer than the ten rows drawn.
     * `count` is how many drivers are away that day - the number the eye icon
     * stands for - and `rows` is only the page on screen.
     */
    var drill = { date: '', page: 1, size: 10 };

    function renderPager(payload) {
        var pager = document.getElementById('capModalPager');
        var label = document.getElementById('capModalPageLabel');
        var prev  = document.getElementById('capModalPrev');
        var next  = document.getElementById('capModalNext');
        var meta  = document.getElementById('capModalMeta');

        var page  = Number(payload.page)  || 1;
        var pages = Number(payload.pages) || 1;
        var total = Number(payload.count) || 0;
        var on    = pages > 1;

        // The sub-heading: date and driver count, in the shape the other two
        // drill modals use. It lives here rather than in the title so it can
        // carry the count, which only arrives with the rows.
        if (meta) {
            meta.textContent = fmtDate(drill.date) + ' \u00b7 ' + total
                + ' driver' + (total === 1 ? '' : 's');
            meta.classList.remove('d-none');
        }

        // d-flex XOR d-none, never both: Bootstrap's .d-flex is
        // `display: flex !important` and outranks the [hidden] attribute.
        if (pager) {
            pager.classList.toggle('d-flex', on);
            pager.classList.toggle('d-none', !on);
        }

        // The same wording the other two use. The old "Showing 1-10 of 25"
        // lived beside it in the footer - one list, one count.
        if (label) {
            label.textContent = on
                ? 'Page ' + page + ' of ' + pages + ' \u00b7 ' + total + ' row(s)'
                : '';
        }
        if (prev) { prev.disabled = page <= 1; }
        if (next) { next.disabled = page >= pages; }
    }

    function loadDrill() {
        var url = urls.drivers + '?date=' + encodeURIComponent(drill.date)
            + '&page=' + drill.page + '&size=' + drill.size;
        var qs  = queryString(query);
        if (qs) { url += '&' + qs; }

        setModalState('loading');
        setDayExport(null);

        fetch(url)
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                if (!payload || !payload.ok) {
                    throw new Error((payload && payload.message) || 'Request failed.');
                }

                // The server clamps an out-of-range page; keep our own copy in
                // step so Prev/Next start from where we actually landed.
                drill.page = Number(payload.page) || 1;

                var rows = payload.rows || [];
                if (!rows.length) {
                    setModalState('empty');
                    return;
                }

                renderRows(rows);
                setModalState('table');
                renderPager(payload);
                // The same URL the fetch used, plus the export flag: the file
                // carries the WHOLE day, not this page of it.
                setDayExport(url + '&export=xls');
                // The modal grid's tbody was just replaced, so re-read the
                // cells the column prefs holds - otherwise a hidden column
                // reappears the next time this modal opens.
                if (window.ddPrefsBoot) { window.ddPrefsBoot(); }
            })
            .catch(function () {
                setModalState('error');
            });
    }

    /**
     * The Excel button for this modal. Withdrawn while a day loads and handed
     * back once rows exist, so it can never download the previous day's list.
     * The endpoint carries the WHOLE day, not the ten rows on screen.
     */
    function setDayExport(url) {
        var btn = document.getElementById('capModalExport');
        if (!btn) { return; }

        if (url) {
            btn.setAttribute('href', url);
            btn.classList.remove('disabled');
            btn.removeAttribute('aria-disabled');
            btn.removeAttribute('tabindex');
        } else {
            btn.setAttribute('href', '#');
            btn.classList.add('disabled');
            btn.setAttribute('aria-disabled', 'true');
            btn.setAttribute('tabindex', '-1');
        }
    }

    function openDrill(date, page) {
        var modalEl = document.getElementById('capDriversModal');
        if (!modalEl || !window.bootstrap || !urls.drivers) { return; }

        drill.date = String(date || '');
        drill.page = Math.max(1, Number(page) || 1);

        // The sub-heading is written when the rows land (renderPager), so the
        // day you just left never shows its count under this day's title.
        var meta = document.getElementById('capModalMeta');
        if (meta) { meta.classList.add('d-none'); }

        setDayExport(null);

        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        loadDrill();
    }

    // One delegated listener covers every row of the day-by-day table.
    document.addEventListener('click', function (event) {
        var target = event.target;
        var button = target.closest ? target.closest('.cap-drill') : null;
        if (!button) { return; }

        event.preventDefault();
        openDrill(button.getAttribute('data-date'), 1);
    });

    // The modal's own pager - it only reloads the rows, never the page.
    var modalPrev = document.getElementById('capModalPrev');
    var modalNext = document.getElementById('capModalNext');

    if (modalPrev) {
        modalPrev.addEventListener('click', function () {
            if (drill.page > 1) { drill.page -= 1; loadDrill(); }
        });
    }
    if (modalNext) {
        modalNext.addEventListener('click', function () {
            drill.page += 1; loadDrill();
        });
    }

    /* ------------------------------------------- range card drill-down --- */

    // "Expected Returns" and "Expected Resignations" on the forecast open
    // their rows HERE instead of navigating away from the page. They use the
    // same endpoint as the Leave Dashboard's own cards
    // (/api/dashboard/kpi-drivers), so both screens list the same drivers for
    // the same range - which is the only reason either number is checkable.
    var kpi = { key: '', title: '', range: '', page: 1, size: 10, url: '' };

    // The same mapping dashboard.js uses, including "active": Address Book
    // colours that value text-bg-success, so it may not read grey here.
    function kpiTone(status) {
        switch (status) {
            case 'approved': case 'returned': case 'active': return 'success';
            case 'pending': case 'planned':                   return 'info';
            case 'on_leave':                                  return 'primary';
            case 'overdue': case 'rejected':                  return 'danger';
            default:                                          return 'secondary';
        }
    }

    /** Show exactly one of loading / error / empty / the table. */
    function kpiState(state, message) {
        var map = { loading: 'capKpiLoading', error: 'capKpiError', empty: 'capKpiEmpty' };

        Object.keys(map).forEach(function (id) {
            var el = document.getElementById(id);
            if (el) { el.classList.toggle('d-none', id !== state); }
        });

        if (state === 'error') {
            var err = document.getElementById('capKpiError');
            if (err) { err.textContent = message || 'Could not load the rows.'; }
        }

        var wrap = document.getElementById('capKpiWrap');
        if (wrap) { wrap.hidden = (state !== 'table'); }
    }

    function kpiPager(payload) {
        var pager = document.getElementById('capKpiPager');
        if (!pager) { return; }

        var page  = Number(payload.page)  || 1;
        var pages = Number(payload.pages) || 1;
        var total = Number(payload.total) || 0;
        var on    = pages > 1;

        // d-flex XOR d-none, never both: Bootstrap's .d-flex is
        // `display: flex !important` and outranks the [hidden] attribute, so
        // the pair can never actually hide anything.
        pager.classList.toggle('d-flex', on);
        pager.classList.toggle('d-none', !on);

        var label = document.getElementById('capKpiPageLabel');
        if (label) {
            label.textContent = on
                ? 'Page ' + page + ' of ' + pages + ' \u00b7 ' + total + ' row(s)'
                : '';
        }

        var prev = document.getElementById('capKpiPrev');
        var next = document.getElementById('capKpiNext');
        if (prev) { prev.disabled = page <= 1; }
        if (next) { next.disabled = page >= pages; }
    }

    function kpiRender(rows) {
        var table = document.getElementById('capKpiTable');
        if (!table || !table.tBodies.length) { return; }

        var body = table.tBodies[0];
        body.textContent = '';

        // Row numbers run on across pages, exactly as they do on the
        // dashboard, so "#14" means the same row in both places and in the
        // Excel file the footer button downloads.
        var offset = (kpi.page - 1) * kpi.size;

        rows.forEach(function (row, i) {
            var driverUrl = (urls.driver || 'drivers/') + encodeURIComponent(row.driver_id);
            var branch = row.branch_name
                ? row.branch_name
                : (row.branch_code && row.branch_code !== '-' ? row.branch_code : 'No branch');
            var date   = row.event_date_fmt || row.event_date || '';
            var status = String(row.status || '').replace(/_/g, ' ');

            var tr = document.createElement('tr');

            var td = function (text, cls) {
                var cell = document.createElement('td');
                cell.className = cls || '';
                cell.textContent = text;
                tr.appendChild(cell);
                return cell;
            };

            td(String(offset + i + 1), 'text-center');
            td(row.badge_number || '\u2014', 'text-nowrap');

            var nameTd = td('', 'fw-semibold');
            var a = document.createElement('a');
            a.className = 'link-dark text-decoration-none';
            a.href = driverUrl;
            a.textContent = row.driver_name || '\u2014';
            nameTd.appendChild(a);

            td(branch, 'text-nowrap');
            td(date || '\u2014', 'text-nowrap');

            var statusTd = td('', '');
            var badge = document.createElement('span');
            badge.className = 'badge text-bg-' + kpiTone(row.status);
            badge.textContent = status || '\u2014';
            statusTd.appendChild(badge);

            body.appendChild(tr);
        });
    }

    /** Withdraw the download before a new card loads - never the old slice. */
    function kpiWithdrawExport() {
        var btn = document.getElementById('capKpiExport');
        if (!btn) { return; }

        btn.setAttribute('href', '#');
        btn.classList.add('disabled');
        btn.setAttribute('aria-disabled', 'true');
        btn.setAttribute('tabindex', '-1');
    }

    function kpiGrantExport() {
        var btn = document.getElementById('capKpiExport');
        if (!btn) { return; }

        btn.setAttribute('href', kpi.url + '&export=xls');
        btn.classList.remove('disabled');
        btn.removeAttribute('aria-disabled');
        btn.removeAttribute('tabindex');
    }

    function kpiLoad() {
        var base = (urls.kpi || '') + '?kpi=' + encodeURIComponent(kpi.key)
            + '&page=' + kpi.page + '&size=' + kpi.size;

        // Range cards were built from the chosen window, so they send it:
        // otherwise the modal would list some other period than the number.
        var parts = (kpi.range || '').split(',');
        if (parts.length === 2 && parts[0] && parts[1]) {
            base += '&from=' + encodeURIComponent(parts[0])
                  + '&to='   + encodeURIComponent(parts[1]);
        }

        kpi.url = base;
        kpiWithdrawExport();
        kpiState('loading');

        fetch(kpi.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) { return response.json().catch(function () { return {}; }); })
            .then(function (payload) {
                if (!payload || !payload.ok) {
                    kpiState('error', (payload && payload.error) || 'Request failed.');
                    return;
                }

                kpi.page = Number(payload.page) || 1;
                kpi.size = Number(payload.size) || kpi.size;

                var total = Number(payload.total) || 0;
                var meta  = document.getElementById('capKpiMeta');

                if (meta) {
                    var span = (kpi.range || '').split(',');
                    var rangeLabel = span.length === 2 && span[0] && span[1]
                        ? fmtDate(span[0]) + ' \u2013 ' + fmtDate(span[1])
                        : (kpi.title || '');
                    meta.textContent = rangeLabel + ' \u00b7 ' + total
                        + ' driver' + (total === 1 ? '' : 's');
                    meta.classList.remove('d-none');
                }

                if (!total || !(payload.rows || []).length) {
                    kpiState('empty');
                    kpiPager(payload);
                    return;
                }

                kpiRender(payload.rows);
                kpiState('table');
                kpiPager(payload);
                kpiGrantExport();
            })
            .catch(function () {
                kpiState('error', 'Could not reach the server.');
            });
    }

    function openKpi(key, range, title) {
        var modalEl = document.getElementById('capKpiModal');
        if (!modalEl || !window.bootstrap || !key || !urls.kpi) { return; }

        kpi.key   = key;
        kpi.range = range || '';
        kpi.title = title || 'Drivers';
        kpi.page  = 1;

        var titleEl = document.getElementById('capKpiTitle');
        if (titleEl) { titleEl.textContent = kpi.title; }

        var meta = document.getElementById('capKpiMeta');
        if (meta) { meta.classList.add('d-none'); }

        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        kpiLoad();
    }

    // One delegated listener for both cards, alongside the existing
    // .cap-drill one - a second mechanism would drift from the first.
    document.addEventListener('click', function (event) {
        var target = event.target;
        var link = target.closest ? target.closest('.cap-kpi') : null;
        if (!link) { return; }

        event.preventDefault();

        var card  = link.closest('.kpi-card');
        var label = card ? card.querySelector('.kpi-label') : null;

        openKpi(link.getAttribute('data-kpi-card') || '',
                link.getAttribute('data-kpi-range') || '',
                label ? label.textContent.trim() : '');
    });

    var kpiPrev = document.getElementById('capKpiPrev');
    var kpiNext = document.getElementById('capKpiNext');

    if (kpiPrev) {
        kpiPrev.addEventListener('click', function () {
            if (kpi.page > 1) { kpi.page -= 1; kpiLoad(); }
        });
    }
    if (kpiNext) {
        kpiNext.addEventListener('click', function () {
            kpi.page += 1; kpiLoad();
        });
    }

    drawChart(metricSelect ? metricSelect.value : 'onLeave');
}());
