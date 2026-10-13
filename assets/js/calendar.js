/**
 * Leave calendar (FullCalendar 6, global build).
 *
 * Events come from /api/leave/calendar as ALL-DAY Y-m-d entries so a leave
 * day never shifts by one when the browser is not in UTC+3.
 * Filters live in the offcanvas form and are applied as query params.
 */
(function () {
    'use strict';

    var host = document.getElementById('leaveCalendar');
    if (!host || typeof FullCalendar === 'undefined') {
        if (host) {
            host.innerHTML = '<div class="text-center text-body-secondary py-5">'
                + 'The calendar library did not load.</div>';
        }
        return;
    }

    var feedUrl = window.CALENDAR_URL || 'api/leave/calendar';
    var recordBase = window.CALENDAR_RECORD_URL || 'leave/';

    var filterForm = document.getElementById('calendarFilterForm');
    var activeFilters = {};

    function readFilters() {
        activeFilters = {};
        if (!filterForm) { return; }

        var fields = filterForm.querySelectorAll('select[name], input[name]');
        fields.forEach(function (field) {
            if (field.value) {
                activeFilters[field.name] = field.value;
            }
        });
    }

    function withFilters(url) {
        var params = new URLSearchParams();

        Object.keys(activeFilters).forEach(function (key) {
            params.set(key, activeFilters[key]);
        });

        var query = params.toString();

        return query ? (url + (url.indexOf('?') === -1 ? '?' : '&') + query) : url;
    }

    readFilters();

    var calendar = new FullCalendar.Calendar(host, {
        initialView: 'dayGridMonth',
        initialDate: new Date(),
        height: 'auto',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,listWeek'
        },
        buttonText: {
            today: 'Today',
            month: 'Month',
            list: 'List'
        },
        firstDay: 1,          // Monday - matches an operations week
        nowIndicator: true,
        dayMaxEvents: 4,
        editable: false,
        selectable: false,

        events: function (info, successCallback, failureCallback) {
            var url = feedUrl
                + '?start=' + encodeURIComponent(info.startStr.slice(0, 10))
                + '&end=' + encodeURIComponent(info.endStr.slice(0, 10));

            fetch(withFilters(url))
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data && data.ok) {
                        successCallback(data.events || []);
                    } else {
                        failureCallback(data && data.message
                            ? new Error(data.message) : new Error('Feed failed'));
                    }
                })
                .catch(function (error) { failureCallback(error); });
        },

        // Tooltip + accessible title on every event.
        eventDidMount: function (info) {
            var props = info.event.extendedProps || {};
            info.el.setAttribute('title',
                props.driver + ' - ' + props.leaveType + '\n'
                + 'Badge: ' + props.badge + '\n'
                + 'Expected back: ' + (props.expected || '—') + '\n'
                + 'Status: ' + (props.statusLabel || '')
            );
            info.el.setAttribute('tabindex', '0');
        },

        eventClick: function (info) {
            info.jsEvent.preventDefault();
            window.location.href = recordBase + String(info.event.id);
        }
    });

    calendar.render();

    // Filter offcanvas: apply on submit, clear on reset.
    if (filterForm) {
        filterForm.addEventListener('submit', function (event) {
            event.preventDefault();
            readFilters();
            calendar.refetchEvents();
        });

        var reset = document.getElementById('cfReset');
        if (reset) {
            reset.addEventListener('click', function () {
                window.setTimeout(function () {
                    readFilters();
                    calendar.refetchEvents();
                }, 0);
            });
        }
    }

    // Keep the calendar usable if the window is resized (mobile/desktop swap).
    window.addEventListener('resize', function () {
        calendar.updateSize();
    });
})();
