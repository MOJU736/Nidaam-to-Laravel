<?php

namespace App\Services\Auth;

use App\Models\Master\LoginHistory;
use App\Models\User;
use App\Services\Documents\EmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in security on top of the password rules (ported from the legacy Security_model):
 *  1. one device per user - a new sign-in signs the older one out;
 *  2. signed out after 30 minutes without activity;
 *  3. login history - every sign-in, sign-out and wrong password (rows are in the master database);
 *  4. "Sign out everywhere" (admin, also on a password change or when a user is deactivated);
 *  5. an email when someone signs in from a device the user has not used before;
 *  6. "keep me signed in" (a remember cookie; every sign-in on another browser cancels it).
 * The session token lives in users.session_token; the browser's own id is a cookie (nd_device) kept for two years.
 */
class SecurityService
{
    public const IDLE_SECONDS = 1800;

    public const IDLE_GRACE = 45; // the page's own timer signs out first

    public const DEVICE_COOKIE = 'nd_device';

    public const REASONS = [
        'logout' => 'Signed out', 'other_device' => 'Signed in on another device', 'idle' => 'Signed out after 30 minutes without activity',
        'admin' => 'Signed out by an admin', 'password' => 'Password was changed', 'deactivated' => 'User was deactivated',
    ];

    public const MESSAGES = [
        'other_device' => 'You were signed out because your account was signed in on another device or browser.',
        'idle' => 'You were signed out after 30 minutes without activity. Please sign in again.',
        'admin' => 'An administrator signed you out. Please sign in again.',
        'password' => 'Your password was changed. Please sign in with the new password.',
        'deactivated' => 'Your user has been deactivated. Please contact your administrator.',
    ];

    protected function db()
    {
        return DB::connection('master');
    }

