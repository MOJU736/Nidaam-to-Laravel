<?php

namespace App\Models;

use App\Models\Master\Account;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** A person who can sign in. Lives in the master database (legacy Ion Auth `users` table). */
class User extends Authenticatable
{
    protected $connection = 'master';
    protected $table = 'users';
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['password', 'salt', 'session_token', 'invite_code', 'remember_code', 'forgotten_password_code'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'all_accounts' => 'boolean', 'must_change_password' => 'boolean'];
    }

    /** "Keep me signed in" uses the legacy remember_code column (widened to 100 characters by SecurityService::ensureSchema). */
    public function getRememberTokenName()
    {
        return 'remember_code';
    }

    public function groups()
    {
        return $this->belongsToMany(Master\Group::class, 'users_groups', 'user_id', 'group_id');
    }

    public function isAdmin(): bool
    {
        return $this->groups()->where('name', 'admin')->exists();
    }

    public function displayName(): string
    {
        return trim("{$this->first_name} {$this->last_name}") ?: $this->username;
    }

    /** Companies this user may open: all of them, or the ids listed in the `accounts` JSON column. */
    public function accessibleAccounts(): Collection
    {
        if ($this->all_accounts) {
            return Account::orderBy('id')->get();
        }

        $ids = json_decode((string) $this->accounts, true);
        if (! is_array($ids) || in_array('all', $ids, true)) {
            return in_array('all', (array) $ids, true) ? Account::orderBy('id')->get() : new Collection;
        }

        return Account::whereIn('id', array_map('intval', $ids))->orderBy('id')->get();
    }
}
