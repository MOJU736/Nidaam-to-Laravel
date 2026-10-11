/* =========================================================================
 * Phone layout (Sept 29 2026) - works with assets/dist/css/mobile.css and
 * views/_partials/mobile_nav.php.
 *  - List tables become cards on phones (each cell gets its column name).
 *  - Line-item tables on forms become one card per line.
 *  - Money fields open the number keyboard.
 *  - Bottom menu: "+ New" sheet, "More" opens the side menu, Tasks badge.
 *  - "Desktop view" / "Mobile view" switch (remembered for a year).
 *  - Installable app: registers the service worker, "Install app" button.
 * ========================================================================= */
(function ($, window, document) {
	'use strict';
	if (!$) { return; }
	var BASE = $('base').attr('href') || '/';

	/* ---------- tables -> cards ---------- */
	function labelTable(table) {
		var $t = $(table);
		if ($t.hasClass('no-cards') || $t.closest('.no-cards, .nd-find, .modal, .daterangepicker, .report-table, .nd-report').length) { return; }
		if (/\/reports(\/|$)/.test(window.location.pathname)) { return; }
		var $head = $t.children('thead').find('tr').last();
		if (!$head.length) { return; }
		var labels = [];
		var bad = false;
		$head.children('th, td').each(function () {
			var span = parseInt($(this).attr('colspan') || '1', 10);
			if (span > 1) { bad = true; }
			labels.push($.trim($(this).text().replace(/\s+/g, ' ')));
		});
		if (bad || labels.length < 2) { return; }
		/* a line-item table on a form has fields to type in (not just the
		   hidden fields of a row's Void/Delete buttons) */
		var isForm = $t.find('tbody').find('input, select, textarea').filter(function () {
			return this.type !== 'hidden' && this.type !== 'submit' && this.type !== 'button' && !$(this).closest('.dropdown-menu, .row-actions-menu, form[style*="inline"]').length;
		}).length > 0;
		$t.addClass('nd-cards').toggleClass('nd-cards--form', isForm);
		$t.children('tbody').children('tr').each(function () {
			var $cells = $(this).children('td');
			if ($cells.length === 1 && labels.length > 1) { $(this).addClass('nd-card-plain'); return; }
			$cells.each(function (i) {
				var $c = $(this);
				if (!$c.is('[data-label]')) { $c.attr('data-label', labels[i] || ''); }
				if ($c.find('.row-actions-toggle, .dropdown').length && !isForm) { $c.addClass('nd-card-actions'); }
			});
		});
	}
	function labelAll(root) {
		$(root || document).find('.content-wrapper table.table, .content-wrapper table.stripped').each(function () { labelTable(this); });
	}

	/* ---------- number keyboard for money fields ---------- */
	function numericInputs(root) {
		$(root || document).find('input[type=text], input[type=number], input:not([type])').each(function () {
			if (this.hasAttribute('inputmode') || this.classList.contains('nd-calc')) { return; }
			var n = (this.name || '') + ' ' + (this.id || '') + ' ' + (this.className || '');
			if (/(amount|price|qty|quantity|total|rate|discount_value|percent|op_balance|days)/i.test(n) && !/(description|note|name)/i.test(n)) {
				/* Oct 3 2026: money boxes accept a calculation (calc-fields.js), so they
				   get the normal keyboard (with * / ( )) instead of the number pad */
				this.classList.add('nd-calc');
			}
		});
	}

	var pending = null;
	function refresh(root) {
		labelAll(root);
		numericInputs(root);
	}

	$(function () {
		refresh(document);
		/* rows added later (invoice lines, DataTables pages, filters) */
		if (window.MutationObserver) {
			new MutationObserver(function () {
				clearTimeout(pending);
				pending = setTimeout(function () { refresh(document); }, 120);
			}).observe(document.querySelector('.content-wrapper') || document.body, { childList: true, subtree: true });
		}
		$(document).on('draw.dt', function () { refresh(document); });

		/* ---------- bottom menu ---------- */
		var $sheet = $('#ndMsheet');
		$('#ndMnavNew').on('click', function () { $sheet.prop('hidden', false); });
		$sheet.on('click', '[data-close]', function () { $sheet.prop('hidden', true); });
		$(document).on('keydown', function (e) { if (e.key === 'Escape') { $sheet.prop('hidden', true); } });
		$('#ndMnavMore').on('click', function () {
			var $b = $('.main-header [data-widget="pushmenu"]').first();
			if ($b.length) { $b.trigger('click'); }
		});

		/* Tasks badge follows the bell's unread count */
		var $bell = $('.nd-bell__badge'), $tb = $('.nd-mnav__badge');
		if ($bell.length && $tb.length && window.MutationObserver) {
			var sync = function () { $tb.text($bell.text()).prop('hidden', $bell.prop('hidden')); };
			new MutationObserver(sync).observe($bell[0], { attributes: true, childList: true, characterData: true, subtree: true });
			sync();
		}

		/* ---------- Desktop view / Mobile view ---------- */
		function setView(v) {
			try { document.cookie = 'nd_view=' + v + '; path=/; max-age=' + (v === 'desktop' ? 31536000 : 0) + '; SameSite=Lax'; } catch (e) {}
			window.location.reload();
		}
		$('#ndDesktopView').on('click', function () { setView('desktop'); });
		var forcedDesktop = document.cookie.indexOf('nd_view=desktop') !== -1;
		var smallScreen = Math.min(window.screen.width, window.screen.height) < 768;
		if (forcedDesktop && smallScreen) {
			$('<button type="button" class="nd-mview-pill is-shown"><i class="fas fa-mobile-alt"></i> Mobile view</button>')
				.appendTo('body').on('click', function () { setView('mobile'); });
		}
		/* a "Desktop view" link at the end of the side menu too (phones) */
		if (!forcedDesktop && window.matchMedia('(max-width: 767.98px)').matches) {
			$('.main-sidebar .nav-sidebar').append('<li class="nav-item"><a href="#" class="nav-link" id="ndDesktopView2"><i class="nav-icon fas fa-desktop"></i><p>Desktop view</p></a></li>');
			$('#ndDesktopView2').on('click', function (e) { e.preventDefault(); setView('desktop'); });
		}

		/* ---------- installable app ---------- */
		var deferred = null;
		window.addEventListener('beforeinstallprompt', function (e) {
			e.preventDefault();
			deferred = e;
			$('#ndInstallApp').prop('hidden', false);
		});
		$('#ndInstallApp').on('click', function () {
			if (!deferred) { return; }
			deferred.prompt();
			deferred.userChoice.then(function () { deferred = null; $('#ndInstallApp').prop('hidden', true); });
		});
		if ('serviceWorker' in navigator && (window.location.protocol === 'https:' || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1')) {
			navigator.serviceWorker.register(BASE + 'sw.js', { scope: BASE }).catch(function () {});
		}
	});
})(window.jQuery, window, document);
