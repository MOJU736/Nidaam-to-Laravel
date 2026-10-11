/* Calculations in every number box (Oct 3 2026 - "Every price section must
   accept calculation like the invoice and quotation").

   Every price, amount and quantity box in the app - any <input type=number>,
   any money box marked inputmode="decimal", the journal Debit / Credit boxes,
   and the invoice-style Qty / Unit Price boxes - accepts a sum like
   (120+80)*3, 1500/12 or 250*1.05:
     - while typing, a small "= 600.00" hint shows the answer;
     - Enter, Tab or leaving the box puts the answer in the box and fires
       input/change so totals on the page update;
     - something that can't be worked out (e.g. "12**") turns the box red
       and stops the form from being saved until it's fixed.
   Number boxes become text boxes so the normal keyboard (with * / ( ))
   shows on phones; the server still checks every number as before.
   Uses nidaamSafeEvalArithmetic() from myjs.js - no eval().
   A box can opt out with data-no-calc. */
(function () {
	'use strict';
	if (typeof window.nidaamSafeEvalArithmetic !== 'function') { return; }
	var DEC = parseInt(document.documentElement.getAttribute('data-nd-dec'), 10);
	if (isNaN(DEC) || DEC < 0) { DEC = 2; }

	var ITEM = function (el) { return el.classList.contains('item-qty') || el.classList.contains('item-price'); };
	var QTY_RE = /(qty|quantity|days|weight|percent|pct|hours|reorder_level|percent_done|row_count|every|margin|retention|\bmq\[|js-it-qty|js-cl-qty)/i;

	function isCandidate(el) {
		if (!el || el.tagName !== 'INPUT' || el.hasAttribute('data-no-calc')) { return false; }
		if (el.closest('.tp, .nd-ec, [data-no-calc]')) { return false; }
		var t = (el.getAttribute('type') || 'text').toLowerCase();
		if (t === 'number') { return true; }
		if (t !== 'text') { return false; }
		return el.classList.contains('nd-calc') || ITEM(el) || el.getAttribute('inputmode') === 'decimal' || el.classList.contains('dr-item') || el.classList.contains('cr-item');
	}

	/* how many decimals the answer keeps */
	function decimalsFor(el) {
		var step = el.getAttribute('data-step') || '';
		if (/^\d+(\.\d+)?$/.test(step)) { var p = step.split('.'); return p[1] ? p[1].length : 0; }
		var n = (el.name || '') + ' ' + (el.id || '') + ' ' + el.className;
		if (el.classList.contains('item-qty') || QTY_RE.test(n)) { return 3; }
		return DEC;
	}

	/* Thousands separators in amount boxes (Oct 5 2026): the box SHOWS
	   1,234,567.00 but its .value (what page scripts and jQuery .val()
	   read) stays 1234567.00, and setting .value adds the commas back.
	   Just before a form is sent the commas are taken out, and the server
	   strips any that still arrive (MY_Controller::strip_amount_commas). */
	var NATIVE = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
	function strip(v) { return String(v == null ? '' : v).replace(/,/g, ''); }
	function group(v) {
		var m = /^\s*(-?)(\d+)(\.\d*)?\s*$/.exec(v);
		return m ? m[1] + m[2].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (m[3] || '') : v;
	}
	function regroup(el) { if (NATIVE && el.__ndWrapped) { NATIVE.set.call(el, group(strip(NATIVE.get.call(el)))); } }
	function wrap(el) {
		if (!NATIVE || el.__ndWrapped || el.hasAttribute('data-no-commas')) { return; }
		el.__ndWrapped = true;
		try {
			Object.defineProperty(el, 'value', {
				configurable: true,
				get: function () { return strip(NATIVE.get.call(this)); },
				set: function (v) { NATIVE.set.call(this, group(strip(v))); }
			});
		} catch (e) { el.__ndWrapped = false; return; }
		regroup(el);
	}
	window.ndRegroup = function (root) { (root || document).querySelectorAll('input.nd-calc').forEach(regroup); };

	function prepare(el) {
		if (el.__ndCalc) { return; }
		el.__ndCalc = true;
		if ((el.getAttribute('type') || '').toLowerCase() === 'number') {
			var step = el.getAttribute('step');
			if (step !== null) { el.setAttribute('data-step', step === 'any' ? '' : step); }
			else { el.setAttribute('data-step', '1'); }
			if (el.getAttribute('min') !== null) { el.setAttribute('data-min', el.getAttribute('min')); }
			if (el.getAttribute('max') !== null) { el.setAttribute('data-max', el.getAttribute('max')); }
			el.removeAttribute('step'); el.removeAttribute('min'); el.removeAttribute('max');
			try { el.type = 'text'; } catch (e) { return; }
		}
		el.removeAttribute('inputmode');
		el.setAttribute('autocomplete', 'off');
		el.classList.add('nd-calc');
		wrap(el);
	}

	function scan(root) {
		(root || document).querySelectorAll('input').forEach(function (el) { if (isCandidate(el)) { prepare(el); } });
	}

	function isExpression(v) { return /[+\-*/()x×÷]/i.test(v.replace(/^\s*-/, '')); }
	function normalise(v) { return String(v).replace(/,/g, '').replace(/[x×]/gi, '*').replace(/÷/g, '/').trim(); }

	function evaluate(el) {
		var raw = normalise(el.value);
		if (raw === '') { return { ok: true, empty: true }; }
		if (/^-?\d*\.?\d+$/.test(raw)) { return { ok: true, value: parseFloat(raw), plain: true }; }
		var r = window.nidaamSafeEvalArithmetic(raw);
		if (r === null || !isFinite(r)) { return { ok: false }; }
		return { ok: true, value: r };
	}

	function fmt(n, d) { return parseFloat(n.toFixed(d)).toFixed(d); }
	function clean(n, d) { var s = n.toFixed(d); return d > 0 && /\./.test(s) && (d === 3) ? String(parseFloat(s)) : s; }

	/* the floating "= answer" hint */
	var hint = document.createElement('div');
	hint.className = 'nd-calc-hint';
	hint.hidden = true;
	document.addEventListener('DOMContentLoaded', function () { document.body.appendChild(hint); });
	function showHint(el, text, bad) {
		if (!hint.parentNode) { document.body.appendChild(hint); }
		var r = el.getBoundingClientRect();
		hint.textContent = text;
		hint.classList.toggle('is-bad', !!bad);
		hint.hidden = false;
		hint.style.left = Math.max(4, r.left + window.scrollX) + 'px';
		hint.style.top = (r.bottom + window.scrollY + 4) + 'px';
		hint.style.minWidth = Math.min(r.width, 220) + 'px';
	}
	function hideHint() { hint.hidden = true; }

	function setBad(el, bad) {
		el.classList.toggle('is-invalid', bad);
		el.classList.toggle('nd-calc-bad', bad);
		if (bad) { el.setAttribute('title', 'Can\'t calculate this - check the sum'); } else if (el.getAttribute('title') === 'Can\'t calculate this - check the sum') { el.removeAttribute('title'); }
	}

	/* puts the answer in the box (item Qty / Price boxes are done by myjs.js) */
	function commit(el) {
		var res = evaluate(el);
		if (!res.ok) { setBad(el, true); return false; }
		setBad(el, false);
		if (res.empty || res.plain || ITEM(el)) { return true; }
		var d = decimalsFor(el);
		el.value = clean(res.value, d);
		el.dispatchEvent(new Event('input', { bubbles: true }));
		el.dispatchEvent(new Event('change', { bubbles: true }));
		return true;
	}

	document.addEventListener('focusin', function (e) { if (isCandidate(e.target)) { prepare(e.target); } });
	document.addEventListener('input', function (e) {
		var el = e.target;
		if (!el.classList || !el.classList.contains('nd-calc')) { return; }
		if (el.classList.contains('nd-calc-bad')) { setBad(el, false); }
		var raw = normalise(el.value);
		if (!raw || !isExpression(raw)) { hideHint(); return; }
		var res = evaluate(el);
		if (res.ok && !res.empty) { showHint(el, '= ' + clean(res.value, decimalsFor(el))); }
		else { showHint(el, 'Keep typing…', false); }
	});
	document.addEventListener('keydown', function (e) {
		var el = e.target;
		if (e.key !== 'Enter' || !el.classList || !el.classList.contains('nd-calc') || ITEM(el)) { return; }
		var raw = normalise(el.value);
		if (raw && isExpression(raw)) { e.preventDefault(); commit(el); hideHint(); }
	});
	document.addEventListener('focusout', function (e) {
		var el = e.target;
		if (!el.classList || !el.classList.contains('nd-calc')) { return; }
		hideHint();
		var ok = commit(el);
		if (!ok) { showHint(el, 'Can\'t calculate this', true); setTimeout(hideHint, 2500); }
		else { setTimeout(function () { regroup(el); }, 0); }
	});

	/* never save a box that couldn't be worked out */
	document.addEventListener('submit', function (e) {
		var form = e.target, bad = null;
		form.querySelectorAll('input.nd-calc').forEach(function (el) {
			if (el.disabled || el.type === 'hidden') { return; }
			if (!commit(el) && !bad) { bad = el; }
		});
		if (!bad) {
			var sent = [];
			form.querySelectorAll('input.nd-calc').forEach(function (el) {
				if (NATIVE && el.__ndWrapped) { NATIVE.set.call(el, strip(NATIVE.get.call(el))); sent.push(el); }
			});
			/* if the page stays (validation, a confirm box...), show the commas again */
			setTimeout(function () { sent.forEach(regroup); }, 0);
		}
		if (bad) {
			e.preventDefault(); e.stopImmediatePropagation();
			bad.focus();
			showHint(bad, 'Can\'t calculate this - fix it before saving', true);
			setTimeout(hideHint, 3500);
		}
	}, true);

	/* boxes added later (new lines, windows) */
	var mo = new MutationObserver(function (list) {
		list.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType === 1) { if (n.tagName === 'INPUT') { if (isCandidate(n)) { prepare(n); } } else { scan(n); } } }); });
	});
	var start = function () { scan(document); mo.observe(document.body, { childList: true, subtree: true }); };
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
	window.ndCalcScan = scan;
})();
