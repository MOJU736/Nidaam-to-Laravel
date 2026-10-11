<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\PasswordService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Forgot password, the "set your password" link (invite / admin reset), the first-sign-in password, and changing your own password. */
class PasswordController extends Controller
{
    public function __construct(protected PasswordService $passwords) {}

    // ---- forgot password -------------------------------------------------------------------------------------------------

    public function forgot()
    {
        return view('auth.forgot', ['sent' => false]);
    }

    /** The page answers the same way whether or not the account exists. */
    public function sendLink(Request $request)
    {
        $d = $request->validate(['identity' => ['required', 'string', 'max:190']], ['identity.required' => 'Type your username or email address.']);
        $this->passwords->requestReset($d['identity'], $request->ip());

        return view('auth.forgot', ['sent' => true]);
    }

    // ---- the link in an invite / reset email -----------------------------------------------------------------------------

    public function setForm(Request $request, string $code)
    {
        if (Auth::check()) { // the link is for someone else's sign-in: sign this browser out first
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('password.set', $code);
        }
        $user = $this->passwords->findInvite($code);

        return view('auth.set-password', ['mode' => 'invite', 'person' => $user, 'bad' => ! $user, 'action' => route('password.set.save', $code)]);
    }

    public function setSave(Request $request, string $code)
    {
        $user = $this->passwords->findInvite($code);
        if (! $user) {
            return view('auth.set-password', ['mode' => 'invite', 'person' => null, 'bad' => true, 'action' => '']);
        }
        $this->validateNew($request, $user);
        $this->passwords->set($user->id, $request->input('new'));
        $this->passwords->record($user->username, 'reset_done', $user->id); // also lifts a lock from wrong passwords

        return redirect()->route('login')->with('message', 'Your password is set. Sign in with your username and new password.')->with('identity_hint', $user->username);
    }

    // ---- first sign-in with a temporary password ---------------------------------------------------------------------------

    public function firstForm(Request $request)
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route('companies.index');
        }

        return view('auth.set-password', ['mode' => 'first', 'person' => $request->user(), 'bad' => false, 'action' => route('password.first.save')]);
    }

    public function firstSave(Request $request)
    {
        $user = $request->user();
        $this->validateNew($request, $user);
        if (Hash::check((string) $request->input('new'), $user->password)) {
            throw ValidationException::withMessages(['new' => 'Please choose a new password - not the one you were given.']);
        }
        $this->passwords->set($user->id, $request->input('new'));

        return redirect()->route('companies.index')->with('message', 'Your new password is saved. Welcome!');
    }

    // ---- change your own password ----------------------------------------------------------------------------------------------

    public function changeForm()
    {
        return view('auth.change-password');
    }

    public function changeSave(Request $request)
    {
        $user = $request->user();
        $request->validate(['old' => ['required', 'string']]);
        if (! Hash::check((string) $request->input('old'), $user->password)) {
            throw ValidationException::withMessages(['old' => 'The current password is not right.']);
        }
        $this->validateNew($request, $user);
        $this->passwords->set($user->id, $request->input('new'));
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('message', 'Your password was changed. Sign in with the new password.')->with('identity_hint', $user->username);
    }

    protected function validateNew(Request $request, object $user): void
    {
        $request->validate(['new' => ['required', 'string', 'max:72'], 'new_confirm' => ['required', 'same:new']], ['new_confirm.same' => 'The two passwords do not match.', 'new_confirm.required' => 'Type the new password twice.']);
        if ($problem = $this->passwords->problem((string) $request->input('new'), $this->passwords->avoidWords($user))) {
            throw ValidationException::withMessages(['new' => $problem]);
        }
    }
}
