/* Progressive-enhancement "combo picker" widget (Sept 2026 - first use:
   Quotation's Customer field + per-line Item pickers, see
   quotation_add.php) - wraps a plain <select> with a small searchable
   dropdown UI matching the redesigned form look (see .combo/.combo-panel
   in mystyle.css), while leaving the underlying <select> fully in charge
   of the real value/name/options. Every existing change-handler and
   dynamically-added <option> across the app's forms (item catalog
   autofill, "+ Add new item..." quick-add, vehicle pricing, etc.) keeps
   working completely unmodified - none of that code was ever written
   against a <select>'s visible UI, only its value and its 'change'
   event, both of which this widget preserves exactly.

   Usage:
     NidaamCombo.enhance(selectEl, {
       placeholder: 'Search or select a customer',
       icon: 'search',          // FA5 icon name shown in the trigger, or
                                 // false to omit it
       showBlankInList: false,  // true = the blank/'' option is a normal
                                 // selectable row (e.g. an Item picker,
                                 // where blank means "type it manually");
                                 // false hides it from the list (e.g. a
                                 // required Customer/Vendor picker -
                                 // "nothing" isn't a real choice there)
       showAvatar: false,       // true = render a 2-letter initials chip
                                 // per row and in the trigger
       required: false,         // true = block the owning <form>'s
                                 // submit while empty, with an inline
                                 // message under the field - NOT the
                                 // native `required` attribute, which
                                 // would anchor its validation bubble to
                                 // the now visually-hidden <select>
       requiredMessage: 'This field is required.'
     });

   A disabled <option> (e.g. LedgerTree::toList()'s negative-id group
   headers - "Assets", "  Bank Accounts", etc., interspersed among the
   real ledger rows and indented with &nbsp; to show nesting) renders in
   the panel as an inert section label, matching how it already looks in
   the native <select> - see renderList() below. It is never clickable,
   never counted as a real search match, and never reachable via arrow
   keys/Enter. Sept 2026 - first hit enhancing the Money In/Money Out/
   Transfer account pickers (entries/money_in.php etc.), which get their
   options from exactly that tree.

   Safe to call more than once on the same <select> - later calls are a
   no-op (unless the element was cloned via cloneNode(), see
   dewrapIfCloned() below, in which case it re-enhances cleanly).

   How the two stay in sync:
   - User picks a row in the panel -> this widget sets select.value and
     dispatches a real 'change' event (bubbles: true), exactly like a
     native select - every existing listener fires normally, unmodified.
   - Existing app JS sets select.value directly, from anywhere, at any
     time (e.g. Sales's handleItemPickerChange() reverting the picker
     right after "+ Add new item..." is chosen) - the widget overrides
     the .value accessor on that ONE <select> instance only (every other
     <select> on the page, enhanced or not, is untouched) so ANY
     assignment - this widget's own, or completely unrelated existing
     code - re-renders the visible trigger label to match. Without this,
     picking "+ Add new item..." would leave the trigger showing
     "+ Add new item..." after the app code silently reverts the real
     select back underneath it. */
