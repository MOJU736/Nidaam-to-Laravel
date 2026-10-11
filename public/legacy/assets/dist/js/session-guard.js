/* =========================================================================
 * Session guard (Sept 30 2026) - see Security_model.
 *
 *  - Signed out after 30 minutes without activity (mouse, keys, touch,
 *    scrolling - in ANY open tab of the app). One minute before, a box
 *    asks "Still there?" with a button to stay signed in.
 *  - While someone works without changing pages (a long invoice), the
 *    page tells the server every few minutes, so they are never signed
 *    out while busy.
 *  - When the server has signed this browser out (another device signed
 *    in, an admin signed them out, idle), background requests get a
 *    "signed out" answer and the page goes to the sign-in page, which
 *    says why.
 * ========================================================================= */
(function ($, window, document) {
	'use strict';
	var C = window.NIDAAM_SESSION;
	if (!$ || !C) { return; }

	var IDLE = (C.idle || 1800) * 1000;
	var WARN = 60 * 1000;
	var PING_EVERY = 4 * 60 * 1000;
	var KEY = 'ndLastActive';
	var lastActive = Date.now(), lastPing = Date.now(), lastWrite = 0, leaving = false;
	var $box = null, warnOpen = false;

	function store() { try { return window.localStorage; } catch (e) { return null; } }
	function shared() { var s = store(); var v = 0; try { v = s ? parseInt(s.getItem(KEY) || '0', 10) : 0; } catch (e) {} return v || 0; }
	function newest() { return Math.max(lastActive, shared()); }

	function mark() {
		var now = Date.now();
		lastActive = now;
		if (now - lastWrite > 5000) {
			lastWrite = now;
			var s = store();
			if (s) { try { s.setItem(KEY, String(now)); } catch (e) {} }
		}
		if (warnOpen) { hideWarn(); ping(); }
	}
	['mousedown', 'keydown', 'touchstart', 'wheel'].forEach(function (ev) { document.addEventListener(ev, mark, { passive: true, capture: true }); });
	var moveT = 0;
	document.addEventListener('mousemove', function () { var n = Date.now(); if (n - moveT > 2000) { moveT = n; mark(); } }, { passive: true });
	window.addEventListener('scroll', function () { var n = Date.now(); if (n - moveT > 2000) { moveT = n; mark(); } }, { passive: true, capture: true });

	function ping() {
		lastPing = Date.now();
		$.ajax({ url: C.ping, cache: false, dataType: 'json', global: false });
	}

	function signOut() {
		if (leaving) { return; }
		leaving = true;
		/* sent as a form so the unsaved-changes guard does not stop it
		   with a "Leave site?" box - the person is away */
		var f = document.createElement('form');
		f.method = 'get';
		f.action = C.timeout;
		document.body.appendChild(f);
		f.submit();
	}

	function box() {
		if ($box) { return $box; }
		$box = $(
			'<div class="modal fade nd-idle" id="ndIdle" tabindex="-1" role="dialog" aria-labelledby="ndIdleTitle" data-backdrop="static" data-keyboard="false">' +
				'<div class="modal-dialog modal-dialog-centered modal-sm" role="document"><div class="modal-content">' +
					'<div class="modal-body">' +
						'<div class="nd-idle__icon"><i class="fas fa-clock"></i></div>' +
						'<h5 id="ndIdleTitle">Still there?</h5>' +
						'<p>For your security you will be signed out in <strong class="nd-idle__secs">60</strong> seconds.</p>' +
					'</div>' +
					'<div class="modal-footer">' +
						'<a href="' + C.logout + '" class="btn btn-light nd-idle__out">Sign out</a>' +
						'<button type="button" class="btn btn-primary nd-idle__stay">Stay signed in</button>' +
					'</div>' +
				'</div></div>' +
			'</div>'
		).appendTo('body');
		$box.on('click', '.nd-idle__stay', function () { mark(); hideWarn(); ping(); });
		return $box;
	}
	function showWarn(secs) {
		var $b = box();
		$b.find('.nd-idle__secs').text(secs);
		if (!warnOpen) {
			warnOpen = true;
			if ($.fn.modal) { $b.modal('show'); } else { $b.show(); }
			try { document.title = '(!) ' + document.title.replace(/^\(!\) /, ''); } catch (e) {}
		}
	}
	function hideWarn() {
		if (!warnOpen) { return; }
		warnOpen = false;
		if ($.fn.modal) { $box.modal('hide'); } else { $box.hide(); }
		try { document.title = document.title.replace(/^\(!\) /, ''); } catch (e) {}
	}

	function tick() {
		var now = Date.now();
		var idle = now - newest();
		if (idle >= IDLE) { signOut(); return; }
		if (idle >= IDLE - WARN) {
			showWarn(Math.max(1, Math.ceil((IDLE - idle) / 1000)));
			return;
		}
		if (warnOpen) { hideWarn(); }
		/* busy on this page: keep the server's clock up to date */
		if (newest() > lastPing && now - lastPing >= PING_EVERY) { ping(); }
	}
	setInterval(tick, 1000);
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { tick(); } });

	/* the server signed this browser out: go to the sign-in page */
	$.ajaxPrefilter(function (opts, orig, xhr) {
		xhr.fail(function (x) {
			var d = x && x.responseJSON;
			if (x.status === 401 && d && d.signed_out) { leaving = true; window.location.href = d.login || C.login; }
			else if (x.status === 403 && d && d.must_change && d.url) { window.location.href = d.url; }
		});
	});

	/* this page is the newest activity */
	mark();
})(window.jQuery, window, document);
