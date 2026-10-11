<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Item;
use App\Services\Accounting\EntryService;
use App\Services\Purchases\PurchasesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Quick expenses: one step, no vendor. Dr an expense or asset account, Cr the bank/cash account it was paid from, posted at once.
 * Stock items are not offered - buying stock goes through a bill so quantity and average cost update.
 */
class ExpenseController extends Controller
{
    use Concerns\HandlesPurchaseDocuments;

    public function __construct(protected PurchasesService $purchases, protected EntryService $entries) {}

    public function index()
    {
        $rows = DB::connection('tenant')->table('expenses')->join('ledgers as el', 'el.id', '=', 'expenses.expense_ledger_id')->join('ledgers as bl', 'bl.id', '=', 'expenses.ledger_id')
            ->leftJoin('items as it', 'it.id', '=', 'expenses.item_id')->orderByDesc('expenses.date')->orderByDesc('expenses.id')
            ->get(['expenses.*', 'el.name as expense_ledger_name', 'bl.name as bank_ledger_name', 'it.name as item_name']);

        return view('purchases.expenses', ['rows' => $rows, 'purchases' => $this->purchases]);
    }

    public function create(Request $request)
    {
        return view('purchases.expense-form', [
            'accounts' => $this->accountChoices(), 'banks' => $this->entries->accountOptions(4), 'methods' => DB::connection('tenant')->table('payment_methods')->orderBy('name')->pluck('name')->all(),
            'items' => Item::where('status', 1)->where('type', '!=', 'inventory')->whereNotNull('expense_ledger_id')->orderBy('name')->get(['id', 'name']), 'next' => $this->purchases->expenseLabel($this->purchases->nextNumber('expenses')),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'expense_type' => ['required', 'in:account,item'], 'ledger_id' => ['required', 'integer'], 'date' => ['required', 'date'], 'amount' => ['required', 'string'],
            'expense_ledger_id' => ['nullable', 'integer'], 'item_id' => ['nullable', 'integer'], 'method' => ['nullable', 'string', 'max:50'], 'reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $itemId = null;
        if ($d['expense_type'] === 'item') {
            $item = Item::find($d['item_id'] ?? 0);
            if (! $item || $item->type === 'inventory') {
                return back()->withInput()->withErrors(['purchases' => 'Select a valid, non-inventory item. Inventory items are purchased through a Bill instead, so their stock quantity updates correctly.']);
            }
            if (! $item->expense_ledger_id) {
                return back()->withInput()->withErrors(['purchases' => "\"{$item->name}\" has no Expense account configured. Set one under Items, or record this expense by account instead."]);
            }
            $itemId = $item->id;
            $d['expense_ledger_id'] = $item->expense_ledger_id;
        } elseif (empty($d['expense_ledger_id'])) {
            return back()->withInput()->withErrors(['purchases' => 'Choose the expense or asset account.']);
        }
        $d['item_id'] = $itemId;
        $id = $this->purchases->recordExpense($d);

        return redirect($request->has('save_and_new') ? route('expenses.create') : route('expenses.show', $id))->with('message', 'Expense recorded and posted to the books.');
    }

    public function show(int $id)
    {
        $e = $this->purchases->expense($id);
        abort_unless($e, 404);

        return view('purchases.expense-show', ['e' => $e, 'purchases' => $this->purchases]);
    }

    public function void(int $id)
    {
        $this->purchases->voidExpense($id);

        return redirect()->route('expenses.show', $id)->with('message', 'Expense voided and its journal entry reversed.');
    }

    public function destroy(int $id)
    {
        $this->purchases->deleteExpense($id);

        return redirect()->route('expenses.index')->with('message', 'Expense deleted.');
    }
}
