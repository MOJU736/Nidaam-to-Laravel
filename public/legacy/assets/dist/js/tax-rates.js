/* Tax rate picker (Oct 2 2026). Puts a "Tax" list (No tax / saved rates /
   Other %) in front of the form's Tax % box; picking a rate fills the box
   and fires 'input' so the form's own total maths runs as before. The
   rates come from views/_partials/tax_rate_picker.php. */
(function () {
	'use strict';
	var cfg = window.NDTaxRates;
	if (!cfg || !cfg.rates || !cfg.rates.length) { return; }
	var input = document.querySelector('input[name="tax_percent"]');
	if (!input || input.getAttribute('data-nd-tax') === 'on') { return; }
	input.setAttribute('data-nd-tax', 'on');
	var norm = function (v) { v = parseFloat(v); return isNaN(v) ? '' : String(Math.round(v * 1000) / 1000); };
	var sel = document.createElement('select');
	sel.className = 'form-control nd-taxpick';
	sel.setAttribute('aria-label', 'Tax');
	var add = function (value, text) { var o = document.createElement('option'); o.value = value; o.textContent = text; sel.appendChild(o); return o; };
	add('', 'No tax');
	cfg.rates.forEach(function (r) { add('r' + r.id, r.name + ' ' + r.rate + '%').setAttribute('data-rate', r.rate); });
	add('other', 'Other %');
	input.parentNode.insertBefore(sel, input);
	var label = input.parentNode.querySelector('label');
	if (label && /tax\s*%/i.test(label.textContent)) { label.innerHTML = 'Tax <span class="opt">(optional)</span>'; }

	var fire = function () {
		['input', 'change'].forEach(function (t) { var e = document.createEvent('HTMLEvents'); e.initEvent(t, true, false); input.dispatchEvent(e); });
	};
	var show = function (other) { input.style.display = other ? '' : 'none'; if (other) { input.focus(); } };
	var pick = function () {
		var v = sel.value;
		if (v === 'other') { show(true); return; }
		show(false);
		input.value = v === '' ? '' : sel.options[sel.selectedIndex].getAttribute('data-rate');
		fire();
	};
	/* start from what the document already has */
	var current = norm(input.value);
	var match = null;
	cfg.rates.forEach(function (r) { if (match === null && current !== '' && norm(r.rate) === current) { match = r; } });
	if (match) { sel.value = 'r' + match.id; show(false); }
	else if (current === '') {
		var def = null;
		cfg.rates.forEach(function (r) { if (r['default']) { def = r; } });
		if (cfg.isNew && def) { sel.value = 'r' + def.id; show(false); input.value = def.rate; var later = function () { setTimeout(fire, 50); }; if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', later); } else { later(); } }
		else { sel.value = ''; show(false); }
	} else { sel.value = 'other'; show(true); input.blur(); }
	sel.addEventListener('change', pick);
})();
