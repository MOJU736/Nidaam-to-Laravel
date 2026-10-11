@extends($exporting ? 'datasharing.bare' : 'layouts.main')
@section('title', 'Data Sharing - Cycle '.$cd['no'].' presentation')
@section('content')
@include('datasharing._style')
@php $q = ['cycle' => (int) $rc['id']]; $n = count($slides); @endphp
<section class="ds">
    <div class="ds-ph"><div><h1>Cycle {{ (int) $cd['no'] }} presentation</h1><p>{{ \App\Support\DsView::d($cd['start']) }} &ndash; {{ \App\Support\DsView::d($cd['end']) }}{{ $cd['closed'] ? ' · closed' : ' · so far' }}</p></div>
        <div class="ds-tools">
            <a class="ds-btn ds-btn--sm" href="{{ route('datasharing.deck', $q + ['as' => 'pdf']) }}" target="_blank" rel="noopener">PDF</a>
            <a class="ds-btn ds-btn--sm" href="{{ route('datasharing.deck', $q + ['as' => 'xlsx']) }}">Excel</a>
            <a class="ds-btn ds-btn--sm" href="{{ route('datasharing.reports', $cd['closed'] ? $q : []) }}">Reports</a>
        </div></div>
    <div class="ds-deck" id="dsDeck">
        @foreach ($slides as $i => $slide)
            <section class="ds-slide" aria-label="{{ $i + 1 }} of {{ $n }}" {{ $i ? 'hidden' : '' }}>@include('datasharing._slide', ['slide' => $slide])</section>
        @endforeach
        <div class="ds-deck__nav">
            <button type="button" class="ds-btn ds-btn--sm" data-deck="prev">&larr; Previous</button>
            <div class="ds-dots">@for ($i = 0; $i < $n; $i++)<button type="button" data-deck="{{ $i }}" aria-label="Slide {{ $i + 1 }}" @class(['on' => $i === 0])></button>@endfor</div>
            <span class="ds-hint" id="dsDeckN">1 / {{ $n }}</span>
            <button type="button" class="ds-btn ds-btn--sm ds-btn--pri" data-deck="next">Next &rarr;</button>
        </div>
    </div>
</section>
<script>
(function () {
    var deck = document.getElementById('dsDeck'); if (!deck) { return; }
    var slides = deck.querySelectorAll('.ds-slide'), dots = deck.querySelectorAll('.ds-dots button'), i = 0;
    var show = function (k) {
        i = Math.max(0, Math.min(slides.length - 1, k));
        for (var j = 0; j < slides.length; j++) { slides[j].hidden = j !== i; dots[j].className = j === i ? 'on' : ''; }
        document.getElementById('dsDeckN').textContent = (i + 1) + ' / ' + slides.length;
        deck.querySelector('[data-deck=prev]').disabled = i === 0; deck.querySelector('[data-deck=next]').disabled = i === slides.length - 1;
    };
    deck.addEventListener('click', function (e) { var b = e.target.closest('[data-deck]'); if (!b) { return; } var v = b.getAttribute('data-deck'); show(v === 'next' ? i + 1 : v === 'prev' ? i - 1 : +v); });
    document.addEventListener('keydown', function (e) { if (e.target && /input|select|textarea/i.test(e.target.tagName)) { return; } if (e.key === 'ArrowRight') { show(i + 1); } else if (e.key === 'ArrowLeft') { show(i - 1); } });
    show(0);
})();
</script>
@endsection
