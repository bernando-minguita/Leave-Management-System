/**
 * Table preferences - show/hide and resize columns, remembered per table.
 *
 * UNIFIED: every table gets the same behaviour, and no table has to ask for it.
 * The script boots off `table` alone, so a table added later joins without any
 * new markup, key, id or anchor - there is nothing per-table left to author.
 *
 * One screen, one "Columns" control. A screen can hold several grids (Expected
 * Returns renders five; Capacity renders two), and readers think of those as
 * one table, so the menu is built ONCE per screen and a change is broadcast to
 * every grid on it - hiding "Destination Country" hides it on all five boards.
 *
 * The menu deals in column LABELS, because that is the only thing two grids
 * genuinely share. Storage stays INDEX-based and PER TABLE, so no existing
 * preference is reinterpreted - a grid with a different column order cannot
 * have index 3 mean two different things:
 *
 *     menu label "Destination Country"  ->  each grid's own index for that label
 *
 * Two settings are kept per table, in localStorage under "lms.tableprefs.<key>":
 *
 *     { "hidden": [3, 5], "widths": { "2": 150, "7": 90 } }
 *
 * The key is the table's data-dd-prefs value when it has one (so the four
 * screens that already shipped keep their saved preferences byte-for-byte),
 * otherwise derived from the screen plus the table's position on it.
 *
 * WHY localStorage and not IndexedDB: these are a handful of small integers
 * per table - a few hundred bytes - read once at boot and written on a
 * toggle. IndexedDB would add an async transaction and a schema version to
 * store less than a single HTTP header, and would make the first paint wait.
 *
 * IMPORTANT: this script never moves, removes or re-creates a cell. It only
 * sets display and width on cells the server rendered, exactly like
 * table.js. Sorting, paging and the exports keep working because a hidden
 * cell is still in the DOM - cellIndex and row.cells are unaffected by
 * display:none, so table.js still sorts a column the reader cannot see
 * (the column menu says so).
 *
 * TIMING: the layout loads this from the <head>, and the stored layout is
 * re-applied from a MutationObserver while the document is still parsing.
 * Observer callbacks are microtasks, so they run before the browser's
 * rendering step - the reader's hidden columns are never painted and then
 * hidden again. See watchWhileParsing() at the bottom.
 *
 * Everything degrades: with no JavaScript the table simply shows every
 * column at its natural width, which is what the server rendered.
 */
