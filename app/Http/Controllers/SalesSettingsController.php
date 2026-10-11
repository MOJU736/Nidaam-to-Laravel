<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Group;
use App\Models\Tenant\Ledger;
use App\Services\Accounting\ActivityLog;
use App\Services\Sales\SalesService;
use App\Support\Tables;
use Illuminate\Http\Request;

/** Sales Settings: the accounts invoices post to, and the numbering prefixes. Administrators only. */
class SalesSettingsController extends Controller
{
    public function __construct(protected SalesService $sales) {}

    public function edit(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('sales.settings', [
            's' => $this->sales->settings(), 'groups' => Group::orderBy('code')->get(['id', 'name', 'code']),
            'ledgers' => Ledger::orderBy('code')->get(['id', 'name', 'code']), 'hasBadDebt' => Tables::has('sales_settings', 'bad_debt_ledger_id'),
        ]);
    }

    public function update(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate([
            'ar_group_id' => ['nullable', 'integer'], 'sales_ledger_id' => ['nullable', 'integer'], 'tax_ledger_id' => ['nullable', 'integer'], 'discount_ledger_id' => ['nullable', 'integer'],
            'bad_debt_ledger_id' => ['nullable', 'integer'], 'bank_name' => ['nullable', 'string', 'max:255'], 'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_iban' => ['nullable', 'string', 'max:100'], 'bank_account_number' => ['nullable', 'string', 'max:100'],
            'quotation_prefix' => ['nullable', 'string', 'max:20'], 'invoice_prefix' => ['nullable', 'string', 'max:20'],
        ]);
        foreach (['ar_group_id', 'sales_ledger_id', 'tax_ledger_id', 'discount_ledger_id', 'bad_debt_ledger_id'] as $k) {
            $d[$k] = $d[$k] ?? null ?: null;
        }
        $d['quotation_prefix'] = $d['quotation_prefix'] ?: 'QTN-';
        $d['invoice_prefix'] = $d['invoice_prefix'] ?: 'INV-';
        $this->sales->saveSettings($d);
        ActivityLog::add('Updated Sales Settings');

        return redirect()->route('sales.settings')->with('message', 'Sales settings saved.');
    }
}
