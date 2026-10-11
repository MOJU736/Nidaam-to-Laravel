{{-- Record payment + WhatsApp reminder windows, opened from any list with data-ds-pay / data-ds-wa buttons; also the shared dialog helpers (data-open, data-close, data-confirm). --}}
@unless ($exporting)
<dialog class="ds-modal" id="dsPayModal">
    <form method="POST" action="{{ route('datasharing.pay', 0) }}" id="dsPayForm">@csrf
        <input type="hidden" name="return" value="{{ $here }}">
        <h3>Record payment</h3>
        <div class="ds-form">
            <div><b data-f="name"></b><div class="ds-hint" data-f="info"></div></div>
            <div class="ds-row2">
                <label>Amount<input class="ds-field" name="amount" data-f="price" inputmode="decimal" required></label>
                <label>Paid by<select class="ds-field" name="method">{{ \App\Support\DsView::methodOptions('Cash') }}</select></label>
            </div>
            <div class="ds-row2">
                <label>Date<input class="ds-field" type="date" name="date" value="{{ date('Y-m-d') }}" min="{{ $cyc['start'] }}" max="{{ date('Y-m-d') }}" required></label>
                <div></div>
            </div>
            <div class="ds-post" id="dsPayHint" hidden></div>
            <p class="ds-hint">Each full month paid moves the due date on one month, on the same day. Less than the price is a part payment: it is kept, and the client still owes the rest. More pays ahead.</p>
            <label class="ds-check"><input type="checkbox" name="receipt" value="1" data-ds-receipt> Open a WhatsApp receipt after saving</label>
        </div>
        <div class="ds-foot"><button type="button" class="ds-btn" data-close>Cancel</button><button type="submit" class="ds-btn ds-btn--pri">Save payment</button></div>
    </form>
</dialog>
<dialog class="ds-modal" id="dsWaModal">
    <h3 id="dsWaTitle">WhatsApp reminder</h3>
    <div class="ds-form">
        <p class="ds-hint" data-f="to"></p>
        <div class="ds-wa" id="dsWaText"></div>
        <p class="ds-hint" id="dsWaHint" hidden>Select the text above, copy it, then paste it in WhatsApp.</p>
    </div>
    <div class="ds-foot"><button type="button" class="ds-btn" data-close>Close</button><a class="ds-btn ds-btn--good" id="dsWaOpen" href="#" target="_blank" rel="noopener">Open WhatsApp</a><button type="button" class="ds-btn ds-btn--pri" id="dsWaCopy">Copy message</button></div>
