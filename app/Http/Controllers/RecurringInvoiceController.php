<?php

namespace App\Http\Controllers;

use App\Services\Sales\RecurringService;
use Illuminate\Http\Request;

/** Invoices that repeat on a schedule. They are created as drafts: you check them and issue them by hand. */
class RecurringInvoiceController extends Controller
{
    public function __construct(protected RecurringService $recurring)
    {
        $this->recurring->ensureSchema();
    }

    public function index()
    {
        $made = $this->recurring->runDue();
        if ($made > 0) {
            session()->now('message', $made.' recurring invoice'.($made === 1 ? ' was' : 's were').' created as draft'.($made === 1 ? '' : 's').' - check and issue '.($made === 1 ? 'it' : 'them').' under Invoices.');
        }

        return view('sales.recurring-index', ['rows' => $this->recurring->list(), 'recurring' => $this->recurring]);
    }

    /** "Make recurring" on an invoice. */
    public function create(Request $request)
    {
        $pre = $this->recurring->prefillFromInvoice((int) $request->query('invoice'));
        if (! $pre) {
            return redirect()->route('invoices.index')->with('error', 'Open the invoice you want to repeat and click Make recurring.');
        }

        return view('sales.recurring-form', ['tpl' => $pre, 'sourceInvoice' => (int) $request->query('invoice'), 'frequencies' => RecurringService::FREQUENCIES]);
    }

    public function edit(int $id)
    {
        $tpl = $this->recurring->get($id);
        abort_unless($tpl, 404);
        $tpl->start_date = $tpl->next_date ?: $tpl->start_date;

        return view('sales.recurring-form', ['tpl' => $tpl, 'sourceInvoice' => 0, 'frequencies' => RecurringService::FREQUENCIES]);
    }

    protected function input(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'customer_id' => ['required', 'integer'], 'frequency' => ['required', 'string'], 'every' => ['required', 'integer', 'min:1', 'max:99'],
            'start_date' => ['required', 'date'], 'end_date' => ['nullable', 'date'], 'due_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'notes' => ['nullable', 'string', 'max:500'],
            'tax_percent' => ['nullable', 'string', 'max:10'], 'tax_total' => ['nullable', 'string', 'max:20'], 'doc_discount_type' => ['nullable', 'string', 'max:10'],
            'doc_discount_value' => ['nullable', 'string', 'max:30'], 'status' => ['nullable', 'string'], 'lines' => ['nullable', 'array'],
        ]);
        $d['discount_type'] = $d['doc_discount_type'] ?? null;
        $d['discount_value'] = $d['doc_discount_value'] ?? null;

        return $d;
    }

    public function store(Request $request)
    {
        $d = $this->input($request);
        $this->recurring->save($d, (array) ($d['lines'] ?? []));
        $made = $this->recurring->runDue();

        return redirect()->route('recurring.index')->with('message', 'Recurring invoice saved.'.($made ? " $made draft invoice".($made === 1 ? ' was' : 's were').' created because the first date is today or earlier.' : ''));
    }

    public function update(Request $request, int $id)
    {
        $d = $this->input($request);
        $this->recurring->save($d, (array) ($d['lines'] ?? []), $id);
        $made = $this->recurring->runDue();

        return redirect()->route('recurring.index')->with('message', 'Recurring invoice updated.'.($made ? " $made draft invoice".($made === 1 ? ' was' : 's were').' created.' : ''));
    }

    public function toggle(Request $request, int $id)
    {
        $this->recurring->setStatus($id, $request->input('status') === 'active' ? 'active' : 'paused');

        return redirect()->route('recurring.index')->with('message', 'Updated.');
    }

    public function destroy(int $id)
    {
        $this->recurring->delete($id);

        return redirect()->route('recurring.index')->with('message', 'Recurring invoice deleted. Invoices it already created are kept.');
    }

    /** "Create now": the next invoice straight away, dated today. */
    public function runNow(int $id)
    {
        $made = $this->recurring->runOne($id, now()->toDateString(), true);

        return redirect()->route('invoices.index')->with($made ? 'message' : 'error', $made ? 'Draft invoice created - check it and click Issue.' : 'Could not create an invoice from it.');
    }
}