(function () {
    'use strict';

    var OPEN_WRAP = null; // the one currently-open .combo-select-wrap, if any

    function closeOpen() {
        if (!OPEN_WRAP) { return; }
        var panel = OPEN_WRAP.querySelector('.combo-panel');
        if (panel) { panel.remove(); }
        var trigger = OPEN_WRAP.querySelector('.combo');
        if (trigger) {
            trigger.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
        }
        OPEN_WRAP = null;
    }

    document.addEventListener('click', function (e) {
        if (OPEN_WRAP && !OPEN_WRAP.contains(e.target)) { closeOpen(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && OPEN_WRAP) {
            var trigger = OPEN_WRAP.querySelector('.combo');
            closeOpen();
            if (trigger) { trigger.focus(); }
        }
    });

    function initials(text) {
        var words = (text || '').trim().split(/\s+/).filter(Boolean);
        if (!words.length) { return ''; }
        if (words.length === 1) { return words[0].slice(0, 2).toUpperCase(); }
        return (words[0][0] + words[1][0]).toUpperCase();
    }

    /* Every selectable row currently on the <select>, read live (not
       cached) so dynamically-added <option>/<optgroup> elements (e.g. a
       quick-added catalog item - see addCatalogOption() in
       quotation_add.php) are always picked up with no extra wiring.
       Preserves document order exactly: an <optgroup> is its own block,
       and any top-level <option>s (before, between, or after groups)
       form their own unlabeled block in that same position - matching
       how "+ Add new item..." (a plain trailing option, not in any
       optgroup) already sits after every category in the native
       <select>. */
    function readOptions(select, opts) {
        var groups = [];
        var current = null;
        Array.prototype.forEach.call(select.children, function (child) {
            if (child.tagName === 'OPTGROUP') {
                var g = { label: child.label, options: [] };
                Array.prototype.forEach.call(child.children, function (opt) {
                    if (opt.tagName === 'OPTION') { g.options.push(opt); }
                });
                groups.push(g);
                current = null;
            } else if (child.tagName === 'OPTION') {
                if (child.value === '' && !opts.showBlankInList) { return; }
                if (!current) {
                    current = { label: null, options: [] };
                    groups.push(current);
                }
                current.options.push(child);
            }
        });
        return groups.filter(function (g) { return g.options.length; });
    }

    function renderTrigger(select, trigger, opts) {
        var selected = select.options[select.selectedIndex];
        var isBlank = !selected || selected.value === '';
        trigger.innerHTML = '';
        if (opts.icon !== false) {
            var icon = document.createElement('i');
            icon.className = 'fas fa-' + opts.icon;
            trigger.appendChild(icon);
        }
        if (isBlank && opts.placeholder) {
            var ph = document.createElement('span');
            ph.className = 'combo-placeholder';
            ph.textContent = opts.placeholder;
            trigger.appendChild(ph);
        } else if (selected) {
            var val = document.createElement('span');
            val.className = 'combo-value';
            if (opts.showAvatar && selected.value.indexOf('__') !== 0) {
                var chip = document.createElement('span');
                chip.className = 'avatar-chip';
                chip.textContent = initials(selected.textContent);
                val.appendChild(chip);
            }
            var label = document.createElement('span');
            label.textContent = selected.textContent;
            val.appendChild(label);
            trigger.appendChild(val);
        }
        var chevron = document.createElement('i');
        chevron.className = 'fas fa-chevron-down';
        trigger.appendChild(chevron);
    }

    function openPanel(select, wrap, trigger, opts) {
        closeOpen();
        var panel = document.createElement('div');
        panel.className = 'combo-panel';

        var searchWrap = document.createElement('div');
        searchWrap.className = 'combo-panel__search';
        searchWrap.innerHTML = '<i class="fas fa-search"></i>';
        var searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.placeholder = 'Type to search...';
        searchWrap.appendChild(searchInput);
        panel.appendChild(searchWrap);

        var list = document.createElement('div');
        list.className = 'combo-panel__list';
        list.setAttribute('role', 'listbox');
        panel.appendChild(list);

        var focusedItem = null;

        function setFocused(el) {
            if (focusedItem) { focusedItem.classList.remove('is-focused'); }
            focusedItem = el || null;
            if (focusedItem) {
                focusedItem.classList.add('is-focused');
                focusedItem.scrollIntoView({ block: 'nearest' });
            }
        }

        function selectOption(optionEl) {
            select.value = optionEl.value; // triggers renderTrigger() via the value override in enhance()
            select.dispatchEvent(new Event('change', { bubbles: true }));
            closeOpen();
            trigger.focus();
        }

        function renderList(filterText) {
            list.innerHTML = '';
            var groups = readOptions(select, opts);
            var q = (filterText || '').trim().toLowerCase();
            var any = false;
            groups.forEach(function (g) {
                /* An action row ("+ Add new item...") always stays in the
                   list, even while searching (Sept 28 2026) - searching for
                   something that isn't there is exactly when it's needed. */
                var matched = g.options.filter(function (opt) {
                    return !q || opt.value.indexOf('__') === 0 || opt.textContent.toLowerCase().indexOf(q) !== -1;
                });
                if (!matched.length) { return; }
                if (g.label) {
                    var gh = document.createElement('div');
                    gh.className = 'combo-panel__group';
                    gh.textContent = g.label;
                    list.appendChild(gh);
                }
                matched.forEach(function (opt) {
                    /* Disabled option = a group-header row (see the
                       usage note at the top of this file), not a real
                       choice - render it as an inert label, same as the
                       native <select> already shows it, and don't let it
                       count toward "any" (a query that only matches
                       header text should still say "No matches"). */
                    if (opt.disabled) {
                        var header = document.createElement('div');
                        header.className = 'combo-panel__group';
                        header.textContent = opt.textContent;
                        list.appendChild(header);
                        return;
                    }
                    var isAction = opt.value.indexOf('__') === 0;
                    if (!isAction) { any = true; }
                    var row = document.createElement('div');
                    row.className = 'combo-panel__item' + (isAction ? ' combo-panel__item--add' : '');
                    row.setAttribute('role', 'option');
                    if (opt.value === select.value) { row.classList.add('is-selected'); }
                    if (isAction) {
                        var plus = document.createElement('i');
                        plus.className = 'fas fa-plus';
                        plus.style.fontSize = '10px';
                        row.appendChild(plus);
                    } else if (opts.showAvatar && opt.value !== '') {
                        var chip = document.createElement('span');
                        chip.className = 'avatar-chip';
                        chip.textContent = initials(opt.textContent);
                        row.appendChild(chip);
                    }
                    var text = document.createElement('span');
                    text.textContent = opt.textContent;
                    row.appendChild(text);
                    row.addEventListener('mouseenter', function () { setFocused(row); });
                    row.addEventListener('click', function () { selectOption(opt); });
                    row._option = opt;
                    list.appendChild(row);
                });
            });
            if (!any) {
                var empty = document.createElement('div');
                empty.className = 'combo-panel__empty';
                empty.textContent = 'No matches';
                list.appendChild(empty);
            }
            setFocused(list.querySelector('.combo-panel__item.is-selected') || list.querySelector('.combo-panel__item:not(.combo-panel__item--add)') || list.querySelector('.combo-panel__item'));
        }

        searchInput.addEventListener('input', function () { renderList(searchInput.value); });
        searchInput.addEventListener('keydown', function (e) {
            var items = Array.prototype.slice.call(list.querySelectorAll('.combo-panel__item'));
            if (!items.length) { return; }
            var idx = items.indexOf(focusedItem);
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setFocused(items[Math.min(idx + 1, items.length - 1)]);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setFocused(items[Math.max(idx - 1, 0)]);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (focusedItem && focusedItem._option) { selectOption(focusedItem._option); }
            }
        });

        wrap.appendChild(panel);
        renderList('');
        searchInput.focus();
        trigger.classList.add('is-open');
        trigger.setAttribute('aria-expanded', 'true');
        OPEN_WRAP = wrap;
    }

    function attachRequiredValidation(select, opts) {
        select.setAttribute('data-combo-required', '1');
        if (opts.requiredMessage) { select.setAttribute('data-combo-required-message', opts.requiredMessage); }
        var form = select.form;
        if (!form || form.hasAttribute('data-combo-validator-attached')) { return; }
        form.setAttribute('data-combo-validator-attached', '1');
        form.addEventListener('submit', function (e) {
            var invalids = Array.prototype.filter.call(
                form.querySelectorAll('select[data-combo-required="1"]'),
                function (s) { return !s.value; }
            );
            if (!invalids.length) { return; }
            e.preventDefault();
            invalids.forEach(function (s) {
                var w = s.closest('.combo-select-wrap');
                if (!w) { return; }
                var t = w.querySelector('.combo');
                if (t) { t.classList.add('has-error'); }
                var msg = w.querySelector('.combo-error-msg');
                if (!msg) {
                    msg = document.createElement('div');
                    msg.className = 'combo-error-msg';
                    w.appendChild(msg);
                }
                msg.textContent = s.getAttribute('data-combo-required-message') || 'This field is required.';
            });
            var firstWrap = invalids[0].closest('.combo-select-wrap');
            var firstTrigger = firstWrap ? firstWrap.querySelector('.combo') : null;
            if (firstTrigger) { firstTrigger.focus(); }
        });
    }

    /* A <select> already carrying its own .combo-select-wrap parent got
       here via cloneNode(true) on a row this widget had already enhanced
       (see addBlankRow()/buildRowFromTemplate() in quotation_add.php,
       which clone a whole <tr> to create each new item line). cloneNode
       copies markup/attributes but never event listeners or per-instance
       property overrides, so a cloned trigger div would otherwise sit
       there inert (no click handler) and its <select> would be missing
       the value-sync override above - restore the clone to a plain,
       unenhanced <select> first so enhance() below builds it all fresh. */
    function dewrapIfCloned(select) {
        var wrap = select.parentNode;
        if (!wrap || !wrap.classList || !wrap.classList.contains('combo-select-wrap')) { return; }
        wrap.parentNode.insertBefore(select, wrap);
        wrap.remove();
        select.removeAttribute('data-combo-enhanced');
        try { delete select.value; } catch (err) { /* restores the native accessor when configurable */ }
    }

    function enhance(select, opts) {
        if (!select) { return; }
        dewrapIfCloned(select);
        if (select.getAttribute('data-combo-enhanced') === '1') { return; }
        select.setAttribute('data-combo-enhanced', '1');

        opts = opts || {};
        opts.icon = ('icon' in opts) ? opts.icon : 'search';

        var wrap = document.createElement('div');
        wrap.className = 'combo-select-wrap';
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);

        var trigger = document.createElement('div');
        trigger.className = 'combo';
        trigger.setAttribute('tabindex', '0');
        trigger.setAttribute('role', 'combobox');
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        wrap.appendChild(trigger);

        renderTrigger(select, trigger, opts);

        trigger.addEventListener('click', function () {
            if (trigger.classList.contains('is-open')) { closeOpen(); return; }
            openPanel(select, wrap, trigger, opts);
        });
        trigger.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
                e.preventDefault();
                if (!trigger.classList.contains('is-open')) { openPanel(select, wrap, trigger, opts); }
            }
        });

        try {
            var desc = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value');
            if (desc && desc.configurable !== false) {
                Object.defineProperty(select, 'value', {
                    configurable: true,
                    enumerable: true,
                    get: function () { return desc.get.call(select); },
                    set: function (v) {
                        desc.set.call(select, v);
                        renderTrigger(select, trigger, opts);
                        trigger.classList.remove('has-error');
                        var msg = wrap.querySelector('.combo-error-msg');
                        if (msg) { msg.remove(); }
                    }
                });
            }
        } catch (err) { /* progressive enhancement only - native <select> still works either way */ }

        if (opts.required) { attachRequiredValidation(select, opts); }
    }

    window.NidaamCombo = { enhance: enhance };
})();
