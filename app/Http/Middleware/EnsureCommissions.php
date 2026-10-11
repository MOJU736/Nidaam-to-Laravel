<?php

namespace App\Http\Middleware;

use App\Services\Commissions\CommissionService;
use Closure;
use Illuminate\Http\Request;

/** Commission pages exist only for a company that has Commissions switched on (Settings → Company); makes the tables on first use. */
class EnsureCommissions
{
    public function __construct(protected CommissionService $commissions) {}

    public function handle(Request $request, Closure $next)
    {
        if (! CommissionService::enabled()) {
            return redirect()->route('dashboard')->with('warning', 'Commissions are switched off for this company. An administrator can switch them on under Settings → Company.');
        }
        $this->commissions->ensureSchema();

        return $next($request);
    }
}