    /** The remember-me column has to hold Laravel's 60-character token (the legacy column held 25); made once, flagged by a file. */
    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $flag = storage_path('app/nd_security_v1.flag');
        if (is_file($flag)) {
            return;
        }
        try {
            $col = collect($this->db()->select("SHOW COLUMNS FROM users LIKE 'remember_code'"))->first();
            if ($col && preg_match('/varchar\((\d+)\)/i', (string) $col->Type, $m) && (int) $m[1] < 100) {
                $this->db()->statement('ALTER TABLE users MODIFY remember_code VARCHAR(100) NULL DEFAULT NULL');
            }
            if (! Schema::connection('master')->hasTable('user_devices')) {
                $this->db()->statement('CREATE TABLE user_devices (id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, device_hash CHAR(64) NOT NULL, label VARCHAR(80) NULL, first_seen INT(11) NOT NULL, last_seen INT(11) NOT NULL, PRIMARY KEY (id), UNIQUE KEY uq_user_device (user_id, device_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8');
            }
            @file_put_contents($flag, date('c'));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** "Chrome on Android", "Safari on iPhone", "Edge on Windows" ... */
    public function deviceLabel(?string $ua = null): string
    {
        $ua ??= (string) request()->userAgent();
        $browser = 'Browser';
        foreach (['/Edg(e|A|iOS)?\//' => 'Edge', '/OPR\/|Opera/' => 'Opera', '/SamsungBrowser/' => 'Samsung Internet', '/Firefox\/|FxiOS/' => 'Firefox', '/Chrome\/|CriOS/' => 'Chrome', '/Safari\//' => 'Safari'] as $re => $name) {
            if (preg_match($re, $ua)) {
                $browser = $name;
                break;
            }
        }
        $os = 'unknown device';
        foreach (['/iPhone/' => 'iPhone', '/iPad/' => 'iPad', '/Android/' => 'Android', '/Windows/' => 'Windows', '/Mac OS X|Macintosh/' => 'Mac', '/CrOS/' => 'Chromebook', '/Linux/' => 'Linux'] as $re => $name) {
            if (preg_match($re, $ua)) {
                $os = $name;
                break;
            }
        }

        return "$browser on $os";
    }

    /** This browser's own random id (a cookie for 2 years; made on first use). */
    protected function deviceId(Request $request): string
    {
        $id = (string) $request->cookie(self::DEVICE_COOKIE);
        if (! preg_match('/^[a-f0-9]{40,64}$/', $id)) {
            $id = bin2hex(random_bytes(24));
            Cookie::queue(Cookie::make(self::DEVICE_COOKIE, $id, 60 * 24 * 730, '/', null, $request->isSecure(), true, false, 'Lax'));
            $request->cookies->set(self::DEVICE_COOKIE, $id);
        }

        return $id;
    }

    /**
     * A new sign-in on this browser: makes it the only valid session for the user (every other device is signed out on its next
     * click), records it, and emails the user when the device is new. $how: password | remembered | resumed | invite
     */
    public function startSession(User $user, Request $request, string $how = 'password'): void
    {
        $this->ensureSchema();
        $now = time();
        $token = bin2hex(random_bytes(32));
        $this->db()->table('users')->where('id', $user->id)->update(['session_token' => $token]);
        $this->db()->table('login_history')->where('user_id', $user->id)->where('event', 'signin')->whereNull('ended_at')->update(['ended_at' => $now, 'end_reason' => 'other_device']);

        $label = $this->deviceLabel((string) $request->userAgent());
        $hash = hash('sha256', $this->deviceId($request));
        $known = $this->db()->table('user_devices')->where(['user_id' => $user->id, 'device_hash' => $hash])->first();
        $hadDevices = $known ? true : $this->db()->table('user_devices')->where('user_id', $user->id)->exists();
        if ($known) {
            $this->db()->table('user_devices')->where('id', $known->id)->update(['last_seen' => $now, 'label' => $label]);
        } else {
            $this->db()->table('user_devices')->insert(['user_id' => $user->id, 'device_hash' => $hash, 'label' => $label, 'first_seen' => $now, 'last_seen' => $now]);
        }
        $newDevice = ! $known && $hadDevices;

        $hist = LoginHistory::create(['user_id' => $user->id, 'identity' => substr((string) $user->username, 0, 100), 'event' => 'signin', 'how' => $how, 'ip_address' => substr((string) $request->ip(), 0, 45),
            'user_agent' => substr((string) $request->userAgent(), 0, 255), 'device' => $label, 'new_device' => $newDevice ? 1 : 0, 'created_at' => $now, 'last_seen' => $now]);
        $request->session()->put(['nd_token' => $token, 'nd_last_activity' => $now, 'nd_hist_id' => $hist->id, 'nd_seen_write' => $now]);

        if ($newDevice && $how === 'password' && empty($user->must_change_password) && filter_var((string) $user->email, FILTER_VALIDATE_EMAIL)) {
            $ip = (string) $request->ip();
            app()->terminating(fn () => $this->newDeviceEmail($user, $label, $ip)); // after the page has gone out, so signing in is not slowed down
        }
    }

    protected function newDeviceEmail(User $user, string $label, string $ip): void
    {
        $core = $this->db()->table('settings')->first();
        $site = trim((string) ($core->sitename ?? '')) ?: 'Nidaam';
        $name = trim((string) $user->first_name) !== '' ? $user->first_name : $user->username;
        $body = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#1d2939;font-size:15px;line-height:1.5"><h2 style="color:#534ab7;font-size:20px">New sign-in to '.e($site).'</h2>'
            .'<p>Hello '.e($name).',</p><p>Your '.e($site).' account (<strong>'.e($user->username).'</strong>) was just signed in on a device it has not used before:</p>'
            .'<p style="background:#f2f4f7;border-radius:8px;padding:12px 16px"><strong>'.e($label).'</strong><br>'.e(date('j M Y, g:i a')).'<br>IP address '.e($ip).'</p>'
            .'<p>If this was you, you can ignore this email.</p><p><strong>Not you?</strong> Tell your administrator straight away so they can sign you out everywhere and reset your password.</p>'
            .'<p style="color:#98a2b3;font-size:12px;margin-top:28px">'.e($site).' · this is an automatic message.</p></div>';
        app(EmailService::class)->sendSystem((string) $user->email, 'New sign-in to '.$site, $body);
    }

    /**
     * Every request of a signed-in user. Returns null when all is well, or ['signout' => reason] when this browser must be signed out.
     */
    public function check(Request $request, User $user, bool $background = false): ?array
    {
        $row = $this->db()->table('users')->where('id', $user->id)->first(['id', 'active', 'session_token']);
        if (! $row || ! (int) $row->active) {
            return ['signout' => 'deactivated'];
        }
        $mine = (string) $request->session()->get('nd_token', '');
        if ($mine === '') { // signed in by "keep me signed in", or a session from before this update: it becomes the one current session
            $this->startSession($user, $request, $request->cookies->has(Auth::guard()->getRecallerName()) ? 'remembered' : 'resumed');

            return null;
        }
        if (! hash_equals((string) $row->session_token, $mine)) {
            $reason = 'other_device';
            if (str_starts_with((string) $row->session_token, 'x:')) {
                $r = substr($row->session_token, 2);
                $reason = isset(self::REASONS[$r]) ? $r : 'admin';
            }

            return ['signout' => $reason];
        }
        $now = time();
        $last = (int) $request->session()->get('nd_last_activity');
        if ($last && $now - $last > self::IDLE_SECONDS + self::IDLE_GRACE) {
            return ['signout' => 'idle'];
        }
        if (! $background) {
            $request->session()->put('nd_last_activity', $now);
            if ($now - (int) $request->session()->get('nd_seen_write') >= 60 && ($hid = (int) $request->session()->get('nd_hist_id'))) { // history "last seen", at most once a minute
                $this->db()->table('login_history')->where('id', $hid)->update(['last_seen' => $now]);
                $request->session()->put('nd_seen_write', $now);
            }
        }

        return null;
    }

    /** Ends this browser's history row. */
    public function endSession(Request $request, string $reason = 'logout'): void
    {
        if (($hid = (int) $request->session()->get('nd_hist_id'))) {
            $this->db()->table('login_history')->where('id', $hid)->whereNull('ended_at')->update(['ended_at' => time(), 'end_reason' => $reason]);
        }
        $request->session()->forget(['nd_token', 'nd_last_activity', 'nd_hist_id', 'nd_seen_write']);
    }

    /** Sign a user out on every device (admin button, password change, deactivate): they are sent to the sign-in page on their next click. */
    public function revokeAll(int $userId, string $reason = 'admin'): void
    {
        $this->db()->table('users')->where('id', $userId)->update(['session_token' => 'x:'.$reason, 'remember_code' => null]);
        $this->db()->table('login_history')->where('user_id', $userId)->where('event', 'signin')->whereNull('ended_at')->update(['ended_at' => time(), 'end_reason' => $reason]);
    }

    /** Login history, newest first. */
    public function history(?int $userId = null, int $limit = 150, string $event = ''): array
    {
        $q = $this->db()->table('login_history as h')->leftJoin('users as u', 'u.id', '=', 'h.user_id')->select('h.*', 'u.first_name', 'u.last_name', 'u.username');
        if ($userId) {
            $q->where('h.user_id', $userId);
        }
        if ($event !== '') {
            $q->where('h.event', $event);
        }

        return $q->orderByDesc('h.id')->limit($limit)->get()->map(fn ($r) => (array) $r)->all();
    }
}
