/* =========================================================================
 * Unsaved-changes guard (Sept 29 2026).
 *
 * "When the user is working on an invoice, bill, payment or anything else
 * and clicks another tab by mistake, warn: you have unsaved changes - Save,
 * Discard, Cancel."
 *
 * How it works:
 *  - Every POST form on the page that the user can type into is watched
 *    (report filters, pop-up modals and forms marked data-unsaved="off"
 *    are skipped). Its values are snapshotted when the page is ready.
 *  - A form is "dirty" when the user has actually touched it AND its values
 *    now differ from the snapshot. (Typing something and deleting it again
 *    is not dirty; code that fills fields in on its own is not dirty.)
 *  - Clicking any link that leaves the page while a form is dirty opens a
 *    dialog: Save / Discard / Cancel.
 *      Save    - clicks the form's own save button (so all the page's own
 *                checks still run). If the save succeeds, the user is taken
 *                on to the page they clicked.
 *      Discard - leaves without saving.
 *      Cancel  - stays on the page.
 *  - Closing the tab, refreshing or the browser Back button while dirty
 *    shows the browser's own "Leave site?" warning (browsers don't allow a
 *    custom dialog there).
 *
 * Per-form options (optional):
 *   data-unsaved="off"         never guard this form
 *   data-unsaved="on"          guard it even where it would be skipped
 *   data-unsaved-name="bill"   what to call it in the message
 *   data-unsaved-save          on a submit button: the one "Save" clicks
 * ========================================================================= */
