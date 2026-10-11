<?php

namespace App\Http\Controllers;

use App\Models\Master\Account;
use App\Models\User;
use App\Services\Accounting\ActivityLog;
use App\Services\Auth\PasswordService;
use App\Services\Auth\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Admin → Users: who can sign in, which group they are in, and which companies they may open. Administrators only (checked by the page middleware). */
class AdminUsersController extends Controller
{
    protected function master()
    {
        return DB::connection('master');
    }

    public function index()
    {
        $users = User::orderBy('username')->get();
        $groups = $this->master()->table('users_groups as ug')->join('groups as g', 'g.id', '=', 'ug.group_id')->pluck('g.name', 'ug.user_id');
        $accounts = Account::orderBy('id')->pluck('label', 'id');

        return view('admin.users', ['users' => $users, 'groupNames' => $groups, 'accounts' => $accounts]);
    }

    protected function form(?User $user)
    {
        return view('admin.user-form', ['u' => $user, 'groups' => $this->master()->table('groups')->orderBy('id')->get(), 'accounts' => Account::orderBy('id')->get(['id', 'label']),
            'groupId' => $user ? app(Permissions::class)->groupId($user) : null, 'mine' => $user ? (array) json_decode((string) $user->accounts, true) : []]);
    }

    public function create()
    {
        return $this->form(null);
    }

    public function edit(int $id)
    {
        return $this->form(User::findOrFail($id));
    }

    protected function rules(?User $user): array
    {
        return [
            'username' => ['required', 'string', 'max:25', 'regex:/^[A-Za-z0-9_.\-]+$/', Rule::unique('master.users', 'username')->ignore($user?->id)],
            'email' => ['required', 'email', 'max:30', Rule::unique('master.users', 'email')->ignore($user?->id)],
            'first_name' => ['required', 'string', 'max:11'], 'last_name' => ['nullable', 'string', 'max:11'], 'company' => ['nullable', 'string', 'max:25'],
            'phone' => ['nullable', 'regex:/^\d{5,15}$/'], 'group_id' => ['required', 'integer', 'exists:master.groups,id'],
            'password' => [$user || request()->boolean('send_invite') ? 'nullable' : 'required', 'string', 'min:8', 'max:72'], 'accounts' => ['nullable', 'array'],
        ];
    }

    protected function accountsJson(Request $request): array
    {
        if ($request->boolean('all_accounts')) {
            return [1, '["all"]'];
        }
        $ids = array_values(array_filter(array_map('strval', (array) $request->input('accounts')), fn ($v) => ctype_digit($v) && Account::whereKey((int) $v)->exists()));
        if (! $ids) {
            throw ValidationException::withMessages(['accounts' => 'Choose at least one company this person may open, or tick "All companies".']);
        }

        return [0, json_encode($ids)];
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->rules(null));
        [$all, $accounts] = $this->accountsJson($request);
        $invite = $request->boolean('send_invite');
        if (! $invite && ($problem = app(PasswordService::class)->problem((string) $d['password'], [$d['username'], $d['first_name'], strstr($d['email'], '@', true)]))) {
            throw ValidationException::withMessages(['password' => $problem]);
        }
        $d['password'] = $invite ? app(PasswordService::class)->randomPassword() : $d['password'];
        $id = $this->master()->transaction(function () use ($d, $request, $all, $accounts) {
            $id = $this->master()->table('users')->insertGetId(['ip_address' => '127.0.0.1', 'username' => $d['username'], 'password' => Hash::make($d['password']), 'salt' => '', 'email' => $d['email'],
                'created_on' => time(), 'active' => $request->boolean('active', true) ? 1 : 0, 'first_name' => $d['first_name'], 'last_name' => $d['last_name'] ?? null, 'company' => $d['company'] ?? null,
                'phone' => $d['phone'] ?? null, 'accounts' => $accounts, 'all_accounts' => $all, 'must_change_password' => $request->boolean('must_change_password') ? 1 : 0, 'temp_password_expires' => $request->boolean('must_change_password') ? time() + PasswordService::TEMP_PASSWORD_DAYS * 86400 : null, 'password_changed_at' => time()]);
            $this->master()->table('users_groups')->insert(['user_id' => $id, 'group_id' => $d['group_id']]);

            return $id;
        });
        ActivityLog::add("Created user {$d['username']}");
        if ($invite) {
            $sent = app(PasswordService::class)->sendInvite(User::findOrFail($id));

            return redirect()->route('admin.users')->with($sent === true ? 'message' : 'error', $sent === true ? "User {$d['username']} was created and an invite link was emailed to {$d['email']}." : "User {$d['username']} was created, but the invite was not sent: $sent Open the user and press Send password link when email works.");
        }

