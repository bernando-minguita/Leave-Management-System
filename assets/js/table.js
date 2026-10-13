/**
 * Table engine - sorting, paging and rows-per-page that happen in place.
 *
 * Every list used to reload the page for the smallest change, which threw the
 * reader back to the top and made the screen jump. This script takes over the
 * markup the server already rendered - it never re-creates a cell - and does
 * the three mechanical jobs locally:
 *
 *   - click a sortable header  -> reorder the rows, and write ?sort=&dir= so
 *                                 the choice is still there after the next GET
 *   - click a page link        -> show a different slice
 *   - change Rows [10..250]    -> re-slice without submitting
 *
 * Everything still works without JavaScript: the headers and page links are
 * real URLs and the size select falls back to submitting its form. The server
 * stays the source of the data, the filters and the exports.
 *
 * Sort persistence is URL-based: the engine reads ?sort=&dir= at boot
 * (readSortFromUrl) and writes it back after every sort (syncUrl), and the
 * filter forms carry it through with hold_sort() in app/helpers. That is what
 * makes the order survive Apply, the chips, the pager and a reload.
 *
 * Convention (set by sort_header() and pagination() in app/helpers):
 *   <table data-dd-table data-dd-size="10" data-dd-initial="key|dir">
 *   <th data-dd-sort="key">       a sortable header
 *   <tr data-dd-row>              a data row (the empty-state row has none)
 *   <div data-dd-pager> <div data-dd-showing> <nav data-dd-nav> <select data-dd-size>
 */
