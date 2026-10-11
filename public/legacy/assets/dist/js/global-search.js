/* =========================================================================
 * Search everything - the top bar search box (Sept 29 2026).
 *
 * - Results drop down while typing (after 2 letters), grouped: customers,
 *   invoices, bills, payments, items, accounts ... (find/quick).
 * - Page shortcuts: "trial balance", "new invoice", "backup" ...
 * - Matches in the user's other companies (find/others), with a link that
 *   switches company and repeats the search there.
 * - Recently viewed: clicking the empty box shows the last documents
 *   opened (kept in this browser, per company).
 * - Ctrl+K or "/" anywhere jumps into the box; arrows + Enter pick; Esc
 *   closes; Enter with nothing picked opens the full results page.
 * ========================================================================= */
(function ($, window, document) {
	'use strict';
	var $wrap = $ ? $('#ndSearch') : null;
	if (!$wrap || !$wrap.length) { return; }

	var $input = $wrap.find('input');
	var BASE = $wrap.data('base');
	var QUICK = $wrap.data('quick-url');
	var OTHERS = $wrap.data('others-url');
	var FULL = $wrap.data('full-url');
	var ACCOUNT = String($wrap.data('account') || '0');
	var RECENT_KEY = 'nidaamRecent:' + ACCOUNT;
	var $panel = $('<div class="nd-find" role="listbox" hidden></div>').appendTo($wrap);
	var timer = null, otherTimer = null, lastQ = null, reqNo = 0;

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function store() { try { return window.localStorage; } catch (e) { return null; } }

	/* ---------- recently viewed ---------- */
	var KINDS = [
		[/sales\/invoice_view\/\d+/, 'Invoice'], [/sales\/quotation_view\/\d+/, 'Quotation'], [/sales\/payment_view\/\d+/, 'Payment received'],
		[/sales\/credit_note_view\/\d+/, 'Credit note'], [/purchases\/bill_view\/\d+/, 'Bill'], [/purchases\/vendor_payment_view\/\d+/, 'Payment made'],
		[/purchases\/supplier_credit_view\/\d+/, 'Supplier credit'], [/purchases\/expense_view\/\d+/, 'Quick expense'], [/customers\/view\/\d+/, 'Customer'],
		[/vendors\/view\/\d+/, 'Vendor'], [/items\/edit\/\d+/, 'Item'], [/projects\/view\/\d+/, 'Project'], [/tasks\/view\/\d+/, 'Task'],
		[/entries\/view\/[a-z_]+\/\d+/, 'Journal entry'], [/reports\/ledgerstatement\/ledgerid\/\d+/, 'Account']
	];
	function recordRecent() {
		var path = window.location.pathname + window.location.search;
		var kind = null;
		for (var i = 0; i < KINDS.length; i++) { if (KINDS[i][0].test(path)) { kind = KINDS[i][1]; break; } }
		if (!kind) { return; }
		var t = $('.content .dashboard-card__title').first().text() || $('.content h1, .content h3').first().text() || document.title;
		t = $.trim(t.replace(/\s+/g, ' ')).slice(0, 90);
		if (!t) { return; }
		var s = store(); if (!s) { return; }
		var list = [];
		try { list = JSON.parse(s.getItem(RECENT_KEY) || '[]'); } catch (e) { list = []; }
		var url = window.location.href.split('#')[0];
		list = $.grep(list, function (r) { return r.url !== url; });
		list.unshift({ url: url, title: t, kind: kind, at: Date.now() });
		try { s.setItem(RECENT_KEY, JSON.stringify(list.slice(0, 8))); } catch (e) {}
	}
	function recentList() {
		var s = store(); if (!s) { return []; }
		try { return JSON.parse(s.getItem(RECENT_KEY) || '[]'); } catch (e) { return []; }
	}

	/* ---------- drawing ---------- */
	function open() { $panel.prop('hidden', false); $wrap.addClass('is-open'); }
	function close() { $panel.prop('hidden', true); $wrap.removeClass('is-open'); }

	function row(url, title, sub, badge, amount, icon) {
		return '<a class="nd-find__row" role="option" href="' + esc(url) + '">' +
			(icon ? '<i class="fas ' + esc(icon) + ' nd-find__ricon"></i>' : '') +
			'<span class="nd-find__main"><span class="nd-find__title">' + esc(title) + '</span>' + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</span>' +
			(badge ? '<span class="find-badge find-badge--' + esc(String(badge).toLowerCase().replace(/ /g, '_')) + '">' + esc(badge) + '</span>' : '') +
			(amount ? '<span class="nd-find__amt">' + esc(amount) + '</span>' : '') + '</a>';
	}

	function showRecent() {
		var list = recentList();
		if (!list.length) {
			$panel.html('<div class="nd-find__hint"><i class="fas fa-search"></i> Search customers, invoices, bills, items, amounts, numbers&hellip;<br><small>Tip: press <kbd>Ctrl</kbd> + <kbd>K</kbd> from anywhere.</small></div>');
		} else {
			$panel.html('<div class="nd-find__head">Recently viewed</div>' + $.map(list, function (r) {
				return row(r.url, r.title, r.kind, '', '', 'fa-history');
			}).join(''));
		}
		open();
	}

	function render(d, q) {
		var html = '';
		if (d.shortcuts && d.shortcuts.length) {
			html += '<div class="nd-find__head"><i class="fas fa-bolt"></i> Go to</div>' + $.map(d.shortcuts, function (s) {
				return row(BASE + s.url, s.title, '', '', '', 'fa-arrow-right');
			}).join('');
		}
		$.each(d.groups || [], function (i, g) {
			html += '<div class="nd-find__head"><i class="fas ' + esc(g.icon) + '"></i> ' + esc(g.label) +
				(g.more ? ' <a class="nd-find__more" href="' + esc(FULL + '?q=' + encodeURIComponent(q) + '&in=' + g.key) + '">show all</a>' : '') + '</div>';
			html += $.map(g.items, function (it) { return row(BASE + it.url, it.title, it.sub, it.badge, it.amount, ''); }).join('');
		});
		if (!html) {
			html = '<div class="nd-find__hint">Nothing found for &ldquo;' + esc(q) + '&rdquo; in this company.</div>';
		}
		html += '<div class="nd-find__others"></div>';
		html += '<a class="nd-find__all" role="option" href="' + esc(FULL + '?q=' + encodeURIComponent(q)) + '"><i class="fas fa-search"></i> See all results for &ldquo;' + esc(q) + '&rdquo; <kbd>Enter</kbd></a>';
		$panel.html(html);
		open();
		if (d.others && q.length >= 3) {
			clearTimeout(otherTimer);
			otherTimer = setTimeout(function () { loadOthers(q); }, 350);
		}
	}

	function loadOthers(q) {
		$.ajax({ url: OTHERS, data: { q: q }, dataType: 'json', global: false }).done(function (d) {
			if (!d || !d.ok || q !== lastQ || !d.companies || !d.companies.length) { return; }
			$panel.find('.nd-find__others').html('<div class="nd-find__head"><i class="fas fa-building"></i> In your other companies</div>' +
				$.map(d.companies, function (c) {
					var next = 'find?q=' + encodeURIComponent(q);
					return row(BASE + 'user/activate/' + c.id + '?next=' + encodeURIComponent(next), c.label + ' — ' + c.count + ' match' + (c.count === 1 ? '' : 'es'),
						(c.top || []).join(' · '), '', 'Open', 'fa-exchange-alt');
				}).join(''));
		});
	}

	function search(q) {
		lastQ = q;
		var my = ++reqNo;
		$.ajax({ url: QUICK, data: { q: q }, dataType: 'json', global: false }).done(function (d) {
			if (my !== reqNo || !d || !d.ok) { return; }
			render(d, q);
		});
	}

	/* ---------- keyboard ---------- */
	function rows() { return $panel.find('.nd-find__row, .nd-find__all'); }
	function move(step) {
		var $r = rows(); if (!$r.length) { return; }
		var i = $r.index($r.filter('.is-active'));
		i = i < 0 ? (step > 0 ? 0 : $r.length - 1) : (i + step + $r.length) % $r.length;
		$r.removeClass('is-active').eq(i).addClass('is-active')[0].scrollIntoView({ block: 'nearest' });
	}

	$input.on('input', function () {
		var q = $.trim(this.value);
		clearTimeout(timer);
		if (q.length < 2 && !/^\d+$/.test(q)) {
			reqNo++;
			if (q === '') { showRecent(); } else { close(); }
			return;
		}
		timer = setTimeout(function () { search(q); }, 220);
	});
	$input.on('focus', function () {
		if ($.trim(this.value) === '') { showRecent(); } else if ($panel.children().length) { open(); }
	});
	$input.on('keydown', function (e) {
		if (e.key === 'ArrowDown') { e.preventDefault(); if ($panel.prop('hidden')) { open(); } move(1); }
		else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
		else if (e.key === 'Escape') { close(); this.blur(); }
		else if (e.key === 'Enter') {
			e.preventDefault();
			var $a = rows().filter('.is-active');
			var q = $.trim(this.value);
			if ($a.length) { window.location.href = $a.attr('href'); }
			else if (q !== '') { window.location.href = FULL + '?q=' + encodeURIComponent(q); }
		}
	});
	$(document).on('mousedown', function (e) { if (!$(e.target).closest('#ndSearch').length) { close(); } });
	$(document).on('keydown', function (e) {
		var typing = /^(input|textarea|select)$/i.test(e.target.tagName) || e.target.isContentEditable;
		if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); $input.trigger('focus').select(); }
		else if (e.key === '/' && !typing) { e.preventDefault(); $input.trigger('focus'); }
	});

	recordRecent();
})(window.jQuery, window, document);