(function ($, window, document) {
	'use strict';
	if (!$ || !window.HTMLFormElement) { return; }

	var AFTER_SAVE_KEY = 'nidaamUnsavedAfterSave';
	var SAVED_NOTE_KEY = 'nidaamUnsavedSavedNote';
	var CSRF_NAME = (window.NIDAAM_UNSAVED && window.NIDAAM_UNSAVED.csrfName) || '';
	var leaving = false;            /* true once we are deliberately navigating away */
	var lastUserAction = 0;         /* time of the last real key/mouse/touch action */
	var tracked = [];               /* guarded forms */

	/* ---------------------------------------------------------------
	 * Which forms are guarded
	 * ------------------------------------------------------------- */
	function isGuardable(form) {
		var flag = (form.getAttribute('data-unsaved') || '').toLowerCase();
		if (flag === 'off') { return false; }
		if (flag === 'on') { return true; }
		if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') { return false; }
		if ($(form).closest('.modal').length) { return false; }                 /* pop-up quick-add forms */
		if (/\/reports(\/|$)/i.test(window.location.pathname)) { return false; } /* report filters */
		if ($(form).find('.report-filter-bar').length || $(form).closest('.report-filter-bar').length) { return false; }
		/* Must have something a person can type into or choose. */
		var editable = false;
		$.each(form.elements, function (i, el) {
			var t = (el.type || '').toLowerCase();
			if (!el.name || el.disabled) { return; }
			if (t === 'hidden' || t === 'submit' || t === 'button' || t === 'reset' || t === 'image') { return; }
			editable = true;
			return false;
		});
		return editable;
	}

	/* ---------------------------------------------------------------
	 * Form values as one string, for comparing with the snapshot
	 * ------------------------------------------------------------- */
	function serialize(form) {
		var parts = [];
		$.each(form.elements, function (i, el) {
			var t = (el.type || '').toLowerCase();
			if (!el.name || el.name === CSRF_NAME) { return; }
			if (t === 'submit' || t === 'button' || t === 'reset' || t === 'image' || t === 'file') { return; }
			var v;
			if (t === 'checkbox' || t === 'radio') {
				v = el.checked ? (el.value || 'on') : '';
			} else if (t === 'select-multiple') {
				v = $.map(el.options, function (o) { return o.selected ? o.value : null; }).join(',');
			} else {
				v = el.value;
			}
			parts.push(el.name + '=' + v);
		});
		/* A file picked for upload counts as a change. */
		$(form).find('input[type=file]').each(function () { if (this.files && this.files.length) { parts.push(this.name + '=<file>'); } });
		return parts.join('&');
	}

	function snapshot(form) {
		form._ugSnap = serialize(form);
		form._ugTouched = false;
	}

	function isDirty(form) {
		return !!form._ugTouched && serialize(form) !== form._ugSnap;
	}

	function firstDirtyForm() {
		for (var i = 0; i < tracked.length; i++) {
			if (document.body.contains(tracked[i]) && isDirty(tracked[i])) { return tracked[i]; }
		}
		return null;
	}

	/* ---------------------------------------------------------------
	 * What to call the thing being edited ("invoice", "bill", ...)
	 * ------------------------------------------------------------- */
	var NAMES = [
		[/purchase_order/i, 'purchase order'],
		[/vendor_payment/i, 'vendor payment'],
		[/quotation/i, 'quotation'],
		[/invoice/i, 'invoice'],
		[/\/bill/i, 'bill'],
		[/expense/i, 'expense'],
		[/money_in/i, 'Money In entry'],
		[/money_out/i, 'Money Out entry'],
		[/transfer/i, 'transfer'],
		[/sales\/payment/i, 'payment'],
		[/payment/i, 'payment'],
		[/certificate/i, 'certificate'],
		[/credit/i, 'credit note'],
		[/refund/i, 'refund'],
		[/adjustment/i, 'stock adjustment'],
		[/wage/i, 'wage sheet'],
		[/worker/i, 'worker'],
		[/stock|store/i, 'stock movement'],
		[/projects/i, 'project'],
		[/entries/i, 'journal entry'],
		[/items/i, 'item'],
		[/customers/i, 'customer'],
		[/vendors/i, 'vendor'],
		[/ledgers/i, 'account'],
		[/groups/i, 'account group'],
		[/commission/i, 'commission'],
		[/settings/i, 'settings'],
		[/user/i, 'user']
	];

	function thingName(form) {
		var n = form.getAttribute('data-unsaved-name');
		if (n) { return n; }
		var path = window.location.pathname + ' ' + (form.getAttribute('action') || '');
		for (var i = 0; i < NAMES.length; i++) {
			if (NAMES[i][0].test(path)) { return NAMES[i][1]; }
		}
		return '';
	}

	/* The button "Save" presses: a marked one, else a draft save, else the
	   main save button, else the first submit button. */
	function saveButton(form) {
		var $subs = $(form).find('button[type=submit], input[type=submit], button:not([type])').filter(':visible');
		if (!$subs.length) { $subs = $(form).find('button[type=submit], input[type=submit], button:not([type])'); }
		var $b = $subs.filter('[data-unsaved-save]').first();
		if (!$b.length) { $b = $subs.filter(function () { return /draft/i.test(this.value || '') || /draft/i.test($(this).text()); }).first(); }
		if (!$b.length) { $b = $subs.filter('.btn-save').first(); }
		if (!$b.length) { $b = $subs.first(); }
		return $b.length ? $b[0] : null;
	}

	function buttonLabel(btn) {
		if (!btn) { return 'Save'; }
		var t = $.trim(btn.tagName === 'INPUT' ? btn.value : $(btn).text());
		return t && t.length <= 30 ? t : 'Save';
	}

	/* ---------------------------------------------------------------
	 * The dialog
	 * ------------------------------------------------------------- */
	var $modal = null;

	function buildModal() {
		if ($modal) { return $modal; }
		var css = '' +
			'#nidaamUnsavedModal .modal-content{border:0;border-radius:10px;box-shadow:0 12px 40px rgba(0,0,0,.25);}' +
			'#nidaamUnsavedModal .modal-body{padding:24px 24px 8px;display:flex;gap:16px;align-items:flex-start;}' +
			'#nidaamUnsavedModal .ug-icon{flex:0 0 44px;height:44px;border-radius:50%;background:#fff4e5;color:#e67e22;display:flex;align-items:center;justify-content:center;font-size:20px;}' +
			'#nidaamUnsavedModal h5{margin:2px 0 6px;font-weight:600;font-size:1.1rem;}' +
			'#nidaamUnsavedModal p{margin:0;color:#555;font-size:.92rem;}' +
			'#nidaamUnsavedModal .modal-footer{border-top:0;padding:16px 24px 20px;gap:8px;flex-wrap:wrap;}' +
			'#nidaamUnsavedModal .modal-footer .btn{min-width:96px;margin:0;}' +
			'#nidaamUnsavedModal .ug-spacer{flex:1 1 auto;}' +
			'@media (max-width:480px){#nidaamUnsavedModal .modal-footer .btn{flex:1 1 100%;}#nidaamUnsavedModal .ug-spacer{display:none;}}';
		$('<style id="nidaamUnsavedStyle"></style>').text(css).appendTo('head');
		$modal = $(
			'<div class="modal fade" id="nidaamUnsavedModal" tabindex="-1" role="dialog" aria-labelledby="nidaamUnsavedTitle" aria-hidden="true" data-backdrop="static">' +
				'<div class="modal-dialog modal-dialog-centered" role="document">' +
					'<div class="modal-content">' +
						'<div class="modal-body">' +
							'<div class="ug-icon"><i class="fas fa-exclamation-triangle"></i></div>' +
							'<div><h5 id="nidaamUnsavedTitle"></h5><p class="ug-msg"></p></div>' +
						'</div>' +
						'<div class="modal-footer">' +
							'<button type="button" class="btn btn-light ug-cancel">Cancel</button>' +
							'<span class="ug-spacer"></span>' +
							'<button type="button" class="btn btn-outline-danger ug-discard">Discard</button>' +
							'<button type="button" class="btn btn-primary ug-save"><i class="fas fa-check" style="margin-right:6px;"></i><span>Save</span></button>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>'
		).appendTo('body');
		return $modal;
	}

	function ask(form, href) {
		var name = thingName(form);
		var btn = saveButton(form);
		var $m = buildModal();
		$m.find('#nidaamUnsavedTitle').text(name ? 'Save this ' + name + '?' : 'Save your changes?');
		$m.find('.ug-msg').text('You have unsaved changes' + (name ? ' on this ' + name : ' on this page') +
			'. If you leave now, they will be lost.');
		$m.find('.ug-save span').text(buttonLabel(btn));
		$m.find('.ug-save').toggle(!!btn);

		$m.find('.ug-cancel').off('click').on('click', function () { $m.modal('hide'); });
		$m.find('.ug-discard').off('click').on('click', function () {
			leaving = true;
			$m.modal('hide');
			window.location.href = href;
		});
		$m.find('.ug-save').off('click').on('click', function () {
			$m.modal('hide');
			rememberAfterSave(href, name);
			/* Let the dialog close, then press the form's own save button so
			   the page's own checks (required fields, confirms) all run. If
			   they stop the save, the user stays here with the messages. */
			setTimeout(function () {
				if (btn) {
					btn.click();
					/* If nothing submitted (a check failed), forget the
					   "go on to" link so a later save stays on its page. */
					setTimeout(function () { if (!leaving) { forgetAfterSave(); } }, 1500);
				}
			}, 200);
		});
		if ($.fn.modal) {
			$m.modal('show');
			$m.one('shown.bs.modal', function () { $m.find('.ug-save:visible, .ug-cancel').first().trigger('focus'); });
		} else if (window.confirm('You have unsaved changes. Leave without saving?')) {
			leaving = true;
			window.location.href = href;
		}
	}

	/* ---------------------------------------------------------------
	 * "Save, then go where I clicked"
	 * ------------------------------------------------------------- */
	function store() { try { return window.sessionStorage; } catch (e) { return null; } }

	function rememberAfterSave(href, name) {
		var s = store();
		if (!s) { return; }
		try { s.setItem(AFTER_SAVE_KEY, JSON.stringify({ href: href, from: window.location.pathname, name: name, at: Date.now() })); } catch (e) {}
	}
	function forgetAfterSave() {
		var s = store();
		if (s) { try { s.removeItem(AFTER_SAVE_KEY); } catch (e) {} }
	}

	function continueAfterSave() {
		var s = store();
		if (!s) { return; }
		var raw = null;
		try { raw = s.getItem(AFTER_SAVE_KEY); } catch (e) {}
		if (!raw) { return; }
		forgetAfterSave();
		var info;
		try { info = JSON.parse(raw); } catch (e) { return; }
		if (!info || !info.href || Date.now() - info.at > 60000) { return; }
		var flags = window.NIDAAM_UNSAVED || {};
		/* The save failed (errors shown) - stay here so they can be fixed. */
		if (flags.hasError || $('.alert-danger:visible, .is-invalid:visible').length) { return; }
		/* Same page again with no "saved" message = it did not save. */
		if (window.location.pathname === info.from && !flags.savedOk) { return; }
		try { s.setItem(SAVED_NOTE_KEY, info.name || ''); } catch (e) {}
		leaving = true;
		window.location.href = info.href;
	}

	/* On the page they went on to: a short "saved" note, since the saved
	   page's own message was skipped. */
	function showSavedNote() {
		var s = store();
		if (!s) { return; }
		var name = null;
		try { name = s.getItem(SAVED_NOTE_KEY); s.removeItem(SAVED_NOTE_KEY); } catch (e) {}
		if (name === null) { return; }
		var msg = name ? 'Your ' + name + ' was saved.' : 'Your changes were saved.';
		if (window.toastr) { window.toastr.success(msg); }
	}

	/* ---------------------------------------------------------------
	 * Wiring
	 * ------------------------------------------------------------- */

	/* A form sent by code (form.submit()) is a deliberate save too. */
	var nativeSubmit = window.HTMLFormElement.prototype.submit;
	window.HTMLFormElement.prototype.submit = function () {
		leaving = true;
		return nativeSubmit.apply(this, arguments);
	};

	/* Real user actions (not code filling fields in on its own). */
	$(document).on('keydown mousedown touchstart paste', function (e) {
		lastUserAction = Date.now();
		var form = e.target && e.target.form ? e.target.form : $(e.target).closest('form')[0];
		if (form && form._ugSnap !== undefined && e.type !== 'mousedown') { form._ugTouched = true; }
		if (form && form._ugSnap !== undefined && e.type === 'mousedown' && $(e.target).closest('button, a, input, select, textarea, .select2-container, label').length) {
			form._ugTouched = true;
		}
	});
	$(document).on('input change', function (e) {
		var form = e.target && e.target.form;
		if (!form || form._ugSnap === undefined) { return; }
		/* A change within a moment of a click/key (a date picked, a Select2
		   option chosen, a line removed) is the user's. */
		if (Date.now() - lastUserAction < 1500) { form._ugTouched = true; }
	});

	/* A real submit (not stopped by the page's own checks) is not "leaving
	   with unsaved changes". Registered after the page's handlers, so it
	   sees whether they stopped it. */
	$(document).on('submit', 'form', function (e) {
		this._ugSubmitAt = Date.now();
		if (!e.isDefaultPrevented()) { leaving = true; }
	});

	/* Forms saved by AJAX (the page stays): count as saved. */
	$(document).ajaxSuccess(function (evt, xhr, opts) {
		if (!opts || (opts.type || opts.method || 'GET').toUpperCase() !== 'POST') { return; }
		$.each(tracked, function (i, f) {
			if (f._ugSubmitAt && Date.now() - f._ugSubmitAt < 15000) { snapshot(f); f._ugSubmitAt = 0; }
		});
	});

	/* Link clicks. */
	$(document).on('click', 'a[href]', function (e) {
		if (leaving || e.isDefaultPrevented()) { return; }
		if (e.which > 1 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }   /* new tab/window */
		var a = this;
		var raw = $.trim(a.getAttribute('href') || '');
		if (!raw || raw.charAt(0) === '#' || /^(javascript|mailto|tel|sms):/i.test(raw)) { return; }
		if (a.hasAttribute('download') || a.hasAttribute('data-toggle') || a.hasAttribute('data-widget') || a.hasAttribute('data-dismiss')) { return; }
		if ((a.getAttribute('target') || '').toLowerCase() === '_blank') { return; }
		if (a.getAttribute('data-unsaved') === 'off') { return; }
		/* Same page, only a #section jump. */
		if (a.pathname === window.location.pathname && a.search === window.location.search && a.hash) { return; }
		var form = firstDirtyForm();
		if (!form) { return; }
		e.preventDefault();
		e.stopImmediatePropagation();
		ask(form, a.href);
	});

	/* Closing the tab, refresh, browser Back. */
	window.addEventListener('beforeunload', function (e) {
		if (leaving || !firstDirtyForm()) { return; }
		e.preventDefault();
		e.returnValue = '';
		return '';
	});

	/* Coming back with the browser's Back button (page restored from cache). */
	window.addEventListener('pageshow', function (e) {
		if (e.persisted) { leaving = false; }
	});

	function start() {
		$('form').each(function () {
			if (isGuardable(this)) { tracked.push(this); snapshot(this); }
		});
		/* Pages fill in some fields by code after loading (Select2, default
		   lines, dates). Take the snapshot again once that has settled, as
		   long as the user has not started yet. */
		var loadedAt = Date.now();
		var resnap = function () {
			setTimeout(function () {
				$.each(tracked, function (i, f) { if (!f._ugTouched && lastUserAction < loadedAt) { snapshot(f); } });
			}, 700);
		};
		/* jQuery's ready can run after the load event on a fast (cached)
		   page, in which case $(window).on('load') would never fire. */
		if (document.readyState === 'complete') { resnap(); } else { $(window).on('load', resnap); }
		showSavedNote();
		continueAfterSave();
	}

	$(start);
})(window.jQuery, window, document);
