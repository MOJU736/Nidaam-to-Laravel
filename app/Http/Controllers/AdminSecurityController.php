<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Accounting\ActivityLog;
use App\Services\Auth\SecurityService;
use Illuminate\Http\Request;

/** Admin → Login history, and "Sign out everywhere" for one user. Administrators only (checked by the page middleware). */
class AdminSecurityController extends Controller
{
    public function __construct(protected SecurityService $security) {}

    public function history(Request $request)
    {
        $userId = (int) $request->query('user') ?: null;
        $event = in_array($request->query('event'), ['signin', 'logout', 'failed', 'locked', 'reset_request', 'reset_done'], true) ? (string) $request->query('event') : '';

        return view('admin.login-history', ['rows' => $this->security->history($userId, 200, $event), 'user' => $userId ? User::find($userId) : null, 'event' => $event,
            'reasons' => SecurityService::REASONS, 'users' => User::orderBy('username')->get(['id', 'username'])]);
    }

    public function signOut(int $id)
    {
        $user = User::findOrFail($id);
        $this->security->revokeAll($user->id, 'admin');
        ActivityLog::add("Signed user {$user->username} out on every device");

        return back()->with('message', "{$user->username} was signed out on every device. They must sign in again.");
    }
}
