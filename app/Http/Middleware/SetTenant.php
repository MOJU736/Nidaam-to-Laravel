<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;

/** Activates the company database chosen at login (session `account_id`). */
class SetTenant
{
    public function __construct(protected TenantManager $tenants) {}

    public function handle(Request $request, Closure $next)
    {
        $id = $request->session()->get('account_id');
        abort_unless($id, 403, 'No company selected.');
        $this->tenants->activate((int) $id);

        return $next($request);
    }
}
