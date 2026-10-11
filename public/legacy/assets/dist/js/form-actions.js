/* =========================================================================
 * Buttons, the same on every page (Sept 30 2026) - desktop and phone.
 *
 *  1. Every form: one action bar fixed at the bottom of the window -
 *     Cancel on the left, the main button on the right.
 *  2. Clear names instead of "Submit" (Save Customer, Create Account,
 *     Save Changes ...). "Clear"/reset buttons are removed.
 *  3. "Save & Issue" on invoices and bills, "Save & New" on payments,
 *     expenses, money in/out, transfers, customers, vendors and items
 *     (the controllers read then=issue / then=new).
 *  4. Document pages (invoice, bill, quotation, payment ...): the status
 *     button and Edit stay, PDF / Print / Email / WhatsApp become icons,
 *     everything else goes under "More", with Void / Delete at the bottom
 *     in red. The Back button is gone (the menu and the browser do that).
 *     The toolbar stays in view while scrolling.
 *  5. No double saving: once a form is sent its buttons are locked and
 *     show a spinner.
 *  6. The app's own confirm box instead of the browser's pop-up. Void asks
 *     for a reason, which goes into the Activity Log.
 *  7. Desktop keys: Ctrl+S saves, Esc cancels (when not typing in a field).
 *
 * Loaded after myjs.js and unsaved-guard.js (see _base/foot.php).
 * A form can opt out of 1-3 with data-nd-bar="off".
 * ========================================================================= */
