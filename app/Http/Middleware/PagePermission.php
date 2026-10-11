<?php

namespace App\Http\Middleware;

use App\Services\Auth\Permissions;
use App\Services\DataSharing\DataSharingService;
use Closure;
use Illuminate\Http\Request;

/** Refuses a page the signed-in person's group may not open, and sends them somewhere they can (the page they came from, or their first allowed page). */
class PagePermission
{
    public function __construct(protected Permissions $permissions) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $route = $request->route();
        $name = $route?->getName();
        // a Data Sharing company with "Data Sharing only": people who are not administrators see only the Data Sharing pages
        if ($user && $name && ! str_starts_with($name, 'datasharing.') && ! str_starts_with($name, 'companies.') && $name !== 'logout' && DataSharingService::only()) {
            $g = $this->permissions->groupName($user);
            if (! in_array($g, ['admin', Permissions::RESTRICTED, Permissions::CUSTOMER_VIEWER], true)) {
                return $request->isMethod('get') ? redirect()->route('datasharing.index') : abort(403, 'This company only uses Data Sharing.');
            }
        }
        if (! $user || $this->permissions->allows($user, $name, $route?->parameter('type'))) {
            return $next($request);
        }

        $message = $this->permissions->denial($user, $name);
        if ($request->expectsJson()) {
            return response()->json(['status' => 'error', 'error' => $message, 'msg' => $message], 403);
        }
        // back to where they came from (when that is a different page they may open), else their first allowed page
        $back = url()->previous();
        $here = $request->fullUrl();
        if ($request->isMethod('get') === false && $back && $back !== $here && str_starts_with($back, $request->getSchemeAndHttpHost())) {
            return redirect($back)->with('warning', $message);
        }
        foreach (['dashboard', 'customers.index', 'reports.index', 'invoices.index', 'companies.index'] as $candidate) {
            if ($candidate !== $name && $this->permissions->allows($user, $candidate)) {
                // a viewer who simply landed on the Dashboard is not told off for it
                return redirect()->route($candidate)->with($name === 'dashboard' ? [] : ['warning' => $message]);
            }
        }
        abort(403, $message);
    }
}
