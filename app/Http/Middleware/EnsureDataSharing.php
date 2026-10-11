<?php

namespace App\Http\Middleware;

use App\Services\DataSharing\DataSharingService;
use Closure;
use Illuminate\Http\Request;

/** Data Sharing pages exist only for a company that has Data Sharing switched on (Settings → Company); makes the tables on first use. */
class EnsureDataSharing
{
    public function __construct(protected DataSharingService $ds) {}

    public function handle(Request $request, Closure $next)
    {
        if (! DataSharingService::enabled()) {
            return redirect()->route('dashboard')->with('warning', 'Data Sharing is not switched on for this company. An administrator can switch it on under Settings → Company.');
        }
        if (! $this->ds->ensureSchema()) {
            return redirect()->route('dashboard')->with('error', 'Could not set up the Data Sharing tables for this company. Nothing was changed.');
        }

        return $next($request);
    }
}