(function ($, window, document) {
	'use strict';
	if (!$ || !window.HTMLFormElement) { return; }

	/* ---------- where are we ---------- */
	var BASE = $('base').attr('href') || '/';
	var basePath = '/';
	try { basePath = new URL(BASE, window.location.href).pathname; } catch (e) {}
	var R = window.location.pathname;
	R = (R.indexOf(basePath) === 0 ? R.slice(basePath.length) : R.replace(/^\//, '')).replace(/^index\.php\/?/, '');
	var IS_REPORT = /^reports(\/|$)/.test(R);
	var IS_VIEW = /(_view|\/view)\/\d+/.test(R) || /^tasks\/view\//.test(R);
	var FORM_PAGE = /(^|\/|_)(add|add2|edit|edit2|create|new|form|write_off|money_in|money_out|transfer|settings|profile|change_password|import|reset)(\/|$|_)/;
	var TOUCH = window.matchMedia && window.matchMedia('(hover: none)').matches;

	function txt(el) { return $.trim(el.tagName === 'INPUT' ? (el.value || '') : $(el).text()).replace(/\s+/g, ' '); }
	function setTxt(el, t) {
		if (el.tagName === 'INPUT') { el.value = t; return; }
		var $el = $(el);
		$el.contents().filter(function () { return this.nodeType === 3; }).remove();
		$el.append(document.createTextNode(($el.children('i, .fas, .fa').length ? ' ' : '') + t));
	}
	function editableCount(form) {
		var n = 0;
		$.each(form.elements, function (i, el) {
			var t = (el.type || '').toLowerCase();
			if (!el.name || t === 'hidden' || t === 'submit' || t === 'button' || t === 'reset' || t === 'image') { return; }
			n++;
		});
		return n;
	}
	/* form.elements, not find(): on some older pages the HTML closes the
	   <form> early, so its buttons are not inside it in the page - the
	   browser still links them to it. */
	function submitsOf(form) {
		return $($.makeArray(form.elements)).filter('button[type=submit], input[type=submit], button:not([type])').filter(function () {
			return !$(this).closest('.modal, table, .dropdown-menu, .input-group, .nd-more').length;
		});
	}
	var formNo = 0;

	/* =====================================================================
	 * 1-3. The action bar on the page's main form
	 * =================================================================== */
	function mainForm() {
		if (IS_REPORT || IS_VIEW) { return null; }
		var best = null, bestN = 0;
		$('.content-wrapper form').each(function () {
			if ((this.getAttribute('method') || 'get').toLowerCase() !== 'post') { return; }
			if (this.getAttribute('data-nd-bar') === 'off' || this.getAttribute('data-unsaved') === 'off') { return; }
			if ($(this).closest('.modal, .dropdown-menu, table, .card-header').length) { return; }
			if (/inline/.test(this.getAttribute('style') || '')) { return; }
			var n = editableCount(this);
			if (n > bestN) { best = this; bestN = n; }
		});
		if (!best) { return null; }
		if (!FORM_PAGE.test(R) && bestN < 4) { return null; }
		if (!submitsOf(best).filter(':visible').length && !$(best).children('.form-actions').length) { return null; }
		return best;
	}

	var NAMES = [
		[/^customers\/add/, 'Save Customer'], [/^vendors\/add/, 'Save Vendor'], [/^items\/add/, 'Save Item'],
		[/^ledgers\/add/, 'Create Account'], [/^groups\/add/, 'Create Group'], [/^entries\/add/, 'Save Journal Entry'],
		[/create_user/, 'Create User'], [/create_account|^accounts\/add/, 'Create Company'], [/create_group/, 'Create Role'],
		[/(^|\/|_)edit/, 'Save Changes'], [/settings/, 'Save Settings'], [/change_password|reset/, 'Change Password'],
		[/import/, 'Import'], [/(^|\/|_)(add|create|new)/, 'Save']
	];
	var GENERIC = /^(submit|save|update|ok|submit form)$/i;

	function nameFor() {
		for (var i = 0; i < NAMES.length; i++) { if (NAMES[i][0].test(R)) { return NAMES[i][1]; } }
		return 'Save';
	}

	function fallbackCancel() {
		var ref = document.referrer || '';
		try {
			var u = new URL(ref);
			if (u.origin === window.location.origin && u.pathname !== window.location.pathname && !/\/(login|auth)/.test(u.pathname)) { return ref; }
		} catch (e) {}
		var seg = R.split('/')[0] || 'dashboard';
		return BASE + seg;
	}

	var SAVE_NEW = /^(ledgers\/add|entries\/add|sales\/payment_add|purchases\/vendor_payment_add|purchases\/expense_add|entries\/(money_in|money_out|transfer)|customers\/add|vendors\/add|items\/add)(\/|$)/;
	var SAVE_ISSUE = /^(sales\/invoice_(add|edit)|purchases\/bill_(add|edit))(\/|$)/;

	function buildBar(form) {
		var $f = $(form);
		$(form).addClass('nd-main-form');
		var $bar = $f.children('.form-actions').first();
		var $subs = submitsOf(form).filter(function () { return $(this).css('display') !== 'none'; });

		/* "Clear" / reset buttons are not needed */
		$f.find('input[type=reset], button[type=reset]').remove();

		if (!$bar.length) {
			if (!$subs.length) { return null; }
			$bar = $('<div class="form-actions nd-bar"></div>');
			if ($.contains(form, $subs[0])) {
				$bar.appendTo($f);
			} else {
				/* buttons outside the (early-closed) form: the bar goes after
				   their card, and each button names its form */
				if (!form.id) { form.id = 'ndForm' + (++formNo); }
				var $spot = $subs.first().closest('.card');
				if ($spot.length) { $bar.insertAfter($spot); } else { $bar.insertAfter($subs.first().parent()); }
				$bar.attr('data-form', form.id).addClass('nd-bar--out');
			}
			var $src = $subs.first().parent();
			var $footer = $subs.first().closest('.card-footer, .box-footer, .form-group, .text-right, .modal-footer');
			/* the Cancel / Back link next to the buttons */
			var $cancel = $src.add($footer).find('a.btn, a.btn-link').filter(function () {
				return /^(cancel|back|close|return|go back)$/i.test(txt(this)) || $(this).find('.fa-arrow-left, .fa-times').length;
			}).first();
			if ($cancel.length) { $bar.append($cancel); }
			$subs.each(function () { $bar.append(this); });
			/* hide what is left empty behind */
			$src.add($footer).each(function () {
				var $c = $(this);
				if ($c.is($f) || $c.find('.nd-bar').length) { return; }
				if (!$.trim($c.text()) && !$c.find('input:not([type=hidden]), select, textarea, button, a, img').length) { $c.addClass('nd-emptied'); }
			});
		} else {
			$bar.addClass('nd-bar');
		}

		/* classes: one look everywhere */
		var $btns = $bar.find('button[type=submit], input[type=submit], button:not([type])');
		$btns.removeClass('btn-primary btn-success btn-info btn-warning btn-default btn-secondary btn-block btn-flat btn-sm btn-lg float-right float-left pull-right pull-left').addClass('btn');
		$btns.each(function () { if (!$(this).hasClass('btn-save-alt')) { $(this).addClass('btn-save'); } });
		var $can = $bar.find('a.btn, a.btn-link').filter(function () {
			return /^(cancel|back|close|return|go back)$/i.test(txt(this)) || $(this).hasClass('btn-cancel');
		}).first();
		if (!$can.length) {
			$can = $('<a class="btn btn-cancel">Cancel</a>').attr('href', fallbackCancel());
		}
		$can.removeClass('btn-secondary btn-danger btn-default btn-light btn-link btn-sm float-right float-left pull-right pull-left').addClass('btn btn-cancel');
		if (/^back$|^go back$|^return$|^close$/i.test(txt($can[0]))) { setTxt($can[0], 'Cancel'); }
		$can.find('.fa-arrow-left, .fa-times').remove();
		$bar.find('.btn').css({ 'margin-left': '', 'margin-right': '', 'float': '' });
		$bar.prepend($can);

		/* 2. clear names */
		$btns.each(function () {
			if (GENERIC.test(txt(this))) { setTxt(this, nameFor()); }
		});

		/* 3a. Save & Issue on draft invoices / bills */
		if (SAVE_ISSUE.test(R)) {
			var $draft = $btns.filter(function () { return /save draft/i.test(txt(this)); }).first();
			if ($draft.length && !$bar.find('[name=then][value=issue]').length) {
				var what = /bill/.test(R) ? 'bill' : 'invoice';
				$draft.removeClass('btn-save').addClass('btn-save-alt').attr('data-unsaved-save', '');
				$('<button type="submit" name="then" value="issue" class="btn btn-save nd-save-issue"><i class="fas fa-check-double" style="margin-right:6px;"></i>Save &amp; Issue</button>')
					.attr('title', 'Save this ' + what + ' and post it to the books')
					.attr('data-nd-what', what)
					.insertAfter($draft);
			}
		}
		/* main button after the others in the page order (Cancel first).
		   On screen the order is set by CSS: Cancel | extra | main - but
		   pressing Enter in a field still presses the draft / main save,
		   never "Save & New". */
		$bar.append($bar.find('.btn-save').not('.btn-save-alt').last());
		/* 3b. Save & New */
		if (SAVE_NEW.test(R) && !$bar.find('[name=then][value=new]').length) {
			$('<button type="submit" name="then" value="new" class="btn btn-save-alt nd-save-new" title="Save, then start another one"><i class="fas fa-plus" style="margin-right:6px;"></i>Save &amp; New</button>')
				.appendTo($bar);
		}

		/* moved buttons keep their link to the form */
		if ($bar.hasClass('nd-bar--out')) { $bar.find('button, input[type=submit]').attr('form', form.id); }

		/* a bar only stays in view if no box around it clips (cards have
		   overflow:hidden for their rounded corners) */
		$bar.parentsUntil('.content-wrapper').each(function () {
			var o = window.getComputedStyle(this);
			if (/(hidden|auto|scroll|clip)/.test(o.overflow + o.overflowY)) { $(this).addClass('nd-overflow-ok'); }
		});

		/* 7. hints */
		if (!TOUCH) {
			var $ks = $(saveButton(form));
			if ($ks.length) { $ks.attr('title', ($ks.attr('title') ? $ks.attr('title') + ' ' : '') + '(Ctrl+S)'); }
			$can.attr('title', 'Cancel (Esc)');
		}
		return $bar;
	}

	/* the button Ctrl+S presses - same choice as the unsaved-changes guard:
	   a marked one, else a draft save, else the main save button */
	function saveButton(form) {
		var $s = submitsOf(form).filter(':visible');
		if (!$s.length) { $s = submitsOf(form); }
		var $b = $s.filter('[data-unsaved-save]').first();
		if (!$b.length) { $b = $s.filter(function () { return /draft/i.test(txt(this)); }).first(); }
		if (!$b.length) { $b = $s.filter('.btn-save').not('.nd-save-new').last(); }
		if (!$b.length) { $b = $s.first(); }
		return $b[0] || null;
	}

	/* =====================================================================
	 * 4. Document page toolbar
	 * =================================================================== */
	var QUICK = /fa-file-pdf|fa-print|fa-envelope|fa-whatsapp|fa-receipt/;
	var DANGER = /(^|\s)(void|delete|remove)(\s|$)/i;

	function tidyToolbar() {
		if (!IS_VIEW) { return; }
		var $h1 = $('.content h1.dashboard-card__title').first();
		if (!$h1.length) { return; }
		var $head = $h1.closest('.card-header');
		var $box = $head.children().filter(function () { return !$(this).is($h1) && !$(this).find($h1).length && $(this).find('.btn').length; }).last();
		if (!$box.length) { return; }

		/* each action = a link/button, or an inline form holding one */
		var acts = [];
		$box.children().each(function () {
			var $el = $(this);
			if ($el.is('form')) {
				if ($el.find('.btn').length) { acts.push({ el: this, btn: $el.find('.btn')[0] }); }
			} else if ($el.is('.btn, a, button')) {
				acts.push({ el: this, btn: this });
			}
		});
		if (acts.length < 3) { return; }

		var $card = $head.closest('.card');
		$card.addClass('nd-doc-card');
		/* a header-only card (projects) sticks as a whole */
		($card.children('.card-body').length ? $head : $card).addClass('nd-doc-head');

		var quick = [], keep = [], primary = [], more = [], danger = [];
		$.each(acts, function (i, a) {
			var $b = $(a.btn), label = txt(a.btn), icon = $b.find('i').attr('class') || '';
			if (/fa-arrow-left/.test(icon) || /^back$/i.test(label)) { $(a.el).remove(); return; }
			if (QUICK.test(icon)) { quick.push(a); return; }
			if ($b.hasClass('btn-primary')) { primary.push(a); return; }
			if (/fa-edit/.test(icon) || /^(edit|new .+)$/i.test(label)) { keep.push(a); return; }
			if ($b.hasClass('btn-danger') || DANGER.test(label)) { danger.push(a); return; }
			more.push(a);
		});
		/* too little to hide: leave it in the bar */
		if (more.length + danger.length < 2) { keep = keep.concat(more, danger); more = []; danger = []; }

		var $tools = $('<div class="nd-tools"></div>');
		$.each(quick, function (i, a) {
			var $b = $(a.btn), label = txt(a.btn);
			if (label) {
				if (!$b.attr('title')) { $b.attr('title', label); }
				$b.contents().filter(function () { return this.nodeType === 3; }).remove();
			}
			$b.addClass('nd-icon');
			$tools.append(a.el);
		});
		$.each(keep, function (i, a) { $tools.append(a.el); });
		if (more.length || danger.length) {
			var $more = $('<div class="nd-more"><button type="button" class="btn btn-secondary btn-sm nd-more__toggle" aria-haspopup="true" aria-expanded="false">More <i class="fas fa-caret-down"></i></button><div class="nd-more__menu" role="menu"></div></div>');
			var $menu = $more.find('.nd-more__menu');
			var asItem = function (a, isDanger) {
				var $b = $(a.btn);
				$b.removeClass('btn btn-sm btn-secondary btn-success btn-info btn-warning btn-danger btn-light btn-outline-secondary btn-link text-danger')
					.addClass('nd-more__item' + (isDanger ? ' nd-more__item--danger' : '')).attr('role', 'menuitem');
				if ($(a.el).is('form')) { $(a.el).css('display', 'block'); }
				$menu.append(a.el);
			};
			$.each(more, function (i, a) { asItem(a, false); });
			if (danger.length && more.length) { $menu.append('<div class="nd-more__sep"></div>'); }
			$.each(danger, function (i, a) { asItem(a, true); });
			$tools.append($more);
		}
		$.each(primary, function (i, a) { $(a.el).addClass('nd-primary'); $tools.append(a.el); });
		$box.empty().append($tools);
	}

	/* the More menu */
	$(document).on('click', '.nd-more__toggle', function (e) {
		e.preventDefault();
		var $m = $(this).closest('.nd-more');
		var open = !$m.hasClass('is-open');
		$('.nd-more.is-open').removeClass('is-open').find('.nd-more__toggle').attr('aria-expanded', 'false');
		$m.toggleClass('is-open', open);
		$(this).attr('aria-expanded', open ? 'true' : 'false');
	});
	$(document).on('click', function (e) {
		if (!$(e.target).closest('.nd-more').length) { $('.nd-more.is-open').removeClass('is-open'); }
	});
	$(document).on('click', '.nd-more__menu a, .nd-more__menu button', function () {
		var $m = $(this).closest('.nd-more');
		setTimeout(function () { $m.removeClass('is-open'); }, 0);
	});

	/* =====================================================================
	 * 6. Confirm box
	 * =================================================================== */
	var $dlg = null;
	function dialog() {
		if ($dlg) { return $dlg; }
		$dlg = $(
			'<div class="modal fade nd-confirm" id="ndConfirm" tabindex="-1" role="dialog" aria-labelledby="ndConfirmTitle" aria-hidden="true">' +
				'<div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">' +
					'<div class="modal-body">' +
						'<div class="nd-confirm__icon"><i class="fas fa-question"></i></div>' +
						'<div class="nd-confirm__text"><h5 id="ndConfirmTitle"></h5><p class="nd-confirm__msg"></p>' +
							'<div class="nd-confirm__reason"><label for="ndConfirmReason">Reason <span>(saved in the Activity Log)</span></label>' +
							'<textarea id="ndConfirmReason" class="form-control" rows="2" maxlength="180" placeholder="e.g. wrong customer, duplicate, cancelled order"></textarea>' +
							'<small class="nd-confirm__err">Please write a short reason.</small></div>' +
						'</div>' +
					'</div>' +
					'<div class="modal-footer">' +
						'<button type="button" class="btn btn-light nd-confirm__no" data-dismiss="modal">Cancel</button>' +
						'<button type="button" class="btn btn-primary nd-confirm__yes"></button>' +
					'</div>' +
				'</div></div>' +
			'</div>'
		).appendTo('body');
		$dlg.on('input', '#ndConfirmReason', function () { $dlg.find('.nd-confirm__reason').removeClass('has-error'); });
		$dlg.on('keydown', function (e) {
			if (e.key === 'Enter' && !$(e.target).is('textarea, button')) { e.preventDefault(); $dlg.find('.nd-confirm__yes').trigger('click'); }
		});
		return $dlg;
	}

	/* opts: {title, msg, yes, danger, reason} -> calls done(reasonText) on yes */
	function ask(opts, done) {
		if (!$.fn.modal) {
			if (window.confirm(opts.msg)) { done(opts.reason ? (window.prompt('Reason') || '') : ''); }
			return;
		}
		var $m = dialog();
		$m.toggleClass('nd-confirm--danger', !!opts.danger);
		$m.find('.nd-confirm__icon i').attr('class', 'fas ' + (opts.danger ? 'fa-exclamation-triangle' : 'fa-question'));
		$m.find('#ndConfirmTitle').text(opts.title);
		$m.find('.nd-confirm__msg').text(opts.msg).toggle(!!opts.msg);
		$m.find('.nd-confirm__reason').toggle(!!opts.reason).removeClass('has-error');
		$m.find('#ndConfirmReason').val('');
		$m.find('.nd-confirm__yes').text(opts.yes || 'OK')
			.toggleClass('btn-danger', !!opts.danger).toggleClass('btn-primary', !opts.danger)
			.off('click').on('click', function () {
				var r = $.trim($m.find('#ndConfirmReason').val());
				if (opts.reason && !r) { $m.find('.nd-confirm__reason').addClass('has-error'); $m.find('#ndConfirmReason').trigger('focus'); return; }
				$m.modal('hide');
				done(r);
			});
		$m.one('shown.bs.modal', function () { (opts.reason ? $m.find('#ndConfirmReason') : $m.find('.nd-confirm__yes')).trigger('focus'); });
		$m.modal('show');
	}

	/* the message a form/button asks before it goes - and neutralise the
	   old browser-pop-up versions so they never show as well */
	function confirmText(form, btn) {
		var msg = null;
		if (btn) {
			$.each(['data-nd-confirm', 'data-nidaam-confirm', 'data-issue-confirm'], function (i, k) {
				var v = btn.getAttribute(k);
				if (v && msg === null) { msg = v; }
				if (k !== 'data-nd-confirm' && v) { btn.setAttribute('data-nd-confirm', v); btn.removeAttribute(k); }
			});
			if (msg !== null) { return msg; }
		}
		var v2 = form.getAttribute('data-nd-confirm') || form.getAttribute('data-nidaam-confirm');
		if (form.hasAttribute('data-nidaam-confirm')) { form.setAttribute('data-nd-confirm', v2); form.removeAttribute('data-nidaam-confirm'); }
		if (v2) { return v2; }
		var src = form.getAttribute('onsubmit') || '';
		if (/confirm\s*\(/.test(src) && typeof form.onsubmit === 'function') {
			var got = null, real = window.confirm;
			window.confirm = function (m) { got = String(m); return false; };
			try { form.onsubmit.call(form, $.Event('submit')); } catch (e) {}
			window.confirm = real;
			if (got !== null) {
				form.onsubmit = null;
				form.removeAttribute('onsubmit');
				form.setAttribute('data-nd-confirm', got);
				return got;
			}
		}
		return null;
	}

	function titleFor(msg) {
		var first = (msg.split(/\n/)[0] || '').split(/\?\s/)[0];
		if (first.length <= 70 && /\?$/.test(first + '?') && msg.indexOf('?') !== -1) {
			var t = msg.slice(0, msg.indexOf('?') + 1);
			if (t.length <= 80 && t.indexOf('\n') === -1) { return { title: t, rest: $.trim(msg.slice(t.length)) }; }
		}
		return { title: 'Are you sure?', rest: msg };
	}

	/* Save & Issue: ask like the Issue button does */
	function issueText(btn) {
		var what = btn.getAttribute('data-nd-what') || 'invoice';
		var low = $('.item-cost-hint').filter(function () { return $.trim($(this).text()) !== '' && $(this).is(':visible'); }).length;
		return 'Save and issue this ' + what + '? It will be posted to the books.' +
			(low ? '\n' + low + ' line' + (low === 1 ? ' is' : 's are') + ' below cost - you will lose money on ' + (low === 1 ? 'it' : 'them') + '.' : '');
	}

	document.addEventListener('submit', function (e) {
		var form = e.target;
		if (!form || form.tagName !== 'FORM') { return; }
		if (form._ndOk) { form._ndOk = false; return; }
		var btn = e.submitter || form._ndLastBtn || null;
		var msg = (btn && btn.classList && btn.classList.contains('nd-save-issue')) ? issueText(btn) : confirmText(form, btn);
		if (!msg) { return; }
		e.preventDefault();
		e.stopImmediatePropagation();
		var action = form.getAttribute('action') || '';
		var isVoid = /(_void|\/void)(\/|$|\?)/i.test(action) || /^void this/i.test(msg);
		var danger = isVoid || /(delete|remove|_void|write_off|reset|clear)/i.test(action) || /^(delete|remove|void|clear|permanently)/i.test(msg);
		var label = btn ? txt(btn) : '';
		if (!label || label.length > 22) { label = isVoid ? 'Void' : (danger ? 'Delete' : 'Yes, continue'); }
		if (btn && btn.classList.contains('nd-save-issue')) { label = 'Save & Issue'; }
		var parts = titleFor(msg);
		if (!parts.rest && /^delete/i.test(parts.title)) { parts.rest = 'This cannot be undone.'; }
		ask({ title: parts.title, msg: parts.rest, yes: label, danger: danger, reason: isVoid }, function (reason) {
			$(form).find('input[name=void_reason]').remove();
			if (isVoid) { $('<input type="hidden" name="void_reason">').val(reason).appendTo(form); }
			form._ndOk = true;
			if (btn && form.requestSubmit && btn.form === form) {
				try { form.requestSubmit(btn); return; } catch (err) {}
			}
			if (btn && btn.name) { $('<input type="hidden">').attr('name', btn.name).val(btn.value).appendTo(form); }
			if (form.requestSubmit) { form.requestSubmit(); } else { $(form).trigger('submit'); }
		});
	}, true);

	/* buttons whose old handler asked on click (before the submit) */
	document.addEventListener('click', function (e) {
		var b = e.target && e.target.closest ? e.target.closest('button, input[type=submit]') : null;
		if (!b || !b.form) { return; }
		b.form._ndLastBtn = b;
		$.each(['data-nidaam-confirm', 'data-issue-confirm'], function (i, k) {
			var v = b.getAttribute(k);
			if (v) { b.setAttribute('data-nd-confirm', v); b.removeAttribute(k); }
		});
	}, true);

	/* =====================================================================
	 * 5. No double saving
	 * =================================================================== */
	$(document).on('submit', 'form', function (e) {
		var form = this;
		if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') { return; }
		if (form._ndBusy) { e.preventDefault(); return; }
		if (e.isDefaultPrevented() || form.getAttribute('target')) { return; }
		form._ndBusy = true;
		var btn = (e.originalEvent && e.originalEvent.submitter) || form._ndLastBtn;
		/* disable only after the browser has read the form (a disabled
		   button would not send its name, e.g. then=issue) */
		setTimeout(function () {
			var $all = $($.makeArray(form.elements)).filter('button[type=submit], input[type=submit], button:not([type])');
			$all.prop('disabled', true).addClass('nd-busy-off');
			if (btn) {
				var $b = $(btn).addClass('nd-busy');
				if (btn.tagName === 'INPUT') { $b.data('ndText', btn.value); btn.value = 'Saving…'; }
				else { $b.prepend('<span class="spinner-border spinner-border-sm nd-spin" role="status" aria-hidden="true"></span>'); }
			}
		}, 0);
		/* downloads / files: the page stays - unlock again */
		setTimeout(function () { unlock(form); }, 12000);
	});
	function unlock(form) {
		form._ndBusy = false;
		var $f = $($.makeArray(form.elements));
		$f.filter('.nd-busy-off').prop('disabled', false).removeClass('nd-busy-off');
		$f.find('.nd-spin').remove();
		$f.filter('.nd-busy').each(function () { if (this.tagName === 'INPUT' && $(this).data('ndText')) { this.value = $(this).data('ndText'); } }).removeClass('nd-busy');
	}
	window.addEventListener('pageshow', function (e) { if (e.persisted) { $('form').each(function () { unlock(this); }); } });

	/* =====================================================================
	 * 7. Keys
	 * =================================================================== */
	function somethingOpen() {
		return $('.modal.show, .select2-container--open, .dropdown-menu.show, .nd-more.is-open, .daterangepicker:visible, .nd-find:not([hidden]), .nd-msheet:not([hidden]), .nd-bell__menu.show').length > 0;
	}
	document.addEventListener('keydown', function (e) {
		var main = document.querySelector('form.nd-main-form');
		if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && (e.key === 's' || e.key === 'S')) {
			if ($('.modal.show').length) { return; }
			var b = main ? saveButton(main) : null;
			if (!b) { return; }
			e.preventDefault();
			if (document.activeElement && document.activeElement.blur && /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) {
				$(document.activeElement).trigger('change');
			}
			b.click();
			return;
		}
		if (e.key === 'Escape' && !e.defaultPrevented) {
			if (!main || somethingOpen()) { return; }
			if (/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.target.isContentEditable) { return; }
			var c = main.querySelector('.form-actions .btn-cancel') || (main.id ? document.querySelector('.nd-bar[data-form="' + main.id + '"] .btn-cancel') : null);
			if (c) { e.preventDefault(); c.click(); }
		}
	});

	/* =====================================================================
	 * 8. Back button and the way back (Oct 9 2026)
	 *    Every page except the menu's own pages gets "← Back to …" at the
	 *    top. It goes to the page the user came from, kept in a short
	 *    trail for this browser tab; with no trail (a link opened on its
	 *    own) it goes to the section's main page. Pages that already have
	 *    their own back link keep it.
	 * =================================================================== */
	var TRAIL_KEY = 'ndTrail.v1', SUBMIT_KEY = 'ndSubmitted.v1';
	var HERE = R + (window.location.search || '');
	function readTrail() { try { var t = JSON.parse(sessionStorage.getItem(TRAIL_KEY) || '[]'); return $.isArray(t) ? t : []; } catch (e) { return []; } }
	function saveTrail(t) { try { sessionStorage.setItem(TRAIL_KEY, JSON.stringify(t.slice(-30))); } catch (e) {} }
	function pathOnly(u) { return String(u || '').split('?')[0].replace(/\/+$/, ''); }
	function pageTitle() {
		if (/^dashboard(\/index)?$/.test(R) || R === '') { return 'Dashboard'; }
		var $h = IS_VIEW ? $('.content h1.dashboard-card__title:visible').first() : $();
		if (!$h.length) { $h = $('.page-header-bar h1, .dashboard-header__title').first(); }
		if (!$h.length) { $h = $('.content h1:visible').first(); }
		var t = $.trim(($h.text() || document.title || '').replace(/\s+/g, ' '));
		return t.length > 42 ? t.slice(0, 40) + '…' : t;
	}
	var trail = readTrail();
	(function updateTrail() {
		if (/^(login|auth|user\/(login|logout|activate))/.test(R)) { return; }
		/* the form that was just saved is not a place to go back to */
		var sent = null;
		try { sent = sessionStorage.getItem(SUBMIT_KEY); sessionStorage.removeItem(SUBMIT_KEY); } catch (e) {}
		if (sent && trail.length && pathOnly(trail[trail.length - 1].p) === pathOnly(sent) && pathOnly(sent) !== pathOnly(HERE)) { trail.pop(); }
		/* going back to a page already in the trail: cut the trail there */
		for (var i = trail.length - 1; i >= 0; i--) {
			if (pathOnly(trail[i].p) === pathOnly(HERE)) { trail = trail.slice(0, i); break; }
		}
		trail.push({ p: HERE, t: '' });
		saveTrail(trail);
	})();
	document.addEventListener('submit', function (e) {
		var f = e.target;
		if (f && f.tagName === 'FORM' && (f.getAttribute('method') || 'get').toLowerCase() === 'post') {
			try { sessionStorage.setItem(SUBMIT_KEY, HERE); } catch (err) {}
		}
	});
	var SECTION = { sales: 'Sales', purchases: 'Purchases', customers: 'Customers', vendors: 'Vendors', items: 'Inventory', projects: 'Projects', payroll: 'Payroll', accounts: 'Chart of Accounts', ledgers: 'Chart of Accounts', groups: 'Chart of Accounts', entries: 'Journal Entries', banking: 'Reconcile', tasks: 'Tasks', reports: 'Reports', account_settings: 'Settings', admin: 'System Dashboard', commissions: 'Commissions', transfer: 'Settings' };
	var SECTION_URL = { items: 'items/home', ledgers: 'accounts', groups: 'accounts', transfer: 'account_settings' };
	/* where Back (and Save & Close) go: {url, label} */
	function backTarget() {
		for (var i = trail.length - 2; i >= 0; i--) {
			if (pathOnly(trail[i].p) !== pathOnly(HERE)) { return { url: trail[i].p, label: trail[i].t || '' }; }
		}
		var seg = R.split('/')[0] || 'dashboard';
		if (/^items\/(add|edit|index|batches|adjust)/.test(R)) { return { url: 'items', label: 'Items' }; }
		return { url: SECTION_URL[seg] || seg, label: SECTION[seg] || 'Dashboard' };
	}
	/* remember this page's title for the next page's "Back to …" */
	$(function () { var last = trail[trail.length - 1]; if (last && pathOnly(last.p) === pathOnly(HERE)) { last.t = pageTitle(); saveTrail(trail); } });

	function menuPage() {
		if (/^(dashboard)(\/index|\/compare)?$/.test(R) || R === '') { return true; }
		var noIndex = function (x) { return x.replace(/\/index$/, ''); };
		var here = noIndex(pathOnly(R)), found = false;
		$('.nav-sidebar a[href], .nd-mnav a[href]').each(function () {
			var h = this.getAttribute('href') || '';
			if (!h || h.charAt(0) === '#') { return; }
			var p = pathOnly(this.pathname.indexOf(basePath) === 0 ? this.pathname.slice(basePath.length) : this.pathname.replace(/^\//, '')).replace(/^index\.php\/?/, '');
			if (noIndex(p) === here) { found = true; return false; }
		});
		return found;
	}
	/* Oct 9 2026: one Back for every page. The page's own older back links
	   (grey "← Customers", the section pill "← Sales", "← Back" buttons in
	   report and document toolbars) are taken out, and their address is
	   kept as the place to go when there is no trail. */
	function oldBackLinks($c) {
		return $c.find('a').filter(function () {
			var $a = $(this);
			if ($a.closest('.nd-crumb--auto, .form-actions, .nd-bar, table, .modal, .dropdown-menu, .nd-more__menu').length) { return false; }
			var t = $.trim($a.text());
			if (/^all results/i.test(t)) { return false; }
			return $a.hasClass('pv-back') || $a.hasClass('nd-crumb__back') || $a.find('.fa-arrow-left').length > 0;
		});
	}
	function addBack() {
		var $c = $('.content-wrapper > section.content, .content-wrapper > .content').first();
		if (!$c.length) { return; }
		var $old = oldBackLinks($c);
		if (menuPage() && !$old.length) { return; }
		var fallback = null;
		if ($old.length) {
			var $f = $old.first();
			var lbl = $.trim($f.text()).replace(/\s+/g, ' ').replace(/^(←\s*)?(back to|back|go to)\s*/i, '');
			var href = $f.attr('href') || '';
			try {
				var u = new URL(href, window.location.href);
				if (u.origin === window.location.origin && u.pathname.indexOf(basePath) === 0) {
					fallback = { url: u.pathname.slice(basePath.length).replace(/^index\.php\/?/, '') + u.search, label: lbl };
				}
			} catch (e) {}
			$old.each(function () {
				var $a = $(this), $p = $a.parent();
				if ($p.is('.nd-crumb')) { $p.remove(); return; }
				$a.remove();
				if ($p.is('p, div, span, nav') && !$.trim($p.text()) && !$p.children().length) { $p.remove(); }
			});
		}
		var t = backTarget();
		if (fallback && !trailHasBack()) { t = fallback; }
		var label = t.label ? 'Back to ' + t.label : 'Back';
		var $nav = $('<nav class="nd-crumb nd-crumb--auto" aria-label="Back"><a class="nd-crumb__back"><i class="fas fa-arrow-left" aria-hidden="true"></i> </a></nav>');
		$nav.find('a').attr('href', BASE + t.url).attr('title', label).append(document.createTextNode(label));
		$c.prepend($nav);
	}
	function trailHasBack() {
		for (var i = trail.length - 2; i >= 0; i--) { if (pathOnly(trail[i].p) !== pathOnly(HERE)) { return true; } }
		return false;
	}

	/* =====================================================================
	 * 9. Save & New / Save & Close (Oct 9 2026)
	 *    The extra buttons send nd_after (new | close) and nd_after_url;
	 *    after a successful save the server goes there instead of opening
	 *    the saved record (helpers/MY_url_helper.php). Invoices, bills
	 *    and credits: "Save & Issue ▾" holds Issue & New / Issue & Close.
	 *    Edit pages: saving goes back to where the user came from.
	 *    Phones: the extra buttons sit under a ▾ next to the main one.
	 * =================================================================== */
	var NEW_CLOSE = /^(ledgers\/add|groups\/add|entries\/add|sales\/payment_add|purchases\/vendor_payment_add|purchases\/expense_add|entries\/(money_in|money_out|transfer)|customers\/add|vendors\/add|items\/add|sales\/quotation_add|purchases\/purchase_order_add|items\/adjustment_add|projects\/wage_add|tasks\/add|payroll\/employee_add)(\/|$)/;
	var ISSUE_MENU = /^(sales\/invoice_(add|edit)|purchases\/bill_(add|edit)|sales\/credit_note_(add|edit)|purchases\/supplier_credit_(add|edit))(\/|$)/;
	var EDIT_PAGE = /(^|\/|_)edit(\/|$|_)/;

	function setAfter(form, after, url) {
		$(form).find('input[name=nd_after], input[name=nd_after_url]').remove();
		if (!after) { return; }
		$('<input type="hidden" name="nd_after">').val(after).appendTo(form);
		$('<input type="hidden" name="nd_after_url">').val(url).appendTo(form);
	}
	function newUrl() {
		var u = HERE;
		/* "& New" from an edit page opens a blank add form */
		if (EDIT_PAGE.test(R)) { u = R.replace(/_edit(\/.*)?$/, '_add').replace(/\/edit(\/.*)?$/, '/add'); }
		return u;
	}
	/* press the real save button, with nd_after set for this one save */
	function pressWith(form, btn, after) {
		var url = after === 'new' ? newUrl() : backTarget().url;
		setAfter(form, after, url);
		form._ndAfterPending = true;
		try { btn.click(); } finally { form._ndAfterPending = false; }
	}
	/* a normal click on a save button clears what an earlier "& New" left */
	document.addEventListener('click', function (e) {
		var b = e.target && e.target.closest ? e.target.closest('button[type=submit], input[type=submit], button:not([type])') : null;
		if (!b || !b.form || b.form._ndAfterPending) { return; }
		var f = b.form;
		if (f.classList.contains('nd-main-form') && EDIT_PAGE.test(R) && !ISSUE_MENU.test(R)) {
			var t = backTarget();
			setAfter(f, pathOnly(t.url) !== pathOnly(HERE) ? 'close' : '', t.url);
		} else if (f.classList.contains('nd-main-form') && EDIT_PAGE.test(R)) {
			var t2 = backTarget();
			setAfter(f, pathOnly(t2.url) !== pathOnly(HERE) ? 'close' : '', t2.url);
		} else {
			setAfter(f, '', '');
		}
	}, true);

	function altButton(label, icon, onClick) {
		return $('<button type="button" class="btn btn-save-alt nd-after-btn"><i class="fas ' + icon + '" style="margin-right:6px;"></i>' + label + '</button>').on('click', onClick);
	}
	function splitMenu($main, items, cls) {
		var $wrap = $('<div class="nd-more nd-more--up nd-split ' + (cls || '') + '"><button type="button" class="btn btn-save nd-more__toggle nd-split__toggle" aria-haspopup="true" aria-expanded="false" title="More ways to save"><i class="fas fa-caret-down" style="margin:0"></i></button><div class="nd-more__menu" role="menu"></div></div>');
		var $menu = $wrap.find('.nd-more__menu');
		$.each(items, function (i, it) {
			$('<button type="button" class="nd-more__item" role="menuitem"><i class="fas ' + it.icon + '"></i>' + it.label + '</button>').on('click', it.run).appendTo($menu);
		});
		$wrap.insertAfter($main);
		$main.addClass(/nd-phone-only/.test(cls || '') ? 'nd-split__main--phone' : 'nd-split__main');
		return $wrap;
	}

	function addAfterButtons(form, $bar) {
		if (!$bar || !$bar.length) { return; }
		if (ISSUE_MENU.test(R)) {
			var $issue = $bar.find('button, input[type=submit]').filter(function () { return /save\s*&(amp;)?\s*issue/i.test(txt(this)) || $(this).hasClass('nd-save-issue'); }).first();
			if (!$issue.length) { return; }
			var items = [];
			if (!/^sales\/credit_note_edit|^purchases\/supplier_credit_edit/.test(R)) { items.push({ label: 'Issue &amp; New', icon: 'fa-plus', run: function () { pressWith(form, $issue[0], 'new'); } }); }
			items.push({ label: 'Issue &amp; Close', icon: 'fa-check', run: function () { pressWith(form, $issue[0], 'close'); } });
			splitMenu($issue, items, 'nd-split--issue');
			/* the draft button: Save & Close / Save & New under its own ▾ on phones too */
			return;
		}
		if (!NEW_CLOSE.test(R)) { return; }
		var main = saveButton(form);
		if (!main) { return; }
		var $main = $(main);
		var $existingNew = $bar.find('.nd-save-new');
		if ($existingNew.length) { $existingNew.insertBefore($main); }
		var $new = $existingNew.length ? $existingNew : altButton('Save &amp; New', 'fa-plus', function () { pressWith(form, main, 'new'); }).insertBefore($main);
		var $close = altButton('Save &amp; Close', 'fa-check', function () { pressWith(form, main, 'close'); }).insertBefore($main);
		$new.addClass('nd-desk-only'); $close.addClass('nd-desk-only');
		splitMenu($main, [
			{ label: 'Save &amp; New', icon: 'fa-plus', run: function () { if ($existingNew.length) { $existingNew[0].click(); } else { pressWith(form, main, 'new'); } } },
			{ label: 'Save &amp; Close', icon: 'fa-check', run: function () { pressWith(form, main, 'close'); } }
		], 'nd-phone-only');
	}

	/* nav bar height, for the sticky document toolbar */
	function measure() {
		var h = $('.main-header').outerHeight() || 57;
		document.documentElement.style.setProperty('--nd-nav-h', h + 'px');
	}

	$(function () {
		measure();
		$(window).on('resize', measure);
		var f = mainForm();
		if (f) { var $nb = buildBar(f); try { addAfterButtons(f, $nb); } catch (e) {} }
		tidyToolbar();
		try { addBack(); } catch (e) { if (window.console) { console.error('addBack', e); } }
		/* stuck toolbar gets a shadow */
		var head = document.querySelector('.nd-doc-head');
		if (head && window.IntersectionObserver) {
			var probe = document.createElement('div');
			probe.style.cssText = 'height:1px;margin-bottom:-1px;';
			head.parentNode.insertBefore(probe, head);
			new IntersectionObserver(function (en) {
				head.classList.toggle('is-stuck', !en[0].isIntersecting);
			}, { rootMargin: '-' + ((parseInt(getComputedStyle(document.documentElement).getPropertyValue('--nd-nav-h'), 10) || 57) + 1) + 'px 0px 0px 0px' }).observe(probe);
		}
	});

	window.NidaamButtons = { ask: ask };
})(window.jQuery, window, document);
