<?php

namespace App\Http\Middleware;

use App\Services\Auth\SecurityService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** One device per user, 30 minutes idle sign-out, "sign out everywhere" and deactivated users: checked on every page of a signed-in person. */
class DeviceSession
{
    public function __construct(protected SecurityService $security) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }
        $this->security->ensureSchema();
        $result = $this->security->check($request, $user, $request->header('X-Background') === '1');
        if ($result) {
            return $this->signOut($request, $result['signout']);
        }

        return $next($request);
    }

    public function signOut(Request $request, string $reason)
    {
        $this->security->endSession($request, $reason);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($request->expectsJson()) {
            return response()->json(['status' => 'error', 'error' => SecurityService::MESSAGES[$reason] ?? 'Please sign in again.'], 401);
        }

        return redirect()->route('login')->with('message', SecurityService::MESSAGES[$reason] ?? 'Please sign in again.');
    }
}
