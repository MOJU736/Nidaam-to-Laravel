<?php

namespace App\Services\Auth;

use App\Models\Master\LoginHistory;
use App\Models\User;
use App\Services\Documents\EmailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Passwords and sign-in links (legacy Security_model): strong-password rules, the "set your password" link used for invites, admin resets
 * and "forgot password", and the rule that a person given a temporary password must choose their own at the first sign-in.
 * Links hold a random code; only its SHA-256 is stored (users.invite_code) with an expiry, and it works once.
 */
class PasswordService
{
    public const INVITE_HOURS = 48;

    public const RESET_MINUTES = 60;

    public const RESET_PER_IP_HOUR = 5;

    public const TEMP_PASSWORD_DAYS = 7;

    public const MIN_LENGTH = 8;

    protected const COMMON = [
        'password', 'password1', 'password12', 'password123', 'passw0rd', 'p@ssw0rd', 'p@ssword', 'welcome1', 'welcome123', '12345678', '123456789', '1234567890', '0123456789', '87654321', '11111111', '00000000',
        '12341234', '11223344', 'qwerty123', 'qwertyui', 'qwerty12', '1q2w3e4r', '1qaz2wsx', 'abc12345', 'abcd1234', 'a1b2c3d4', 'aa123456', 'admin123', 'admin1234', 'administrator1', 'letmein1', 'iloveyou1',
        'monkey123', 'dragon123', 'football1', 'baseball1', 'sunshine1', 'princess1', 'superman1', 'trustno1', 'master123', 'changeme1', 'test1234x', 'somalia1', 'somalia123', 'mogadishu1', 'mogadishu123',
        'nidaam123', 'nidaam1234', 'accountant1', 'hassan123', 'mohamed123', 'mohamud123', 'ahmed123', 'ali12345', 'abdi1234', 'allah123', 'bismillah1', 'computer1',
    ];

    protected const WEAK_WORDS = ['password', 'passw', 'pass', 'qwerty', 'qwertyuiop', 'asdf', 'abc', 'abcd', 'abcdef', 'admin', 'welcome', 'letmein', 'nidaam', 'iloveyou', 'test', 'user', 'somalia', 'mogadishu',
        'q', 'a', 'aa', 'qwe', 'qaz', 'wsx', 'qazwsx', 'zxcvbn'];

    protected function db()
    {
        return DB::connection('master');
    }

    /** Null when the password is fine, otherwise the reason in plain words. */
    public function problem(string $password, array $avoid = []): ?string
    {
        $p = $password;
        if (strlen($p) < self::MIN_LENGTH) {
            return 'The password must be at least '.self::MIN_LENGTH.' characters long.';
        }
        if (! preg_match('/[A-Za-z]/', $p) || ! preg_match('/[0-9]/', $p)) {
            return 'The password must have both letters and numbers.';
        }
        $low = strtolower($p);
        $word = preg_replace('/[^a-z]/', '', $low);
        $digits = preg_replace('/[^0-9]/', '', $low);
        if (in_array($low, self::COMMON, true) || preg_match('/^(.)\1+$/', $p) || in_array($word, self::WEAK_WORDS, true)
            || (strlen($word) <= 2 && strlen($digits) >= 6 && (str_contains('01234567890123456789', $digits) || str_contains('98765432109876543210', $digits)))) {
            return 'That password is too common and easy to guess. Please choose another one.';
        }
        foreach ($avoid as $w) {
            $w = strtolower(trim((string) $w));
            if (strlen($w) >= 3 && str_contains($low, $w)) {
                return 'The password must not contain your name, username or email.';
            }
        }

        return null;
    }

    /** The words a person's password must not contain. */
    public function avoidWords(object $user): array
    {
        $w = [];
        foreach (['username', 'first_name', 'last_name'] as $f) {
            if (! empty($user->$f)) {
                $w[] = $user->$f;
            }
        }
        if (! empty($user->email)) {
            $w[] = strstr($user->email, '@', true);
        }

        return $w;
    }

    /** Saves a new password (bcrypt). $mustChange: the person must choose their own at the next sign-in; the temporary one then works for 7 days only. */
    public function set(int $userId, string $password, bool $mustChange = false): void
    {
        $this->db()->table('users')->where('id', $userId)->update([
            'password' => Hash::make($password), 'remember_code' => null, 'forgotten_password_code' => null, 'must_change_password' => $mustChange ? 1 : 0,
            'temp_password_expires' => $mustChange ? time() + self::TEMP_PASSWORD_DAYS * 86400 : null, 'invite_code' => null, 'invite_expires' => null, 'password_changed_at' => time(), 'session_token' => 'x:password',
        ]);
    }

    public function tempExpired(object $user): bool
    {
        return (int) $user->must_change_password === 1 && ! empty($user->temp_password_expires) && (int) $user->temp_password_expires < time();
    }

    // ---- links ---------------------------------------------------------------------------------------------------------------

    /** A one-time link code; returns the raw code to put in the link. */
    public function createInvite(int $userId, ?int $seconds = null): string
    {
        $raw = bin2hex(random_bytes(24));
        $this->db()->table('users')->where('id', $userId)->update(['invite_code' => hash('sha256', $raw), 'invite_expires' => time() + ($seconds ?? self::INVITE_HOURS * 3600)]);

        return $raw;
    }

