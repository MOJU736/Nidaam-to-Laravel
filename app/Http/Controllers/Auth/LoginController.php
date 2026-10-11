<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Master\LoginHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sign-in against the legacy `users` table. Mirrors the old app's rules:
 * username or email, bcrypt passwords, 10 wrong passwords = 15 minute lock.
 */
class LoginController extends Controller
{
    const LOCK_TRIES = 10;
    const LOCK_SECONDS = 900;

    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'identity' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);
        $identity = trim($data['identity']);

        if (($left = $this->lockLeft($identity)) > 0) {
            $this->record($identity, 'locked');
            $mins = max(1, (int) ceil($left / 60));

            return back()->withInput($request->only('identity'))->withErrors([
                'identity' => "Too many wrong passwords. This account is locked for {$mins} more minute".($mins === 1 ? '' : 's').'.',
            ]);
        }

        $user = User::where('username', $identity)->orWhere('email', $identity)->orderByDesc('id')->first();

        if (! $user || ! $user->active || ! Auth::attempt(['id' => $user->id, 'password' => $data['password']])) {
            $this->record($identity, 'failed', $user?->id);

            return back()->withInput($request->only('identity'))->withErrors([
                'identity' => $user && ! $user->active ? 'This account is not active.' : 'Incorrect username or password.',
            ]);
        }

        if (app(\App\Services\Auth\PasswordService::class)->tempExpired($user)) {
            Auth::logout();
            $this->record($identity, 'failed', $user->id);

            return back()->withInput($request->only('identity'))->withErrors(['identity' => 'The temporary password has expired. Use "Forgot your password?" or ask an administrator for a new link.']);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login' => time()])->save();
        $this->record($identity, 'signin', $user->id, 'password');

        return redirect()->route('companies.index');
    }

    public function logout(Request $request)
    {
        if ($user = $request->user()) {
            $this->record($user->username, 'logout', $user->id);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** Seconds left on the lock, 0 when not locked. */
    protected function lockLeft(string $identity): int
    {
        $lastGood = LoginHistory::where('identity', $identity)->whereIn('event', ['signin', 'reset_done'])->max('created_at') ?? 0;
        $since = max(time() - self::LOCK_SECONDS, $lastGood);
        $q = LoginHistory::where('identity', $identity)->where('event', 'failed')->where('created_at', '>', $since);

        if ($q->count() < self::LOCK_TRIES) {
            return 0;
        }

        return max(0, ((int) $q->max('created_at') + self::LOCK_SECONDS) - time());
    }

    protected function record(string $identity, string $event, ?int $userId = null, ?string $how = null): void
    {
        LoginHistory::create([
            'user_id' => $userId,
            'identity' => substr($identity, 0, 100),
            'event' => $event,
            'how' => $how,
            'ip_address' => substr((string) request()->ip(), 0, 45),
            'user_agent' => substr((string) request()->userAgent(), 0, 255),
            'created_at' => time(),
        ]);
    }
}
