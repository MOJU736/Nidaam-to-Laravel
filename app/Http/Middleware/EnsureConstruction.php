<?php

namespace App\Http\Middleware;

use App\Services\Construction\ConstructionService;
use Closure;
use Illuminate\Http\Request;

/** Construction pages exist only for a company that has Construction switched on (Settings → Company details); makes the tables on first use. */
class EnsureConstruction
{
    public function __construct(protected ConstructionService $construction) {}

    public function handle(Request $request, Closure $next)
    {
        if (! ConstructionService::enabled()) {
            return redirect()->route('dashboard')->with('error', 'Projects are part of Construction, which is turned off for this company. An administrator can switch it on under Settings → Company details.');
        }
        $this->construction->ensureTables();

        return $next($request);
    }
}