    public function findInvite(?string $raw): ?User
    {
        if (! preg_match('/^[a-f0-9]{48}$/', (string) $raw)) {
            return null;
        }
        $u = User::where('invite_code', hash('sha256', $raw))->where('active', 1)->first();

        return $u && (int) $u->invite_expires >= time() ? $u : null;
    }

    public function randomPassword(): string
    {
        return 'Nd'.bin2hex(random_bytes(12)).'7';
    }

    protected function site(): string
    {
        return trim((string) ($this->db()->table('settings')->value('sitename') ?? '')) ?: 'Nidaam';
    }

    protected function frame(string $title, string $inner): string
    {
        return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#1d2939;font-size:15px;line-height:1.5"><h2 style="color:#534ab7;font-size:20px">'.e($title).'</h2>'.$inner
            .'<p style="color:#98a2b3;font-size:12px;margin-top:28px">'.e($this->site()).' · this is an automatic message.</p></div>';
    }

    protected function button(string $link, string $label): string
    {
        return '<p style="margin:24px 0"><a href="'.e($link).'" style="background:#534ab7;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block">'.e($label).'</a></p>';
    }

    /** Emails an invite (new user) or a "set a new password" link (admin reset). Returns true or an error text. */
    public function sendInvite(User $user, bool $isReset = false): bool|string
    {
        if (! filter_var((string) $user->email, FILTER_VALIDATE_EMAIL)) {
            return 'This user has no valid email address.';
        }
        $link = route('password.set', $this->createInvite($user->id));
        $site = $this->site();
        $subject = $isReset ? "Set a new password for $site" : "You are invited to $site";
        $intro = $isReset ? "An administrator asked for your $site password to be reset. Choose a new password with the button below."
            : "An account was created for you in $site. Choose your own password with the button below, then sign in.";
        $body = $this->frame($subject, '<p>Hello '.e(trim((string) $user->first_name) !== '' ? $user->first_name : $user->username).",</p><p>$intro</p>".$this->button($link, 'Set my password')
            .'<p>Your username: <strong>'.e($user->username).'</strong></p><p style="color:#667085;font-size:13px">This link works once and expires in '.self::INVITE_HOURS.' hours. If the button does not work, copy this address into your browser:<br>'.e($link).'</p>');

        return app(EmailService::class)->sendSystem($user->email, $subject, $body);
    }

    /**
     * "Forgot password": the person types their username or email. When it belongs to an active user with an email address, a one-time link is
     * emailed (it works for 60 minutes). The caller always answers the same way, so it never says whether an account exists. At most 5 requests an
     * hour from one IP address, and one email per user every 2 minutes.
     */
    public function requestReset(string $identity, ?string $ip): void
    {
        $identity = trim($identity);
        if ($identity === '' || strlen($identity) > 190) {
            return;
        }
        $ip = substr((string) $ip, 0, 45);
        if (LoginHistory::where('event', 'reset_request')->where('ip_address', $ip)->where('created_at', '>', time() - 3600)->count() >= self::RESET_PER_IP_HOUR) {
            return;
        }
        $users = User::where('active', 1)->where(fn ($q) => $q->whereRaw('LOWER(username) = ?', [strtolower($identity)])->orWhereRaw('LOWER(email) = ?', [strtolower($identity)]))->limit(3)->get();
        if ($users->isEmpty()) {
            $this->record($identity, 'reset_request', null, $ip);

            return;
        }
        foreach ($users as $u) {
            $this->record($u->username, 'reset_request', $u->id, $ip);
            if (LoginHistory::where('event', 'reset_request')->where('user_id', $u->id)->where('created_at', '>', time() - 120)->count() > 1 || ! filter_var((string) $u->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $link = route('password.set', $this->createInvite($u->id, self::RESET_MINUTES * 60));
            $site = $this->site();
            $subject = "Reset your $site password";
            $body = $this->frame($subject, '<p>Hello '.e(trim((string) $u->first_name) !== '' ? $u->first_name : $u->username).',</p><p>Someone asked to reset the password of your '.e($site).' account. If it was you, choose a new password with the button below.</p>'
                .$this->button($link, 'Choose a new password').'<p>Your username: <strong>'.e($u->username).'</strong></p><p style="color:#667085;font-size:13px">This link works once and expires in '.self::RESET_MINUTES.' minutes. If the button does not work, copy this address into your browser:<br>'.e($link)
                .'</p><p><strong>Did not ask for this?</strong> You can ignore this email: your password stays the same.</p>');
            app(EmailService::class)->sendSystem($u->email, $subject, $body);
        }
    }

    public function record(string $identity, string $event, ?int $userId = null, ?string $ip = null): void
    {
        LoginHistory::create(['user_id' => $userId, 'identity' => substr($identity, 0, 100), 'event' => $event, 'ip_address' => substr((string) ($ip ?? request()->ip()), 0, 45), 'user_agent' => substr((string) request()->userAgent(), 0, 255), 'created_at' => time()]);
    }
}
