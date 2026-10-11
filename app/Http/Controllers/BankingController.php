<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Ledger;
use App\Services\Banking\BankRecService;
use App\Services\Reports\ReportService;
use App\Support\Money;
use Illuminate\Http\Request;

/** Banking → Reconcile: tick the lines on a bank statement until the difference is zero, then finish. Rules live in BankRecService. */
class BankingController extends Controller
{
    public function __construct(protected BankRecService $bank, protected ReportService $reports)
    {
        $this->bank->ensureTables();
    }

    /** "1,234.50" / "(1,234.50)" / "-1234.5" -> "1234.50", or null. */
    protected function amount(?string $raw): ?string
    {
        $v = trim((string) $raw);
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $v, $m)) {
            $neg = true;
            $v = $m[1];
        }
        $v = str_replace([',', ' '], '', $v);
        $v = preg_replace('/^[^\d\-]+/', '', $v); // a currency symbol
        if ($v === '' || ! preg_match('/^-?\d+(\.\d{1,2})?$/', $v)) {
            return null;
        }
        $v = number_format((float) $v, 2, '.', '');

        return $neg ? '-'.ltrim($v, '-') : $v;
    }

    protected function ledgerOrFail(int $id): Ledger
    {
        return $this->bank->account($id) ?? abort(redirect()->route('banking.index')->with('error', 'That account is not a bank or cash account, or it no longer exists.'));
    }

    protected function recOrFail(int $id): object
    {
        return $this->bank->get($id) ?? abort(redirect()->route('banking.index')->with('error', 'That reconciliation no longer exists.'));
    }

    public function index()
    {
        $cards = array_map(fn ($l) => ['ledger' => $l, 'book' => $this->bank->bookBalance($l), 'last' => $this->bank->lastDone($l->id), 'draft' => $this->bank->draftFor($l->id)], $this->bank->accounts());

        return view('banking.index', ['cards' => $cards]);
    }

    /** Step 1: the statement's ending date and balance. */
    public function reconcile(Request $request, int $id)
    {
        $l = $this->ledgerOrFail($id);
        $draft = $this->bank->draftFor($l->id);
        $last = $this->bank->lastDone($l->id);

        if ($request->isMethod('post')) {
            $date = (string) $request->input('statement_date');
            $bal = $this->amount($request->input('statement_balance'));
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || date('Y-m-d', strtotime($date)) !== $date) {
                return back()->withInput()->with('error', "Enter the statement's ending date.");
            }
            if ($bal === null) {
                return back()->withInput()->with('error', "Enter the statement's ending balance, e.g. 12,500.00.");
            }

            return redirect()->route('banking.rec', $this->bank->start($l, $date, $bal));
        }
        if ($draft && ! $request->query('edit')) {
            return redirect()->route('banking.rec', $draft->id);
        }

        return view('banking.start', ['ledger' => $l, 'draft' => $draft, 'last' => $last, 'begin' => $this->bank->clearedBalance($l),
            'date' => old('statement_date', $draft ? substr($draft->statement_date, 0, 10) : date('Y-m-t', strtotime('last day of previous month'))),
            'balance' => old('statement_balance', $draft ? number_format((float) $draft->statement_balance, 2, '.', '') : '')]);
    }

    /** Step 2: tick the lines on the statement. POST: save / finish / discard. */
    public function rec(Request $request, int $id)
    {
        $rec = $this->recOrFail($id);
        $l = $this->ledgerOrFail((int) $rec->ledger_id);
        if ($rec->status !== 'draft') {
            return redirect()->route('banking.report', $rec->id);
        }

        if ($request->isMethod('post')) {
            $do = (string) $request->input('do');
            if ($do === 'discard') {
                $this->bank->discard($rec);

                return redirect()->route('banking.index')->with('message', 'The reconciliation was discarded. Nothing was marked as cleared.');
            }
            $this->bank->saveTicks($l, $rec, (array) $request->input('cleared'));
            if ($do === 'finish') {
                $this->bank->finish($l, $rec);

                return redirect()->route('banking.report', $rec->id)->with('message', $l->name.' is reconciled to '.Money::date($rec->statement_date).'.');
            }

            return redirect()->route('banking.rec', $rec->id)->with('message', 'Saved. You can come back and finish this reconciliation any time.');
        }

        $rec->begin_balance = $this->bank->clearedBalance($l); // follows anything cleared since the draft began
        $lines = $this->bank->lines($l, $rec);

        return view('banking.rec', ['ledger' => $l, 'rec' => $rec, 'lines' => $lines, 'totals' => $this->bank->totals($rec, $lines), 'nature' => $this->reports->nature($l)]);
    }

    public function history(int $id)
    {
        $l = $this->ledgerOrFail($id);

        return view('banking.history', ['ledger' => $l, 'recs' => $this->bank->history($l->id), 'last' => $this->bank->lastDone($l->id), 'draft' => $this->bank->draftFor($l->id)]);
    }

    /** The reconciliation report: summary, cleared lines, and what is still uncleared. */
    public function report(int $id)
    {
        $rec = $this->recOrFail($id);
        $l = $this->ledgerOrFail((int) $rec->ledger_id);
        if ($rec->status === 'draft') {
            return redirect()->route('banking.rec', $rec->id);
        }
        $cleared = $this->bank->clearedLines($l, $rec);
        $in = $out = '0.00';
        foreach ($cleared as $c) {
            $c['in'] !== null ? $in = Money::add($in, $c['in']) : $out = Money::add($out, $c['out']);
        }
        $uncleared = $this->bank->lines($l, (object) ['statement_date' => $rec->statement_date, 'id' => 0]);
        $last = $this->bank->lastDone($l->id);

        return view('banking.report', ['ledger' => $l, 'rec' => $rec, 'cleared' => $cleared, 'uncleared' => $uncleared, 'sum' => ['in' => $in, 'out' => $out],
            'book' => $this->bank->bookBalance($l, substr($rec->statement_date, 0, 10)), 'isLast' => $last && (int) $last->id === (int) $rec->id, 'nature' => $this->reports->nature($l)]);
    }

    public function undo(int $id)
    {
        $rec = $this->recOrFail($id);
        $l = $this->ledgerOrFail((int) $rec->ledger_id);
        $this->bank->undo($l, $rec);

        return redirect()->route('banking.history', $l->id)->with('message', 'The reconciliation to '.Money::date($rec->statement_date).' was undone - its lines are uncleared again.');
    }
}
