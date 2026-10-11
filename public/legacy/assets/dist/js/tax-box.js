/* One Tax box: number + % / $ / saved rate (Oct 3 2026). See
   views/_partials/tax_box.php. Keeps the hidden tax_percent / tax_total
   fields in step with what the user picks and fires 'input' + 'change'
   on them, so each form's own total maths (applyTaxPercent / recalc)
   runs exactly as it did with the old two boxes. */
(function () {
	'use strict';
	function fire(el) {
		['input', 'change'].forEach(function (t) { el.dispatchEvent(new Event(t, { bubbles: true })); });
	}
	function num(raw) {
		raw = String(raw || '').replace(/,/g, '').trim();
		if (raw === '') { return ''; }
		if (/^-?\d*\.?\d+$/.test(raw)) { return raw; }
		if (typeof window.nidaamSafeEvalArithmetic === 'function') {
			var r = window.nidaamSafeEvalArithmetic(raw.replace(/[x×]/gi, '*').replace(/÷/g, '/'));
			if (r !== null && isFinite(r)) { return String(Math.round(r * 1e6) / 1e6); }
		}
		return null; /* still typing / can't work out */
	}
	function setup(box) {
		if (box.__ndTax) { return; }
		box.__ndTax = true;
		var val = box.querySelector('.nd-taxbox__value');
		var sel = box.querySelector('.nd-taxbox__type');
		var pct = box.querySelector('input[name="tax_percent"]');
		var tot = box.querySelector('input[name="tax_total"]');
		var out = box.querySelector('.nd-taxbox__readout');
		var showReadout = box.hasAttribute('data-readout');
		var isAmount = function () { return sel.value === 'amount'; };
		val.classList.toggle('is-percent', !isAmount());
		sel.classList.toggle('is-rate', sel.value.charAt(0) === 'r');

		var readout = function () {
			if (!out) { return; }
			var t = parseFloat(tot.value);
			if (showReadout && !isAmount() && pct.value !== '' && !isNaN(t)) {
				out.textContent = '= ' + (window.ndMoney ? ndMoney(t) : t.toFixed(2)) + ' tax';
			} else { out.textContent = ''; }
		};
		var sync = function () {
			var n = num(val.value);
			if (n === null) { return; }
			if (isAmount()) {
				pct.value = '';
				tot.value = n === '' ? '0.00' : n;
				fire(pct); fire(tot);
			} else {
				pct.value = n;
				if (n === '') { tot.value = '0.00'; fire(tot); }
				fire(pct);
			}
			box.classList.toggle('is-amount', isAmount());
			sel.classList.toggle('is-rate', sel.value.charAt(0) === 'r');
			val.classList.toggle('is-percent', !isAmount()); /* calc-fields keeps up to 3 decimals for a % */
			setTimeout(readout, 0);
		};

		sel.addEventListener('change', function () {
			var o = sel.options[sel.selectedIndex];
			if (o && o.hasAttribute('data-rate')) { val.value = o.getAttribute('data-rate'); }
			sync();
		});
		val.addEventListener('input', function () {
			var o = sel.options[sel.selectedIndex];
			if (o && o.hasAttribute('data-rate') && num(val.value) !== o.getAttribute('data-rate')) { sel.value = 'percent'; }
			sync();
		});
		val.addEventListener('change', sync);

		/* the totals move when lines change - keep the "= amount" note fresh */
		var form = box.closest('form') || document;
		var t = null;
		['input', 'change', 'click'].forEach(function (ev) {
			form.addEventListener(ev, function () { clearTimeout(t); t = setTimeout(readout, 30); });
		});
		/* work the tax out once on load (e.g. the default rate on a new document) */
		setTimeout(sync, 60);
	}
	function start() { document.querySelectorAll('[data-nd-taxbox]').forEach(setup); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
