<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Ledger;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\LedgerBalances;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** New / edit / delete one account (ledger). The list lives on the Chart of Accounts page. */
class LedgerController extends Controller
{
    public function __construct(protected ChartOfAccounts $chart) {}

    public function create(Request $request)
    {
        return view('chart.ledger-form', $this->formData(null, (int) $request->query('group')));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $ledger = $this->chart->saveAccount(null, $data);
        ActivityLog::add("Added account: {$ledger->name}");

        $to = $request->has('save_and_new') ? route('ledgers.create', ['group' => $ledger->group_id]) : route('chart', ['show' => $ledger->id]);

        return redirect($to)->with('message', "Account \"{$ledger->name}\" was created.");
    }

    public function edit(int $id)
    {
        return view('chart.ledger-form', $this->formData(Ledger::findOrFail($id), 0));
    }

    public function update(Request $request, int $id)
    {
        $ledger = Ledger::findOrFail($id);
        abort_if($this->chart->companyLocked(), 403, 'This company account is locked.');

        $data = $this->validated($request, $ledger);
        $oldBalance = [$ledger->op_balance, $ledger->op_balance_dc];
        $ledger = $this->chart->saveAccount($ledger, $data);
        ActivityLog::add("Updated account: {$ledger->name}");

        $redirect = redirect()->route('chart', ['show' => $ledger->id])->with('message', "Account \"{$ledger->name}\" was updated.");
        $changed = Money::cmp($data['op_balance'] ?: 0, $oldBalance[0]) !== 0 || ($data['op_balance_dc'] !== $oldBalance[1] && Money::positive($data['op_balance'] ?: 0));

        return ($this->chart->booksClosed() && $changed)
            ? $redirect->with('warning', 'Saved. The opening balance was not changed, because the books are closed.') : $redirect;
    }

    public function destroy(int $id)
    {
        $ledger = Ledger::findOrFail($id);
        $this->chart->deleteAccount($ledger);
        ActivityLog::add("Deleted account: {$ledger->name}");

        return redirect()->route('chart')->with('message', "Account \"{$ledger->name}\" was deleted.");
    }

    protected function validated(Request $request, ?Ledger $ledger): array
    {
        $places = (int) (\App\Models\Tenant\Setting::first()->decimal_places ?? 2);
        $request->merge(['op_balance' => str_replace([',', ' '], '', (string) $request->input('op_balance'))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:255', function ($attr, $value, $fail) use ($ledger) {
                // no two accounts with the same name (ignoring case and spaces at the ends)
                $dupe = Ledger::whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($value))])->when($ledger, fn ($q) => $q->where('id', '!=', $ledger->id))->exists();
                $dupe && $fail('There is already an account called "'.trim($value).'". Use a different name.');
            }],
            'group_id' => ['required', 'integer', Rule::exists('tenant.groups', 'id')],
            'op_balance_dc' => ['required', Rule::in(['D', 'C'])],
            'op_balance' => ['nullable', 'numeric', 'min:0', "regex:/^\\d+(\\.\\d{1,$places})?$/"],
            'notes' => ['nullable', 'string', 'max:500'],
            'type' => ['nullable', 'boolean'], 'reconciliation' => ['nullable', 'boolean'], 'is_contra' => ['nullable', 'boolean'],
        ], ['op_balance.regex' => "The opening balance can have at most $places decimal places."]);
    }

    protected function formData(?Ledger $ledger, int $pick): array
    {
        $groups = $this->chart->groupOptions();
        $data = [
            'ledger' => $ledger, 'groups' => $groups, 'picked' => $ledger ? (int) $ledger->group_id : (isset($groups[$pick]) ? $pick : 0),
            'booksClosed' => $this->chart->booksClosed(), 'lines' => 0, 'closing' => null, 'party' => null, 'normal' => 'D',
        ];

        if ($ledger) {
            $db = DB::connection('tenant');
            $data['lines'] = $db->table('entryitems')->where('ledger_id', $ledger->id)->count();
            $data['closing'] = app(LedgerBalances::class)->closingBalance($ledger->id);
            $data['party'] = $db->table('customers')->where('ledger_id', $ledger->id)->exists() ? 'customer'
                : ($db->table('vendors')->where('ledger_id', $ledger->id)->exists() ? 'vendor' : null);
            $normal = ChartOfAccounts::normalSide($groups[$ledger->group_id]['type'] ?? 'Asset');
            $data['normal'] = $ledger->is_contra ? ($normal === 'D' ? 'C' : 'D') : $normal;
        }

        return $data;
    }
}
