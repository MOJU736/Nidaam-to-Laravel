<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** A person given a temporary password can do nothing until they have chosen their own. */
class MustChangePassword
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && $user->must_change_password && ! in_array($request->route()?->getName(), ['password.first', 'password.first.save', 'logout'], true)) {
            return redirect()->route('password.first');
        }

        return $next($request);
    }
}
