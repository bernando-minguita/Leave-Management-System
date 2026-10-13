/**
 * Dashboard "Build Summary Report" export.
 *
 * Everything numeric in the PDF was computed in PHP and is already sitting in
 * the off-screen #summaryReport sheet - this file only draws the two charts
 * and hands the sheet to html2pdf.js. No business rule is decided here, so
 * the downloaded figures cannot disagree with the dashboard.
 *
 * The charts are created with responsive:false, a fixed pixel size and
 * animation off. That combination is what stops them exporting blank: with
 * animation on, html2canvas captures an in-progress frame, and with
 * responsive on, Chart.js can resize the canvas mid-capture.
 */
(function () {
    'use strict';

    var button = document.getElementById('buildSummaryReport');
    var sheet  = document.getElementById('summaryReport');

    if (!button || !sheet) { return; }

    if (typeof window.Chart === 'undefined' || typeof window.html2pdf === 'undefined') {
        // The bundle is only loaded for users who may export, so a failure
        // here means something broke rather than "not allowed" - say so
        // instead of letting the click fail silently.
        button.disabled = true;
        button.title = 'PDF export is unavailable. Refresh the page and try again.';
        if (window.FL) { window.FL.toast('warning', button.title); }
        return;
    }

    var data = window.REPORT_DATA || {};
    var charts = [];
    var busy = false;

    /**
     * Colour for row i. REPORT_DATA.colors is resolved server-side per branch
     * name, so the doughnut slice and the table marker always agree even if
     * the rows sort differently between the two. The palette is only a
     * fallback for a payload that predates that change.
     */
    function colourAt(i) {
        if (data.colors && data.colors[i]) { return data.colors[i]; }
        var palette = data.colors && data.colors.length ? data.colors : ['#1e4b7a'];
        return palette[i % palette.length];
    }

    function destroyCharts() {
        charts.forEach(function (c) { try { c.destroy(); } catch (e) { /* already gone */ } });
        charts = [];
    }

    /**
     * Set the canvas drawing buffer to the box it sits in BEFORE Chart.js
     * measures it. With responsive:false Chart.js reads the width/height
     * attributes, so a fixed 350 would either overflow a ~326px card or be
     * squeezed by the CSS. Measuring the box keeps bitmap and box identical.
     */
    function sizeCanvas(id) {
        var canvas = document.getElementById(id);
        if (!canvas || !canvas.parentNode) { return null; }

        var box = canvas.parentNode;
        canvas.width = Math.max(160, Math.round(box.clientWidth));
        canvas.height = Math.max(120, Math.round(box.clientHeight));

        return canvas;
    }

    function agencyChart() {
        var canvas = sizeCanvas('rptAgencyChart');
        var rows = data.breakdown || [];

        if (!canvas) { return; }
        if (!rows.length) {
            canvas.parentNode.innerHTML =
                '<div class="rpt-note" style="text-align:center;padding-top:80px">' +
                'No drivers are currently on leave.</div>';
            return;
        }

        charts.push(new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: rows.map(function (r) { return r.label; }),
                datasets: [{
                    data: rows.map(function (r) { return r.total; }),
                    backgroundColor: rows.map(function (r, i) { return colourAt(i); }),
                    borderColor: '#ffffff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: false,
                maintainAspectRatio: false,
                animation: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 10, boxHeight: 10, padding: 8, font: { size: 10 } }
                    }
                }
            }
        }));
    }

    function trendChart() {
        var canvas = sizeCanvas('rptTrendChart');
        var rows = data.trend || [];

        if (!canvas) { return; }
        if (!rows.length) {
            canvas.parentNode.innerHTML =
                '<div class="rpt-note" style="text-align:center;padding-top:80px">' +
                'No leave records in the selected period.</div>';
            return;
        }

        charts.push(new Chart(canvas, {
            type: 'line',
            data: {
                labels: rows.map(function (r) { return r.label; }),
                datasets: [{
                    label: 'Leave records',
                    data: rows.map(function (r) { return r.total; }),
                    borderColor: '#1e4b7a',
                    backgroundColor: 'rgba(30, 75, 122, .12)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointRadius: 3,
                    pointBackgroundColor: '#1e4b7a'
                }]
            },
            options: {
                responsive: false,
                maintainAspectRatio: false,
                animation: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10 }, maxRotation: 0, autoSkip: true } },
                    y: { beginAtZero: true, ticks: { precision: 0, font: { size: 10 } }, grid: { color: '#eef2f7' } }
                }
            }
        }));
    }

    /**
     * Let Chart.js paint before the canvas is photographed.
     *
     * A timer rather than requestAnimationFrame: rAF is throttled to a halt
     * in a background tab, so starting the export and then switching away
     * would stall the build with the button still showing "Building PDF...".
     */
    function paint() {
        return new Promise(function (resolve) { setTimeout(resolve, 60); });
    }

    function setBusy(on) {
        if (on) {
            button.dataset.idle = button.innerHTML;
            button.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1" role="status" ' +
                'aria-hidden="true"></span>Building PDF…';
        } else if (button.dataset.idle) {
            button.innerHTML = button.dataset.idle;
        }

        // Toggled last/first either way, the disabled flag is what stops a
        // second click queueing a second PDF while this one is building.
        button.disabled = on;
    }

    button.addEventListener('click', async function () {
        if (busy) { return; }
        busy = true;
        setBusy(true);

        try {
            destroyCharts();
            agencyChart();
            trendChart();
            await paint();

            var today = sheet.getAttribute('data-today') || new Date().toISOString().slice(0, 10);

            await html2pdf().set({
                // Left/right margins are 0 so the capture container is the
                // full 210mm - exactly the sheet's width. With 8mm side
                // margins the container is 194mm/733px and the wider sheet is
                // clipped, which is what cut the right-hand columns off. The
                // visible gutter comes from .rpt-sheet's own 10mm padding.
                margin: [10, 0, 10, 0],
                filename: 'driver_leave_report_' + today + '.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: {
                    scale: 2,
                    useCORS: true,
                    backgroundColor: '#ffffff',
                    logging: false,
                    // The sheet already sits off-screen, so capture it there
                    // rather than scrolling it into view first.
                    scrollX: 0,
                    scrollY: 0,
                    windowWidth: document.documentElement.clientWidth
                },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
                pagebreak: {
                    mode: ['css', 'legacy'],
                    // Nothing may straddle a page boundary.
                    avoid: ['.rpt-head', '.rpt-kpi', '.rpt-chart', '.rpt-notes', '.rpt-table tfoot', 'tr']
                }
            }).from(sheet).save();

            if (window.FL) {
                window.FL.toast('success', 'Driver leave report downloaded.');
            }
        } catch (err) {
            if (window.FL) {
                window.FL.toast('danger', 'Could not build the PDF. Please try again.');
            }
        } finally {
            destroyCharts();
            setBusy(false);
            busy = false;
        }
    });

})();