(function () {
    'use strict';

    var MONTHS = {
        jan: 0, feb: 1, mar: 2, apr: 3, may: 4, jun: 5,
        jul: 6, aug: 7, sep: 8, oct: 9, nov: 10, dec: 11
    };

    /**
     * Classify displayed text so dates sort as dates and numbers as numbers.
     * Dates arrive already formatted ("05 Mar 2026"), so they are parsed here
     * rather than left to sort as words.
     */
    /**
     * Whether an ambiguous A/B/YYYY column reads day-first. The display date
     * format is a Settings value, so both orders are possible; the engine
     * settles it per sort from the column being sorted.
     */
    var DAY_FIRST = true;

    function rank(text) {
        var s = String(text === null || text === undefined ? '' : text).trim();

        if (s === '' || s === '\u2014' || s === '-') {
            return { kind: 2, num: 0, text: '' };
        }

        var iso = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
        if (iso) {
            return { kind: 0, num: Date.UTC(+iso[1], +iso[2] - 1, +iso[3]), text: s };
        }

        var human = /^(\d{1,2})\s+([A-Za-z]{3})[a-z]*\s+(\d{4})$/.exec(s);
        if (human && MONTHS[human[2].toLowerCase()] !== undefined) {
            return {
                kind: 0,
                num: Date.UTC(+human[3], MONTHS[human[2].toLowerCase()], +human[1]),
                text: s
            };
        }

        // 01/10/2026 - both orders are recognised. A part greater than 12
        // settles the column outright; an ambiguous one reads day-first, which
        // is the application's own default (d M Y).
        var slash = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(s);
        if (slash) {
            var first = +slash[1];
            var second = +slash[2];

            if (first > 12) {
                return { kind: 0, num: Date.UTC(+slash[3], second - 1, first), text: s };
            }
            if (second > 12) {
                return { kind: 0, num: Date.UTC(+slash[3], first - 1, second), text: s };
            }
            return {
                kind: 0,
                num: Date.UTC(+slash[3], (DAY_FIRST ? first : second) - 1,
                    DAY_FIRST ? second : first),
                text: s
            };
        }

        var num = parseFloat(s.replace(/,/g, ''));
        if (!isNaN(num) && /^-?[\d,.]+\s*%?$/.test(s)) {
            return { kind: 1, num: num, text: s };
        }

        return { kind: 2, num: 0, text: s.toLowerCase() };
    }

    /**
     * Set DAY_FIRST from the column about to be sorted: one value past 12 in
     * either position decides every row in it.
     */
    function detectDayFirst(values) {
        for (var i = 0; i < values.length; i++) {
            var m = /^(\d{1,2})\/(\d{1,2})\/\d{4}$/.exec(String(values[i]).trim());
            if (!m) {
                continue;
            }
            if (+m[1] > 12) {
                DAY_FIRST = true;
                return;
            }
            if (+m[2] > 12) {
                DAY_FIRST = false;
                return;
            }
        }
    }

    function compare(a, b, dir) {
        var x = rank(a);
        var y = rank(b);
        var r;

        if (x.kind === 2 || y.kind === 2) {
            r = x.text < y.text ? -1 : (x.text > y.text ? 1 : 0);
        } else {
            r = x.num - y.num;
        }

        return dir === 'desc' ? -r : r;
    }

    /* ---------------------------------------------------------------- state -- */

    function Engine(table) {
        this.table = table;
        this.body = table.tBodies[0];

        if (!this.body) {
            return;
        }

        var self = this;

        // Rows the server rendered. The empty-state row carries no marker, so
        // it takes no part in paging - it shows only when there is nothing at
        // all to show.
        this.rows = [];
        this.emptyRow = null;

        Array.prototype.forEach.call(this.body.rows, function (row) {
            if (row.hasAttribute('data-dd-row')) {
                self.rows.push(row);
            } else {
                self.emptyRow = row;
            }
        });

        this.headers = Array.prototype.slice.call(table.querySelectorAll('th[data-dd-sort]'));

        var parts = (table.getAttribute('data-dd-initial') || '').split('|');
        this.sort = parts[0] || '';
        this.dir = parts[1] === 'desc' ? 'desc' : 'asc';
        // The third slot is the page the URL asked for, so a bookmarked
        // ?page=3 still lands on page 3 after the engine takes over.
        this.page = parseInt(parts[2], 10) || 1;
        this.size = parseInt(table.getAttribute('data-dd-size'), 10) || 10;

        // The address bar is the other half of the sort state (see syncUrl).
        this.readSortFromUrl();

        // Scope every lookup to the table's own card: a page can hold several
        // grids (Expected Returns renders five), and the table itself carries
        // data-dd-size - a document-wide query would return the <table>
        // instead of the Rows select and break the size handler.
        this.scope = table.closest('.card') || document;
        this.pager = this.scope.querySelector('[data-dd-pager]');
        this.showing = this.pager ? this.pager.querySelector('[data-dd-showing]') : null;
        this.nav = this.pager ? this.pager.querySelector('[data-dd-nav]') : null;
        this.sizeSelect = this.pager ? this.pager.querySelector('[data-dd-size]') : null;

        // The "#" column: renumbered after every sort so the index describes
        // what is on screen, not the order the server happened to send.
        var head = table.tHead;
        this.indexCol = (head && head.rows[0] && head.rows[0].cells[0]
            && head.rows[0].cells[0].textContent.trim() === '#') ? 0 : -1;
    }

    Engine.prototype.start = function () {
        var self = this;

        this.wireHeaders();
        this.wireSize();

        // Delegated on the pager rather than the nav, because the nav may not
        // exist yet: pagination() only emits one when the server already knew
        // there was more than a page, and a Rows change can create it later.
        if (this.pager) {
            this.pager.addEventListener('click', function (event) {
                var link = event.target && event.target.closest
                    ? event.target.closest('a[data-dd-page]')
                    : null;

                if (!link || !self.pager.contains(link)) {
                    return;
                }

                event.preventDefault();
                self.page = parseInt(link.getAttribute('data-dd-page'), 10) || 1;
                self.apply();
            });
        }

        this.apply();
    };

    /**
     * Read ?sort=&dir= off the address bar.
     *
     * data-dd-initial is what the server rendered for this very request, so on
     * a register the two agree and this changes nothing. What it is for is the
     * other half of the feature: a sort written by syncUrl() (or by a
     * sort_header() link) has to come back after the browser is closed and
     * reopened, and on a screen where only the client ever sorts - Expected
     * Returns renders five boards from one shared query string - the URL is
     * the only record of the choice at all.
     *
     * A key that is not one of THIS table's headers is ignored, so the shared
     * string cannot break a board that does not render that column: it falls
     * back to the order the server sent.
     */
    Engine.prototype.readSortFromUrl = function () {
        if (!this.headers.length) {
            return;
        }

        var params;

        try {
            params = new URLSearchParams(window.location.search);
        } catch (err) {
            return;
        }

        var key = params.get('sort');
        if (!key) {
            return;
        }

        var known = this.headers.some(function (th) {
            return th.getAttribute('data-dd-sort') === key;
        });

        if (!known) {
            return;
        }

        this.sort = key;
        this.dir = params.get('dir') === 'desc' ? 'desc' : 'asc';
    };

    /**
     * Put the sort back in the address bar after a click.
     *
     * Without this the whole feature is a dead end: sorting never leaves the
     * page, and the next GET - Apply, a status chip, the pager, a reload -
     * rebuilds from a query string that has no sort in it, so the server falls
     * back to its default and re-seeds the engine with that instead.
     *
     * replaceState, not pushState: sorting a column is not a navigation, and
     * Back must keep meaning "leave this screen". page (and a stale export)
     * go at the same time, because a new sort starts again at page 1 - which
     * is exactly what sort_header()'s links do.
     */
    Engine.prototype.syncUrl = function () {
        if (!this.sort || !this.headers.length
                || !window.history || !window.history.replaceState) {
            return;
        }

        var params;

        try {
            params = new URLSearchParams(window.location.search);
        } catch (err) {
            return;
        }

        params.set('sort', this.sort);
        params.set('dir', this.dir);
        params.delete('page');
        params.delete('export');

        var query = params.toString();
        var next = query === '' ? '' : '?' + query;

        if (next === window.location.search) {
            return;
        }

        try {
            window.history.replaceState(null, '',
                window.location.pathname + next + window.location.hash);
        } catch (err) {
            // A host that forbids history writes (file://, some embedded
            // webviews) still gets the sorted grid - just not the URL.
        }
    };

    Engine.prototype.wireHeaders = function () {
        var self = this;

        this.headers.forEach(function (th) {
            if (!th.hasAttribute('tabindex')) {
                th.setAttribute('tabindex', '0');
            }

            var run = function (event) {
                if (event) {
                    event.preventDefault();
                }

                var key = th.getAttribute('data-dd-sort');
                self.dir = (self.sort === key && self.dir === 'asc') ? 'desc' : 'asc';
                self.sort = key;
                self.page = 1;
                self.apply();

                // The sort has left JS memory and gone into the address bar,
                // so the next GET carries it (see syncUrl).
                self.syncUrl();
            };

            // ONE toggle per click. The handler lives on the <th> alone. The
            // anchor inside it is a real URL for the no-JS fallback, and a
            // click there bubbles up to the <th> - so binding `run` to BOTH
            // fired it twice per click. Two toggles of
            //   dir = (same key && asc) ? desc : asc
            // always land on 'desc': the first click sorted descending, and
            // every click after that toggled asc and straight back to desc.
            // Ascending was unreachable from the mouse.
            th.addEventListener('click', run);

            // Cancel the navigation only - never the sort. Leaving the bubble
            // to reach the <th> is what keeps the count at exactly one.
            var link = th.querySelector('a');
            if (link) {
                link.addEventListener('click', function (event) {
                    event.preventDefault();
                });
            }

            th.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    run();
                }
            });
        });
    };

    Engine.prototype.wireSize = function () {
        if (!this.sizeSelect) {
            return;
        }

        this.size = parseInt(this.sizeSelect.value, 10) || this.size;

        var self = this;

        // The inline onchange falls back to submitting the form; defined here
        // it takes over and re-slices in place.
        window.ddTableSize = function (el) {
            var engine = el.form && el.form.__ddEngine ? el.form.__ddEngine : self;
            engine.size = parseInt(el.value, 10) || 10;
            engine.page = 1;
            engine.apply();
        };
    };

    /** Reorder the rows the server rendered. */
    Engine.prototype.sortRows = function () {
        if (!this.sort) {
            return;
        }

        // cellIndex, NOT the position in this list: the row also carries
        // columns that cannot be sorted (#, checkboxes, Duration, Actions), so
        // counting only the sortable headers would read the wrong cell - on
        // Leave Records it sorted by the index column itself.
        var index = -1;
        this.headers.forEach(function (th) {
            if (th.getAttribute('data-dd-sort') === this.sort) {
                index = th.cellIndex;
            }
        }, this);

        if (index < 0) {
            return;
        }

        var dir = this.dir;
        var body = this.body;

        // Decide the day/month order of a slash-formatted date column before
        // comparing it, so 25/10 and 10/25 both read the way they were shown.
        detectDayFirst(this.rows.map(function (row) {
            var cell = row.cells[index];
            return cell ? cell.textContent : '';
        }));

        // Sort INTO this.rows. Reordering the DOM alone is not enough: the
        // page slice and the "#" renumbering both read this.rows, so leaving
        // it in the server's order showed a scrambled page and renumbered the
        // index column from the pre-sort order.
        this.rows = this.rows
            .map(function (row) {
                var cell = row.cells[index];
                return { row: row, value: cell ? cell.textContent : '' };
            })
            .sort(function (a, b) {
                var r = compare(a.value, b.value, dir);
                // Stable: rows the server ordered keep that order on a tie.
                return r !== 0 ? r : a.row.__ddOrder - b.row.__ddOrder;
            })
            .map(function (entry) {
                body.appendChild(entry.row);
                return entry.row;
            });
    };

    /** Show one slice, update the "Showing" line and rebuild the page links. */
    Engine.prototype.apply = function () {
        var self = this;
        var total = this.rows.length;
        var pages = Math.max(1, Math.ceil(total / this.size));

        this.page = Math.min(Math.max(1, this.page), pages);

        this.sortRows();

        // Reindex after sorting: the "#" column has to count what the reader
        // can see, so it is rewritten in the current order (1..N across the
        // whole set, which lands as 11-20 on page 2).
        if (this.indexCol === 0) {
            this.rows.forEach(function (row, i) {
                var cell = row.cells[0];
                if (cell) {
                    cell.textContent = String(i + 1);
                }
            });
        }

        var start = (this.page - 1) * this.size;
        var end = Math.min(total, start + this.size);

        this.rows.forEach(function (row, i) {
            row.hidden = i < start || i >= end;
        });

        if (this.emptyRow) {
            this.emptyRow.hidden = total !== 0;
        }

        if (this.showing) {
            this.showing.innerHTML = 'Showing <strong>'
                + (total === 0 ? 0 : start + 1).toLocaleString()
                + '</strong>&ndash;<strong>' + end.toLocaleString()
                + '</strong> of <strong>' + total.toLocaleString() + '</strong>';
        }

        this.renderNav(pages);

        this.headers.forEach(function (th) {
            var on = th.getAttribute('data-dd-sort') === self.sort;
            var icon = th.querySelector('.bi');

            th.setAttribute('aria-sort',
                on ? (self.dir === 'asc' ? 'ascending' : 'descending') : 'none');
            th.classList.toggle('is-sorted', on);

            if (icon) {
                icon.className = 'bi small ' + (on
                    ? (self.dir === 'asc' ? 'bi-sort-up' : 'bi-sort-down')
                    : 'bi-arrow-down-up opacity-50');
            }
        });
    };

    /**
     * pagination() only emits a <nav> when the server already knew there was
     * more than one page. At the default 50 rows, Leave Records and Current
     * Leave are a single page, so there is no nav to fill - and changing Rows
     * to 10 makes four pages out of them. Build the missing nav then, in the
     * same place pagination() would have put it.
     */
    Engine.prototype.buildNav = function () {
        var nav = document.createElement('nav');
        nav.setAttribute('aria-label', 'Table pagination');
        nav.setAttribute('data-dd-nav', '');

        var list = document.createElement('ul');
        list.className = 'pagination pagination-sm mb-0';
        nav.appendChild(list);

        if (this.pager) {
            // Straight after the "Showing" line, before the Rows select.
            var group = this.sizeSelect && this.sizeSelect.parentNode
                ? this.sizeSelect.parentNode
                : null;
            this.pager.insertBefore(nav, group);
        }

        return nav;
    };

    Engine.prototype.renderNav = function (pages) {
        if (!this.nav && this.pager) {
            this.nav = this.buildNav();
        }

        if (!this.nav) {
            return;
        }

        if (pages < 2) {
            this.nav.hidden = true;
            return;
        }

        this.nav.hidden = false;

        var page = this.page;
        var numbers = [];

        for (var i = 1; i <= pages; i++) {
            if (i === 1 || i === pages || Math.abs(i - page) <= 2) {
                numbers.push(i);
            }
        }

        var html = page > 1
            ? '<li class="page-item"><a class="page-link" href="#" data-dd-page="'
              + (page - 1) + '">&lsaquo;</a></li>'
            : '<li class="page-item disabled"><span class="page-link">&lsaquo;</span></li>';

        var previous = null;
        numbers.forEach(function (number) {
            if (previous !== null && number - previous > 1) {
                html += '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }
            html += number === page
                ? '<li class="page-item active"><span class="page-link">' + number + '</span></li>'
                : '<li class="page-item"><a class="page-link" href="#" data-dd-page="'
                  + number + '">' + number + '</a></li>';
            previous = number;
        });

        html += page < pages
            ? '<li class="page-item"><a class="page-link" href="#" data-dd-page="'
              + (page + 1) + '">&rsaquo;</a></li>'
            : '<li class="page-item disabled"><span class="page-link">&rsaquo;</span></li>';

        var list = this.nav.querySelector('.pagination');
        if (list) {
            list.innerHTML = html;
        }
    };

    /* ----------------------------------------------------------------- boot -- */

    function boot() {
        document.querySelectorAll('table[data-dd-table]').forEach(function (table) {
            if (table.__ddEngine) {
                return;
            }

            var engine = new Engine(table);
            if (!engine.body) {
                return;
            }

            table.__ddEngine = engine;

            engine.rows.forEach(function (row, i) {
                row.__ddOrder = i;
            });

            // The Rows select lives inside the pager's own form; hand that
            // form the engine so the global ddTableSize() reaches the right one.
            if (engine.sizeSelect && engine.sizeSelect.form) {
                engine.sizeSelect.form.__ddEngine = engine;
            }

            engine.start();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();


