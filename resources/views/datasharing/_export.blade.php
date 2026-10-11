{{-- Excel / PDF / Print buttons for a list page: $route (route name) and $qs (the page's own filters). --}}
<a class="ds-btn ds-btn--sm" href="{{ route($route, $qs + ['as' => 'xlsx']) }}">Excel</a>
<a class="ds-btn ds-btn--sm" href="{{ route($route, $qs + ['as' => 'pdf']) }}" target="_blank" rel="noopener">PDF</a>
<button type="button" class="ds-btn ds-btn--sm" onclick="window.print()">Print</button>