        return redirect()->route('admin.users')->with('message', "User {$d['username']} was created.");
    }

    public function update(Request $request, int $id)
    {
        $user = User::findOrFail($id);
        $d = $request->validate($this->rules($user));
        [$all, $accounts] = $this->accountsJson($request);
        $groupId = (int) $d['group_id'];
        // never lock everyone out: the last active administrator keeps their group and stays active
        $adminGroup = (int) $this->master()->table('groups')->where('name', 'admin')->value('id');
        if ($user->isAdmin() && ($groupId !== $adminGroup || ! $request->boolean('active', true)) && $this->activeAdmins($user->id) === 0) {
            throw ValidationException::withMessages(['group_id' => 'This is the only active administrator, so it cannot be moved to another group or switched off.']);
        }
        $row = ['username' => $d['username'], 'email' => $d['email'], 'first_name' => $d['first_name'], 'last_name' => $d['last_name'] ?? null, 'company' => $d['company'] ?? null,
            'phone' => $d['phone'] ?? null, 'accounts' => $accounts, 'all_accounts' => $all, 'active' => $request->boolean('active') ? 1 : 0, 'must_change_password' => $request->boolean('must_change_password') ? 1 : 0];
        if (($d['password'] ?? '') !== '') {
            if ($problem = app(PasswordService::class)->problem((string) $d['password'], [$d['username'], $d['first_name'], strstr($d['email'], '@', true)])) {
                throw ValidationException::withMessages(['password' => $problem]);
            }
            $row += ['password' => Hash::make($d['password']), 'password_changed_at' => time(), 'session_token' => 'x:password', 'temp_password_expires' => $request->boolean('must_change_password') ? time() + PasswordService::TEMP_PASSWORD_DAYS * 86400 : null]; // a new password signs the person out everywhere
        }
        $this->master()->transaction(function () use ($id, $row, $groupId) {
            $this->master()->table('users')->where('id', $id)->update($row);
            $this->master()->table('users_groups')->where('user_id', $id)->delete();
            $this->master()->table('users_groups')->insert(['user_id' => $id, 'group_id' => $groupId]);
        });
        ActivityLog::add("Updated user {$d['username']}");

        return redirect()->route('admin.users')->with('message', "User {$d['username']} was updated.");
    }

    /** Emails the person a one-time link to choose a new password (and signs them out everywhere). */
    public function resetLink(int $id)
    {
        $user = User::findOrFail($id);
        $sent = app(PasswordService::class)->sendInvite($user, true);
        if ($sent !== true) {
            return back()->with('error', "The link was not sent: $sent");
        }
        $this->master()->table('users')->where('id', $id)->update(['session_token' => 'x:password']);
        ActivityLog::add("Sent a password link to user {$user->username}");

        return back()->with('message', "A link to choose a new password was emailed to {$user->email}. It works once for 48 hours.");
    }

    /** Active administrators other than $exceptId. */
    protected function activeAdmins(int $exceptId): int
    {
        return $this->master()->table('users as u')->join('users_groups as ug', 'ug.user_id', '=', 'u.id')->join('groups as g', 'g.id', '=', 'ug.group_id')
            ->where('g.name', 'admin')->where('u.active', 1)->where('u.id', '!=', $exceptId)->count();
    }

    public function destroy(Request $request, int $id)
    {
        $user = User::findOrFail($id);
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete the account you are signed in with.');
        }
        if ($user->isAdmin() && $this->activeAdmins($user->id) === 0) {
            return back()->with('error', 'This is the only active administrator, so it cannot be deleted.');
        }
        $this->master()->transaction(function () use ($id) {
            $this->master()->table('users_groups')->where('user_id', $id)->delete();
            $this->master()->table('users')->where('id', $id)->delete();
        });
        ActivityLog::add("Deleted user {$user->username}");

        return redirect()->route('admin.users')->with('message', "User {$user->username} was deleted.");
    }
}
