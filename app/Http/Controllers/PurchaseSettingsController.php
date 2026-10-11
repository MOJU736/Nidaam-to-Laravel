<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Group;
use App\Models\Tenant\Ledger;
use App\Services\Accounting\ActivityLog;
use App\Services\Purchases\PurchasesService;
use Illuminate\Http\Request;

/** Purchase Settings: the Accounts Payable group, tax and discount accounts, and bill numbering. Administrators only. */
class PurchaseSettingsController extends Controller
{
    public function __construct(protected PurchasesService $purchases) {}

    public function edit(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('purchases.settings', ['s' => $this->purchases->settings(), 'groups' => Group::orderBy('code')->get(['id', 'name', 'code']), 'ledgers' => Ledger::orderBy('code')->get(['id', 'name', 'code'])]);
    }

    public function update(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate(['ap_group_id' => ['nullable', 'integer'], 'tax_ledger_id' => ['nullable', 'integer'], 'discount_ledger_id' => ['nullable', 'integer'], 'bill_prefix' => ['nullable', 'string', 'max:20']]);
        foreach (['ap_group_id', 'tax_ledger_id', 'discount_ledger_id'] as $k) {
            $d[$k] = ($d[$k] ?? null) ?: null;
        }
        $d['bill_prefix'] = $d['bill_prefix'] ?: 'BILL-';
        $this->purchases->saveSettings($d);
        ActivityLog::add('Updated Purchase Settings');

        return redirect()->route('purchases.settings')->with('message', 'Purchase settings saved.');
    }
}
