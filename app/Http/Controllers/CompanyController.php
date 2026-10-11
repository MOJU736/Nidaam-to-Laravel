<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Setting;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;

/** Choose which company (tenant database) to work in. */
class CompanyController extends Controller
{
    public function index(Request $request, TenantManager $tenants)
    {
        $accounts = $request->user()->accessibleAccounts();

        if ($accounts->isEmpty()) {
            return redirect()->route('login')->withErrors(['identity' => 'No companies are assigned to this user.']);
        }

        if ($accounts->count() === 1 && $this->open($tenants, $accounts->first(), $request)) {
            return redirect()->route('dashboard');
        }

        return view('companies.index', ['accounts' => $accounts, 'active' => $request->session()->get('account_id')]);
    }

    public function activate(Request $request, TenantManager $tenants, int $id)
    {
        // Only companies this user is allowed into - never trust the id alone.
        $account = $request->user()->accessibleAccounts()->firstWhere('id', $id);
        abort_unless($account, 403, 'You do not have access to this company.');

        if (! $this->open($tenants, $account, $request)) {
            return redirect()->route('companies.index')->withErrors(['company' => "Could not connect to {$account->label}'s database."]);
        }

        return redirect()->route('dashboard');
    }

    protected function open(TenantManager $tenants, $account, Request $request): bool
    {
        try {
            $tenants->activate($account);
            Setting::query()->first();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        $request->session()->put('account_id', $account->id);

        return true;
    }
}