(function () {
    'use strict';

    var PREFIX = 'lms.tableprefs.';

    /** Columns the reader must not be able to lose. */
    var LOCKED_LABELS = ['actions'];

    /**
     * Is this header a utility column rather than a piece of data?
     *
     * Two kinds, both detected STRUCTURALLY rather than by name:
     *   - a bulk-select checkbox header (Leave Records, Address Book) - it has
     *     no text at all, which is why it used to surface as "Column 2"
     *   - the Actions column, matched by LOCKED_LABELS
     *
     * Neither belongs in the Columns menu: you cannot sensibly hide a
     * select-all box or the row's own commands, and listing them as
     * untickable rows is noise.
     */
    function isUtilityColumn(th, label) {
        if (th.querySelector('input[type="checkbox"]')) { return true; }
        return LOCKED_LABELS.indexOf(String(label).toLowerCase()) !== -1;
    }

    /* ---------------------------------------------------------- storage -- */

    function load(key) {
        try {
            var raw = localStorage.getItem(PREFIX + key);
            var out = raw ? JSON.parse(raw) : null;
            if (!out || typeof out !== 'object') { return { hidden: [], widths: {} }; }
            return {
                hidden: Array.isArray(out.hidden) ? out.hidden.filter(function (n) {
                    return typeof n === 'number' && isFinite(n);
                }) : [],
                widths: (out.widths && typeof out.widths === 'object') ? out.widths : {}
            };
        } catch (e) {
            // Private browsing, a quota error, or corrupted JSON - carry on
            // with defaults rather than breaking the screen.
            return { hidden: [], widths: {} };
        }
    }

    function save(key, prefs) {
        try {
            localStorage.setItem(PREFIX + key, JSON.stringify(prefs));
        } catch (e) {
            // Nothing we can do; the feature is a convenience, not data.
        }
    }

    /**
     * Has this table ever had a preference stored?
     *
     * The difference matters: a default (a column the server ships hidden) is
     * adopted only while nothing is stored, whereas a stored value is a
     * decision the reader has already made and must win every time after.
     */
    function hasStored(key) {
        try {
            return localStorage.getItem(PREFIX + key) !== null;
        } catch (e) {
            return false;
        }
    }

    /* ------------------------------------------------------------- model -- */

    /**
     * The column's readable name.
     *
     * BUG THIS FIXES: this used to walk only the header's DIRECT text nodes,
     * which returns nothing for every sortable column - sort_header() emits
     *
     *     <th ...><a ...>Driver <i class="bi-..."></i></a></th>
     *
     * so the label lives inside <a>, never at th.childNodes level. The empty
     * string then fell through to the fallback and the menu read
     * "Column 2, Column 3, ..." instead of the real names.
     *
     * textContent takes the label wherever it is nested. The sort icons are
     * font glyphs with no text, so they contribute nothing.
     */
    function labelOf(th) {
        return (th.textContent || '').replace(/\s+/g, ' ').trim();
    }

    /**
     * Which screen this table is on - the unit the control is unified over.
     *
     * The layout publishes the sidebar key (leave-returns, drivers, ...) as a
     * meta tag. Falling back to the path keeps a screen without one working,
     * and the path arrives base-stripped from the server, so /lms/leave/x and
     * /leave/x land on the same key.
     */
    function screenKey() {
        var meta = document.querySelector('meta[name="page-key"]');
        var key = meta ? String(meta.getAttribute('content') || '').trim() : '';

        if (key) { return key; }

        return (window.location.pathname || '/').replace(/\/+$/, '') || '/';
    }

    function Prefs(table, index) {
        this.table = table;
        this.index = index;

        // The screen this table belongs to: ONE control for all of them, and
        // the guard that stops a second bar being built.
        this.key = screenKey();

        // Where THIS table keeps its own numbers. A shipped data-dd-prefs wins
        // so existing readers keep their layout; otherwise derive one.
        this.storeKey = table.getAttribute('data-dd-prefs')
            || (this.key + '#' + index);

        this.prefs = load(this.storeKey);
        this.cells = [];
        this.labels = [];
        this.locked = [];
        this.menu = null;
        this.group = null;
        this.seeded = false;

        this.build();
        this.seedDefaults();
    }

    /**
     * Every cell that belongs to column i, including the header.
     *
     * Rows are read by index (row.cells[i]) rather than by nth-child, so a
     * short row - the empty state, which is a single colspan cell - is
     * skipped instead of having the wrong cell hidden.
     *
     * Re-run on every boot: driver-dashboard re-renders its tbody, which
     * detaches the cells this object once held, and a stale list would
     * silently stop hiding anything on that screen.
     *
     * @return {Array<Array<Element>>}
     */
    Prefs.prototype.cellsByColumn = function () {
        var rows = this.table.rows;
        var cols = [];
        var i;
        var r;

        for (i = 0; i < (this.table.tHead ? this.table.tHead.rows[0].cells.length : 0); i++) {
            cols.push([]);
        }

        for (r = 0; r < rows.length; r++) {
            var row = rows[r];
            for (i = 0; i < row.cells.length && i < cols.length; i++) {
                cols[i].push(row.cells[i]);
            }
        }

        return cols;
    };

    /* ------------------------------------------------------- build + UI -- */

    /** Header texts, and which columns the reader may not hide. */
    Prefs.prototype.build = function () {
        var head = this.table.tHead;
        if (!head || !head.rows.length) { return; }

        var cells = head.rows[0].cells;
        this.labels = [];
        this.locked = [];

        for (var i = 0; i < cells.length; i++) {
            var label = labelOf(cells[i]);
            var utility = isUtilityColumn(cells[i], label);

            // A checkbox header carries no text; give it a name for
            // debugging, knowing it never reaches the menu.
            this.labels.push(label || (utility ? 'Select' : 'Column ' + (i + 1)));
            this.locked.push(utility);
        }

        this.cells = this.cellsByColumn();
    };

    /**
     * Adopt a column the server shipped hidden - once.
     *
     * Auxiliary columns (Last Updated, Created By) are rendered
     * style="display:none" so there is no flash of two extra columns before
     * the script runs. The markup alone is not enough, though: applyHidden()
     * writes an explicit display onto every cell, so on the first paint it
     * would un-hide them again. This copies that server default into the
     * reader's stored preferences - but ONLY while nothing is stored yet, so a
     * choice they go on to make is never overruled by the markup on a reload.
     */
    Prefs.prototype.seedDefaults = function () {
        if (this.seeded) { return; }
        this.seeded = true;

        // A stored value is a decision; a server default is only the starting
        // point before one exists.
        if (hasStored(this.storeKey)) { return; }

        var head = this.table.tHead;
        if (!head || !head.rows.length) { return; }

        var self = this;
        var changed = false;

        Array.prototype.forEach.call(head.rows[0].cells, function (th, i) {
            if (self.locked[i]) { return; }              // never seed a locked column
            if (th.style.display !== 'none') { return; } // only server-shipped defaults
            if (self.prefs.hidden.indexOf(i) !== -1) { return; }

            self.prefs.hidden.push(i);
            changed = true;
        });

        if (changed) { save(this.storeKey, this.prefs); }
    };

    /**
     * A colgroup gives the resizer one element to write to per column instead
     * of hundreds of body cells on every mousemove.
     */
    Prefs.prototype.ensureColgroup = function () {
        var cg = this.table.querySelector('colgroup[data-dd-cols]');
        if (cg) { this.colgroup = cg; return; }

        cg = document.createElement('colgroup');
        cg.setAttribute('data-dd-cols', '');
        for (var i = 0; i < this.labels.length; i++) {
            cg.appendChild(document.createElement('col'));
        }
        // colgroup must precede thead.
        this.table.insertBefore(cg, this.table.firstChild);
        this.colgroup = cg;
    };

    /** The index this table stores a shared menu label under, or -1. */
    Prefs.prototype.indexOfLabel = function (label) {
        return this.labels.indexOf(label);
    };

    Prefs.prototype.isHidden = function (index) {
        return this.prefs.hidden.indexOf(index) !== -1;
    };

    /**
     * Hide/show this table's column and persist it under THIS table's key.
     *
     * The decision itself lives in the group (it speaks labels), so this only
     * ever writes one table's numbers - which is what keeps two grids with
     * different column orders from overwriting each other.
     */
    Prefs.prototype.setHidden = function (index, hidden) {
        // Enforced HERE, not just in the menu: a locked column cannot be
        // hidden by any caller, so a future UI or a stray script cannot take
        // the row actions away.
        if (this.locked[index]) { return; }

        var list = this.prefs.hidden;
        var at = list.indexOf(index);

        if (hidden && at === -1) { list.push(index); }
        if (!hidden && at !== -1) { list.splice(at, 1); }

        save(this.storeKey, this.prefs);
    };

    /** Hide/show every cell of each column, header included. */
    Prefs.prototype.applyHidden = function () {
        var visibleHideable = 0;
        var firstHideable = -1;

        for (var i = 0; i < this.cells.length; i++) {
            var hidden = this.isHidden(i);

            // Locked columns (Actions) are never hidden, so they must not
            // count as "a column is on screen" - otherwise a reader could
            // hide every data column and be left with a row of action
            // buttons and no data behind them.
            if (!this.locked[i]) {
                if (!hidden) { visibleHideable++; }
                if (firstHideable === -1) { firstHideable = i; }
            }

            for (var c = 0; c < this.cells[i].length; c++) {
                this.cells[i][c].style.display = hidden ? 'none' : '';
            }
        }

        // Never leave an empty grid: bring the first hideable column back.
        if (visibleHideable === 0 && firstHideable !== -1) {
            this.prefs.hidden = this.prefs.hidden.filter(function (n) {
                return n !== firstHideable;
            });
            for (var k = 0; k < this.cells[firstHideable].length; k++) {
                this.cells[firstHideable][k].style.display = '';
            }
            save(this.storeKey, this.prefs);
        }
    };

    Prefs.prototype.applyWidths = function () {
        var self = this;
        Object.keys(this.prefs.widths).forEach(function (i) {
            var px = self.prefs.widths[i];
            var idx = parseInt(i, 10);
            if (!isFinite(idx) || !isFinite(px) || px <= 0) { return; }
            var col = self.colgroup ? self.colgroup.children[idx] : null;
            if (col) { col.style.width = px + 'px'; }
            if (self.table.tHead && self.table.tHead.rows[0].cells[idx]) {
                self.table.tHead.rows[0].cells[idx].style.width = px + 'px';
            }
        });
    };

    /* ----------------------------------------------------------- group -- */

    /**
     * Every grid on one screen, keyed by the SCREEN.
     *
     * This is what makes the control unified: the five Expected Returns
     * boards and the two Capacity grids are one screen to a reader, so one
     * menu drives all of them. A grid joins simply by existing - there is no
     * registration to write, which is why a table added later needs no change.
     */
    var groups = {};

    function groupOf(key) {
        if (!groups[key]) {
            groups[key] = {
                key: key,
                members: [],
                owner: null,          // the grid whose bar is on screen

                /** Re-read cells and re-apply everywhere (a grid may have re-rendered). */
                refresh: function () {
                    this.members.forEach(function (p) { p.refresh(); });
                },

                applyAll: function () {
                    this.members.forEach(function (p) {
                        p.applyHidden();
                        p.applyWidths();
                    });
                    this.syncMenu();
                },

                syncMenu: function () {
                    if (this.owner) { this.owner.syncMenu(); }
                },

                /** The first grid that holds this label, and at what index. */
                locate: function (label) {
                    for (var i = 0; i < this.members.length; i++) {
                        var at = this.members[i].indexOfLabel(label);
                        if (at !== -1) { return { member: this.members[i], index: at }; }
                    }
                    return null;
                },

                /**
                 * Hide/show a label on EVERY grid that has it.
                 *
                 * The menu speaks labels; each grid translates to its own
                 * index and writes its own store, so two grids with different
                 * column orders cannot corrupt one another.
                 */
                setHiddenByLabel: function (label, hidden) {
                    this.members.forEach(function (p) {
                        var i = p.indexOfLabel(label);
                        if (i === -1) { return; }
                        p.setHidden(i, hidden);
                    });
                    this.applyAll();
                },

                /** A drag on one board sizes that column on all of them. */
                setWidthByLabel: function (label, px) {
                    this.members.forEach(function (p) {
                        var i = p.indexOfLabel(label);
                        if (i === -1) { return; }
                        if (px) { p.prefs.widths[i] = px; } else { delete p.prefs.widths[i]; }
                        save(p.storeKey, p.prefs);
                    });
                    this.applyAll();
                },

                /** Union of every grid's labels, in first-seen order. */
                labels: function () {
                    var out = [];
                    var locked = {};

                    this.members.forEach(function (p) {
                        p.labels.forEach(function (label, i) {
                            var at = out.indexOf(label);
                            if (at === -1) {
                                out.push(label);
                                locked[label] = p.locked[i];
                            } else {
                                // Locked in ONE grid means untickable everywhere,
                                // so Actions never becomes hideable just because
                                // another grid on the screen lacks it.
                                locked[label] = locked[label] || p.locked[i];
                            }
                        });
                    });

                    return out.filter(function (label) { return !locked[label]; });
                },

                reset: function () {
                    this.members.forEach(function (p) {
                        p.prefs = { hidden: [], widths: {} };
                        save(p.storeKey, p.prefs);
                    });
                    this.applyAll();
                }
            };
        }

        return groups[key];
    }

    /** Re-read the cells (the grid may have re-rendered) and re-apply. */
    Prefs.prototype.refresh = function () {
        this.build();
        if (!this.labels.length) { return; }
        this.ensureColgroup();
        this.applyWidths();
        this.applyHidden();
    };

    /* ------------------------------------------------------ placement -- */

    /**
     * Where the "Columns" button belongs.
     *
     * In the page header, on the same row as Print/Excel - that is where a
     * reader looks for a view control, and where they were told to look.
     * The old position (a row of its own, just above the grid) survives as
     * the fallback for a screen with no header toolbar, so the feature can
     * never become undiscoverable.
     *
     *   1. [data-dd-prefs-anchor="<id>"] on the table - an explicit target
     *   2. .page-head - its .btn-toolbar when the screen has actions, else
     *      the header itself (it is space-between, so the control still
     *      lands top-right, next to where the actions would have been)
     *   3. before .table-responsive - original behaviour
     *
     * `into`   - append inside that node (1, 2) or insert before it (3)
     * `inHead` - it landed in a page header, so style it as a header
     *            control: no mb-2 of its own, menu opening inwards
     *
     * One assumption: a screen has one page-head. Every list view renders
     * exactly one (app/views/partials/page-head.php).
     */
    Prefs.prototype.anchorFor = function () {
        var grid = this.table.closest('.table-responsive');

        var want = this.table.getAttribute('data-dd-prefs-anchor');
        if (want) {
            var node = document.getElementById(want);
            if (node) {
                return { node: node, into: true, inHead: !!node.closest('.page-head') };
            }
        }

        // The header's action row, when the screen HAS actions.
        // partials/page-head.php only emits .btn-toolbar when $actions is
        // non-empty, and on some screens every button sits behind can() - so
        // a role holding none of them renders a bare header with an empty
        // right-hand side. Anchor to .page-head itself in that case: it is
        // space-between with a gap, so the control lands on the same line
        // as the title, on the right - precisely where those buttons would
        // have been, and not stranded below the filter bar.
        var head = document.querySelector('.page-head');
        if (head) {
            return {
                node: head.querySelector('.btn-toolbar') || head,
                into: true,
                inHead: true
            };
        }

        return { node: grid || this.table.parentNode, into: false, inHead: false };
    };

    /* ----------------------------------------------------------- menu -- */

    /**
     * The screen's ONE "Columns" dropdown.
     *
     * Built once per screen key by whichever grid boots first; the guard
     * below is what stops five Expected Returns boards putting five buttons
     * in the header.
     */
    Prefs.prototype.addMenu = function () {
        // A screen may withdraw the control altogether.
        //
        // The three KPI screens - Leave Dashboard, Driver Dashboard and
        // Capacity Planning - are drill pages: their grids sit behind KPI
        // cards and inside modals, so ONE "Columns" button in their header
        // would list every column on the screen at once and broadcast a
        // change to whichever grid booted first. Those three pass no_prefs
        // to partials/page-head.php, which renders data-dd-prefs-none.
        //
        // Only the BUTTON is withdrawn. boot() still runs refresh(),
        // addResizers() and group.applyAll() after this returns, so the
        // grids keep their resizers, their stored hidden columns and the
        // server-shipped defaults - they look exactly as before, minus the
        // control. Only this file renders the control, so this one guard is
        // the whole of it.
        if (document.querySelector('[data-dd-prefs-none]')) { return; }

        var self = this;
        var group = this.group;

        // One bar per table key, so booting twice cannot duplicate it.
        var placed = document.querySelectorAll('[data-dd-prefs-bar]');
        for (var n = 0; n < placed.length; n++) {
            if (placed[n].getAttribute('data-dd-prefs-bar') === this.key) { return; }
        }

        var target = this.anchorFor();
        var anchor = target.node;
        // Bail out only if there is nowhere at all to put it - silently
        // vanishing would leave the feature undiscoverable.
        if (!anchor || !anchor.parentNode) { return; }

        group.owner = this;

        var bar = document.createElement('div');
        bar.className = 'dd-prefs-bar' + (target.inHead ? '' : ' mb-2');
        bar.setAttribute('data-dd-prefs-bar', this.key);
        if (target.into) {
            // Appended into a flex row, the bar becomes a flex ITEM rather
            // than a row of its own: pin its width so it cannot stretch and
            // shove the Excel/Print buttons around.
            bar.style.flex = '0 0 auto';
        }

        var wrap = document.createElement('div');
        wrap.className = 'dropdown';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-outline-secondary dropdown-toggle';
        btn.setAttribute('data-bs-toggle', 'dropdown');
        btn.setAttribute('aria-expanded', 'false');
        // Static markup only - the column LABELS below go in as textContent,
        // never as HTML, because they come from the server.
        btn.innerHTML = '<i class="bi bi-columns me-1"></i>Columns';
        wrap.appendChild(btn);

        var menu = document.createElement('ul');
        // In the header the button hugs the right edge of the page, so the
        // menu has to open leftwards or it runs off screen.
        menu.className = 'dropdown-menu' + (target.inHead ? ' dropdown-menu-end' : '');
        this.menu = menu;

        group.labels().forEach(function (label) {
            var li = document.createElement('li');
            var a = document.createElement('a');
            a.className = 'dropdown-item d-flex align-items-center gap-2';
            a.href = '#';

            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'form-check-input';
            cb.tabIndex = -1;      // the row itself is the control
            // The label is the shared currency between grids; each grid turns
            // it back into its own column index when the tick changes.
            cb.setAttribute('data-dd-col', label);

            var span = document.createElement('span');
            span.textContent = label;

            a.appendChild(cb);
            a.appendChild(span);

            // The whole row toggles, not just the 13px checkbox.
            a.addEventListener('click', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();

                // Ask the MODEL, never the tick.
                //
                // Clicking the tick runs the browser's PRE-click activation
                // - flipping it - before this handler runs, and
                // preventDefault() then reverts it AFTER we return. So
                // cb.checked here was always the transient value: handing it
                // to setHiddenByLabel() re-applied whatever was already true,
                // the column never moved, and syncMenu() put the tick back
                // where it started. The row looked like it worked while a
                // click on the checkbox itself did nothing at all - which is
                // what "cannot tick or untick" was.
                var at = group.locate(label);
                if (!at) { return; }

                group.setHiddenByLabel(label, !at.member.isHidden(at.index));

                // That revert lands AFTER dispatch and would overwrite what
                // syncMenu() just wrote, so put the tick back once the click
                // has finished.
                setTimeout(function () { group.syncMenu(); }, 0);
            });

            li.appendChild(a);
            menu.appendChild(li);
        });

        var reset = document.createElement('li');
        var resetBtn = document.createElement('button');
        resetBtn.type = 'button';
        resetBtn.className = 'dropdown-item text-danger';
        resetBtn.innerHTML = '<i class="bi bi-arrow-counterclockwise me-1"></i>Reset this table';
        resetBtn.addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            group.reset();
            location.reload();
        });
        reset.appendChild(resetBtn);
        menu.appendChild(reset);

        wrap.appendChild(menu);
        bar.appendChild(wrap);
        if (target.into) {
            anchor.appendChild(bar);                    // into the header row
        } else {
            anchor.parentNode.insertBefore(bar, anchor);  // above the grid
        }

        this.syncMenu();
    };

    /** Reflect the shared state back into the checkboxes. */
    Prefs.prototype.syncMenu = function () {
        if (!this.menu) { return; }
        var group = this.group;

        Array.prototype.forEach.call(
            this.menu.querySelectorAll('input[data-dd-col]'),
            function (cb) {
                var hit = group.locate(cb.getAttribute('data-dd-col'));
                cb.checked = !(hit && hit.member.isHidden(hit.index));
            }
        );
    };

    /* ---------------------------------------------------------- resize -- */

    /**
     * A 7px strip on the right edge of every hideable header. Dragging it
     * writes one <col> per frame; the header width follows so the feedback
     * is immediate, and the result is persisted on release - to every grid on
     * the screen that has the same column, because the release is spoken in
     * labels, not indices.
     */
    Prefs.prototype.addResizers = function () {
        var self = this;
        var head = this.table.tHead;
        if (!head || !head.rows.length) { return; }

        Array.prototype.forEach.call(head.rows[0].cells, function (th, i) {
            if (self.locked[i]) { return; }
            // Already wired - boot() runs again after a re-render.
            if (th.querySelector('.dd-resize')) { return; }

            var h = document.createElement('div');
            h.className = 'dd-resize';
            h.setAttribute('data-dd-resize', String(i));
            h.title = 'Drag to resize - double-click to reset';
            th.style.position = 'relative';
            th.appendChild(h);

            h.addEventListener('mousedown', function (ev) {
                ev.preventDefault();
                self.startDrag(i, ev.clientX, th.offsetWidth);
            });

            h.addEventListener('dblclick', function () {
                self.group.setWidthByLabel(self.labels[i], 0);
            });
        });
    };

    Prefs.prototype.startDrag = function (index, startX, startWidth) {
        var self = this;
        var th = this.table.tHead.rows[0].cells[index];
        var col = this.colgroup ? this.colgroup.children[index] : null;

        function move(ev) {
            var w = Math.max(40, Math.round(startWidth + (ev.clientX - startX)));
            th.style.width = w + 'px';
            if (col) { col.style.width = w + 'px'; }
        }

        function up(ev) {
            document.removeEventListener('mousemove', move);
            document.removeEventListener('mouseup', up);
            document.body.classList.remove('dd-resizing');

            var w = Math.max(40, Math.round(startWidth + (ev.clientX - startX)));
            self.group.setWidthByLabel(self.labels[index], w);
        }

        document.body.classList.add('dd-resizing');
        document.addEventListener('mousemove', move);
        document.addEventListener('mouseup', up);
    };

    /* ------------------------------------------------------------- boot -- */

    /**
     * Every table on the page into its screen group, and safe to run again.
     *
     * This is only the SCAN. Both passes need it - the one that runs while the
     * document is still being parsed, and the real boot at DOMContentLoaded -
     * and only the real boot is allowed to build the menu and the resizers,
     * because the header they anchor to does not exist halfway through the
     * body.
     *
     * Returns the tables grouped by screen key.
     */
    function collect() {
        var tables = document.querySelectorAll('table');
        var perScreen = {};

        Array.prototype.forEach.call(tables, function (table, i) {
            // A table with no header has no columns to show or hide.
            if (!table.tHead || !table.tHead.rows.length) { return; }

            // An explicit opt-OUT - the grids above still need no opt-in.
            //
            // The drill-down modal is a transient list whose header is
            // rewritten per card, so it must not join the shared machinery:
            // no store key, no Columns bar, no resizers. It used to be
            // skipped by accident (a bare <tr> means table.tHead is null);
            // once it grew a real header section it joined, and
            // group.applyAll() then wrote display:'' back over the Date
            // column the modal hides for "Total Active Drivers" - leaving the
            // header standing over rows that had already dropped the cell.
            if (table.hasAttribute('data-dd-skip')) { return; }

            if (!table.__ddPrefs) {
                var p = new Prefs(table, i);
                if (!p.labels.length) { return; }

                table.__ddPrefs = p;
                p.group = groupOf(p.key);
                p.group.members.push(p);
            }

            if (!perScreen[table.__ddPrefs.key]) { perScreen[table.__ddPrefs.key] = []; }
            perScreen[table.__ddPrefs.key].push(table.__ddPrefs);
        });

        return perScreen;
    }

    /**
     * Re-read the cells and write hidden + width back onto every grid.
     *
     * `decorate` is what separates the two callers: only the real boot is
     * allowed to add the Columns menu and the drag handles. While the document
     * is still parsing there is no .page-head to anchor to yet, so the
     * parse-time pass asks for applyHidden()/applyWidths() and nothing else.
     */
    function applyGroups(perScreen, decorate) {
        Object.keys(perScreen).forEach(function (key) {
            var members = perScreen[key];
            var group = members[0].group;

            members.forEach(function (p) { p.refresh(); });

            if (decorate) {
                // Each call is guarded, so this stops after the first bar exists.
                members.forEach(function (p) { p.addMenu(); });
                members.forEach(function (p) { p.addResizers(); });
            }

            group.applyAll();
        });
    }

    /** Does anything here actually have a column to hide? */
    function anyHidden(perScreen) {
        return Object.keys(perScreen).some(function (key) {
            return perScreen[key].some(function (p) { return p.prefs.hidden.length > 0; });
        });
    }

    /**
     * Every table on the page, and safe to run again.
     *
     * Running again matters: driver-dashboard rebuilds its tbody on every
     * sort, which detaches the cells we once held, and a screen may create a
     * grid after load. Both are fixed by re-reading and re-applying - which
     * is why this is exposed for the renderers to call.
     */
    function boot() {
        applyGroups(collect(), true);
    }

    /**
     * Apply the reader's stored layout WHILE THE PAGE IS STILL PARSING.
     *
     * BUG THIS FIXES: this script used to load at the end of <body> and wait
     * for DOMContentLoaded. By then the browser had already painted the grid
     * with every column at full width, so the saved layout arrived a beat
     * later - the columns the reader had hidden flashed into view and then
     * disappeared, on every page load and again on every filtered query (the
     * filter is a GET, so that is a full reload).
     *
     * Two things move it earlier:
     *
     *   1. the layout loads this script from the <HEAD>, so it is downloaded
     *      and executed before the body exists;
     *   2. this observer re-applies the preference each time the parser hands
     *      over more of the document. A MutationObserver callback is a
     *      microtask: it runs at the end of the task that inserted the nodes
     *      and BEFORE the browser's rendering step, so the cells are already
     *      display:none by the time anything is painted. No flash, and the
     *      server still knows nothing about localStorage.
     *
     * It only re-reads and re-applies. No Columns bar and no resizers are
     * built here - there is no header to anchor them to yet - and the whole
     * pass is skipped when nothing is hidden, which is most screens: a grid
     * that shows every column paints exactly what the server rendered and has
     * nothing to correct.
     *
     * boot() takes over at DOMContentLoaded (menu, resizers, and a final
     * re-read) and disconnects this.
     */
    function watchWhileParsing() {
        var observer = new MutationObserver(function () {
            // The document finished between two batches: boot() owns it now.
            if (document.readyState !== 'loading') { return; }

            // Fires for every node the parser hands over before the first
            // table (sidebar, topbar, KPI cards) - nothing to apply to yet.
            if (!document.querySelector('table')) { return; }

            var perScreen = collect();
            if (!anyHidden(perScreen)) { return; }

            applyGroups(perScreen, false);
        });

        observer.observe(document.documentElement, { childList: true, subtree: true });

        document.addEventListener('DOMContentLoaded', function () {
            observer.disconnect();
            boot();
        });
    }

    // Renderers that rebuild rows call this so their grid rejoins and the
    // hidden/width settings survive the re-render.
    window.ddPrefsBoot = boot;

    // The layout loads this from <head>, so the document is always still
    // 'loading' there. The else keeps the script usable wherever it is
    // included late: it simply boots straight away.
    if (document.readyState === 'loading' && document.documentElement) {
        watchWhileParsing();
    } else {
        boot();
    }
})();







