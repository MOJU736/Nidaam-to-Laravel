/* Check-before-send email window (Oct 2 2026) - see
   application/views/_partials/email_compose.php. A "Send by Email" button
   (class js-nd-email, data-nd-email = JSON from FunctionsCore::emailButton())
   opens the window filled in; addresses are checked as they are typed and
   Send stays off until every one is valid and To has at least one. */
(function () {
	'use strict';
	var tpl = document.getElementById('ndEcTpl');
	if (!tpl || !tpl.content) { return; }
	document.body.appendChild(document.importNode(tpl.content, true));
	var modal = document.getElementById('ndEmailCompose');
	if (!modal) { return; }
	var form = document.getElementById('ndEcForm');
	var to = document.getElementById('ndEcTo');
	var cc = document.getElementById('ndEcCc');
	var bcc = document.getElementById('ndEcBcc');
	var subject = document.getElementById('ndEcSubject');
	var message = document.getElementById('ndEcMessage');
	var send = document.getElementById('ndEcSend');
	var first = document.getElementById('ndEcFirst');
	var hiddenBox = form.querySelector('.nd-ec__hidden');
	var summary = form.querySelector('.nd-ec__summary');
	var RE = /^[^\s@,;<>"']+@[^\s@,;<>"']+\.[a-z]{2,}$/i;

	function list(v) {
		return String(v || '').split(/[,;\s]+/).map(function (x) { return x.replace(/^[<"']+|[>"']+$/g, ''); }).filter(Boolean);
	}
	function row(name) { return form.querySelector('[data-ec-row="' + name + '"]'); }
	function showRow(name, on) {
		var r = row(name);
		r.hidden = !on;
		form.querySelector('[data-ec-show="' + name + '"]').hidden = on;
	}

	function check(input, required) {
		var all = list(input.value);
		var bad = all.filter(function (e) { return !RE.test(e); });
		var err = form.querySelector('.nd-ec__err[data-for="' + input.id + '"]');
		var msg = '';
		if (bad.length) {
			msg = (bad.length === 1 ? 'Not a valid email address: ' : 'Not valid email addresses: ') + bad.slice(0, 3).join(', ') + (/@[^.]*$/.test(bad[0]) ? ' - missing the ending, e.g. .com' : '');
		} else if (required && !all.length && input.dataset.touched) {
			msg = 'Enter the email address to send to.';
		}
		err.textContent = msg;
		input.classList.toggle('is-invalid', !!msg);
		return { ok: !bad.length && (!required || all.length > 0), list: all };
	}

	function refresh() {
		var a = check(to, true), b = check(cc, false), c = check(bcc, false);
		var ok = a.ok && b.ok && c.ok && subject.value.trim() !== '';
		var n = a.list.length + b.list.length + c.list.length;
		if (n > 20) { ok = false; }
		send.disabled = !ok;
		first.value = a.list[0] || '';
		summary.textContent = ok ? 'Goes to ' + n + ' address' + (n === 1 ? '' : 'es') : (n > 20 ? 'Up to 20 addresses' : '');
		return ok;
	}

	[to, cc, bcc, subject].forEach(function (el) {
		el.addEventListener('input', refresh);
		el.addEventListener('blur', function () { el.dataset.touched = '1'; refresh(); });
	});
	form.querySelectorAll('[data-ec-show]').forEach(function (b) {
		b.addEventListener('click', function () {
			var name = b.getAttribute('data-ec-show');
			showRow(name, true);
			(name === 'cc' ? cc : bcc).focus();
		});
	});

	function open(d) {
		form.setAttribute('action', d.action);
		modal.querySelector('#ndEcTitle span').textContent = d.title || 'Send by Email';
		to.value = d.to || ''; cc.value = d.cc || ''; bcc.value = '';
		[to, cc, bcc].forEach(function (el) { delete el.dataset.touched; el.classList.remove('is-invalid'); });
		showRow('cc', !!cc.value); showRow('bcc', false);
		subject.value = d.subject || ''; message.value = d.message || '';
		hiddenBox.innerHTML = '';
		var h = d.hidden || {};
		Object.keys(h).forEach(function (k) {
			var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = h[k]; hiddenBox.appendChild(i);
		});
		var save = form.querySelector('.nd-ec__save');
		save.hidden = !d.save;
		save.querySelector('input').disabled = !d.save;
		save.querySelector('span').textContent = d.save || '';
		var at = form.querySelector('.nd-ec__attach');
		at.hidden = !d.attach;
		at.querySelector('.nd-ec__attach-name').textContent = d.attach || '';
		var pv = at.querySelector('.nd-ec__preview');
		pv.hidden = !d.preview; pv.setAttribute('href', d.preview || '#');
		send.innerHTML = '<i class="fas fa-paper-plane"></i> Send';
		form._ecBusy = false;
		refresh();
		window.jQuery(modal).modal('show');
	}
	window.jQuery(modal).on('shown.bs.modal', function () { (to.value ? subject : to).focus(); });

	document.addEventListener('click', function (e) {
		var b = e.target.closest ? e.target.closest('.js-nd-email') : null;
		if (!b) { return; }
		e.preventDefault();
		var d;
		try { d = JSON.parse(b.getAttribute('data-nd-email')); } catch (err) { return; }
		/* a "..." menu that held the button closes first */
		if (window.jQuery) { window.jQuery(b).closest('.dropdown-menu').removeClass('show'); }
		if (!d.src) { open(d); return; }
		/* lists: the settings are fetched when the button is pressed */
		b.disabled = true;
		fetch(d.src, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				b.disabled = false;
				if (j && j.ok) { open(j); } else { window.alert((j && j.error) || 'Could not open the email window.'); }
			})
			.catch(function () { b.disabled = false; window.alert('Could not open the email window. Check the connection and try again.'); });
	});

	form.addEventListener('submit', function (e) {
		if (!refresh()) { e.preventDefault(); [to, cc, bcc].forEach(function (el) { el.dataset.touched = '1'; }); refresh(); return; }
		if (form._ecBusy) { e.preventDefault(); return; }
		form._ecBusy = true;
		send.disabled = true;
		send.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
	});
})();