</dialog>
<script>
(function () {
    var q = function (s, r) { return (r || document).querySelector(s); }, qa = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    window.dsOpen = function (id) { var d = typeof id === 'string' ? document.getElementById(id) : id; if (d && !d.open) { d.showModal(); } return d; };
    document.addEventListener('click', function (e) {
        var c = e.target.closest('[data-close]'); if (c) { var d = c.closest('dialog'); if (d) { d.close(); } return; }
        var o = e.target.closest('[data-open]'); if (o) { dsOpen(o.getAttribute('data-open')); return; }
        var p = e.target.closest('[data-ds-pay]'); if (p) { pay(p); return; }
        var w = e.target.closest('[data-ds-wa]'); if (w) { wa(w); return; }
    });
    document.addEventListener('submit', function (e) { var m = e.target.getAttribute && e.target.getAttribute('data-confirm'); if (m && !window.confirm(m)) { e.preventDefault(); } });
    document.addEventListener('change', function (e) { var r = e.target; if (r.type === 'radio' && r.closest('.ds-opts')) { qa('label', r.closest('.ds-opts')).forEach(function (l) { l.classList.toggle('is-on', l.contains(r) && r.checked); }); } });
    var cur = {{ \Illuminate\Support\Js::from(\App\Support\Money::format('0.00')) }}, payInfo = { plan: 0, credit: 0 }, base = {{ \Illuminate\Support\Js::from(url('/datasharing/pay')) }};
    var money = function (v) { return cur.replace(/[0-9.,]+$/, '') + (Math.round(v * 100) / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); };
    var payHint = function () {
        var f = q('#dsPayForm'), a = parseFloat(String(q('[data-f=price]', f).value || '').replace(/[^0-9.]/g, '')) || 0, p = payInfo.plan, t = a + payInfo.credit, h = '';
        if (p > 0 && a > 0) {
            var n = Math.floor((t + 0.004) / p), left = Math.round((t - n * p) * 100) / 100;
            h = n === 0 ? 'Part payment &middot; ' + money(p - t) + ' still owed for this month' : 'Pays ' + n + ' month' + (n > 1 ? 's' : '') + (left > 0 ? ' &middot; ' + money(left) + ' kept toward the next month' : '');
            if (payInfo.credit > 0) { h += ' <small>(' + money(payInfo.credit) + ' paid before)</small>'; }
        }
        var el = q('#dsPayHint'); el.innerHTML = h ? '<div><span>' + h + '</span></div>' : ''; el.hidden = !h;
    };
    function pay(b) {
        var f = q('#dsPayForm');
        f.action = base + '/' + b.getAttribute('data-ds-pay');
        q('[data-f=name]', f).textContent = b.getAttribute('data-name'); q('[data-f=info]', f).textContent = b.getAttribute('data-info'); q('[data-f=price]', f).value = b.getAttribute('data-price');
        payInfo = { plan: +(b.getAttribute('data-plan') || b.getAttribute('data-price')), credit: +(b.getAttribute('data-credit') || 0) };
        payHint(); dsOpen('dsPayModal');
    }
    document.addEventListener('input', function (e) { if (e.target.matches && e.target.matches('#dsPayForm [data-f=price]')) { payHint(); } });
    try { if (localStorage.getItem('ds_receipt') === '1') { qa('[data-ds-receipt]').forEach(function (c) { c.checked = true; }); } } catch (err) {}
    document.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('[data-ds-receipt]')) { var v = e.target.checked; qa('[data-ds-receipt]').forEach(function (c) { c.checked = v; }); try { localStorage.setItem('ds_receipt', v ? '1' : '0'); } catch (err) {} } });
    function wa(b) {
        var msg = b.getAttribute('data-ds-wa'), phone = (b.getAttribute('data-phone') || '').replace(/[^0-9]/g, '');
        q('#dsWaTitle').textContent = b.getAttribute('data-title') || 'WhatsApp reminder';
        q('#dsWaText').textContent = msg;
        q('#dsWaModal [data-f=to]').textContent = 'To ' + b.getAttribute('data-name') + ' · ' + b.getAttribute('data-phone') + '. You check it before it goes; nothing is sent by itself.';
        var o = q('#dsWaOpen'); o.href = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(msg); o.hidden = phone.length < 9;
        q('#dsWaHint').hidden = true; dsOpen('dsWaModal');
    }
    q('#dsWaCopy').addEventListener('click', function () {
        var t = q('#dsWaText').textContent;
        var sel = function () { var r = document.createRange(); r.selectNodeContents(q('#dsWaText')); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); q('#dsWaHint').hidden = false; };
        try { navigator.clipboard.writeText(t).then(function () { q('#dsWaModal').close(); }, sel); } catch (err) { sel(); }
    });
    @if (! empty($receipt))
    document.addEventListener('DOMContentLoaded', function () {
        var b = document.createElement('button'); b.type = 'button'; b.hidden = true; b.setAttribute('data-ds-wa', {{ \Illuminate\Support\Js::from($receipt['msg']) }}); b.setAttribute('data-name', {{ \Illuminate\Support\Js::from($receipt['name']) }});
        b.setAttribute('data-phone', {{ \Illuminate\Support\Js::from((string) $receipt['phone']) }}); b.setAttribute('data-title', 'WhatsApp receipt'); document.body.appendChild(b); wa(b);
    });
    @endif
})();
</script>
@endunless
