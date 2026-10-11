/* =========================================================================
 * Notification bell (Sept 29 2026) - tasks between managers and users.
 *
 * Reads tasks/bell (JSON) when the page opens, every minute after that,
 * and whenever the bell is opened. It does not check while the browser
 * tab is hidden, or after 30 minutes with no mouse/keyboard use, so an
 * idle open tab does not keep the login alive for ever.
 * ========================================================================= */
(function ($, window, document) {
	'use strict';
	var $bell = $ ? $('#ndBell') : null;
	if (!$bell || !$bell.length) { return; }

	var URL = $bell.data('url');
	var OPEN_URL = $bell.data('open-url');
	var READ_ALL_URL = $bell.data('read-all-url');
	var SWITCH_URL = $bell.data('switch-url');
	var EVERY = 60 * 1000;
	var IDLE_STOP = 30 * 60 * 1000;

	var $badge = $bell.find('.nd-bell__badge');
	var $list = $bell.find('.nd-bell__list');
	var $others = $bell.find('.nd-bell__others');
	var $summary = $bell.find('.nd-bell__summary-text');
	var $readAll = $bell.find('.nd-bell__readall');
	var baseTitle = document.title.replace(/^\(\d+\+?\)\s*/, '');
	var lastActive = Date.now();
	var lastUnread = null;
	var busy = false;

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function render(d) {
		var n = d.unread || 0;
		$badge.text(n > 99 ? '99+' : n).prop('hidden', n === 0);
		$readAll.prop('hidden', n === 0);
		document.title = (n > 0 ? '(' + (n > 99 ? '99+' : n) + ') ' : '') + baseTitle;
		if (lastUnread !== null && n > lastUnread) {
			$bell.removeClass('nd-bell--ring');
			void $bell[0].offsetWidth; /* restart the animation */
			$bell.addClass('nd-bell--ring');
		}
		lastUnread = n;

		var open = d.open || 0;
		var txt = open === 0 ? 'My tasks: nothing open' : 'My tasks: ' + open + ' open';
		if (d.overdue) { txt += ' · <span class="text-danger">' + d.overdue + ' overdue</span>'; }
		$summary.html(txt);

		if (!d.items || !d.items.length) {
			$list.html('<div class="nd-bell__empty"><i class="far fa-bell"></i><div>No notifications yet</div></div>');
		} else {
			$list.html($.map(d.items, function (it) {
				return '<a class="nd-bell__item' + (it.read ? '' : ' is-unread') + '" href="' + esc(OPEN_URL + it.id) + '">' +
					'<span class="nd-bell__dot"></span>' +
					'<span class="nd-bell__text">' + esc(it.message) + '<small>' + esc(it.when) + '</small></span></a>';
			}).join(''));
		}

		if (d.others && d.others.length) {
			$others.html($.map(d.others, function (o) {
				return '<a href="' + esc(SWITCH_URL + o.account_id) + '"><i class="fas fa-building"></i> ' + esc(o.count) + ' new in <strong>' + esc(o.label) + '</strong><span>Switch</span></a>';
			}).join('')).show();
		} else {
			$others.empty().hide();
		}
	}

	function load(force) {
		if (busy) { return; }
		if (!force && (document.hidden || Date.now() - lastActive > IDLE_STOP)) { return; }
		busy = true;
		$.ajax({ url: URL, dataType: 'json', cache: false, global: false })
			.done(function (d) { if (d && d.ok) { render(d); } })
			.always(function () { busy = false; });
	}

	$(document).on('mousemove keydown touchstart scroll', function () { lastActive = Date.now(); });
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { load(); } });
	$bell.on('show.bs.dropdown', function () { load(true); });

	$readAll.on('click', function (e) {
		e.preventDefault();
		e.stopPropagation();
		$.ajax({ url: READ_ALL_URL, type: 'POST', dataType: 'json', global: false, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
			.always(function () { load(true); });
	});

	setTimeout(function () { load(true); }, 300);
	setInterval(load, EVERY);
})(window.jQuery, window, document);
