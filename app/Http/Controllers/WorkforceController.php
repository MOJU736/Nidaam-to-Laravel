<?php

namespace App\Http\Controllers;

use App\Services\Construction\ConstructionService;
use App\Services\Construction\WagesService;
use Illuminate\Http\Request;

/** Construction: site workers and their wage sheets. */
class WorkforceController extends Controller
{
    public function __construct(protected WagesService $wages, protected ConstructionService $projects) {}

    public function workers(Request $request)
    {
        $edit = (int) $request->query('edit');

        return view('construction.workers', ['workers' => $this->wages->workers(), 'projects' => $this->projects->options(), 'edit' => $edit ? $this->wages->worker($edit) : null, 'payTypes' => WagesService::PAY_TYPES]);
    }

    public function workerSave(Request $request, ?int $id = null)
    {
        $this->wages->saveWorker($request->only(['name', 'phone', 'trade', 'pay_type', 'rate', 'default_project_id', 'status']), $id);

        return redirect()->route('construction.workers')->with('message', $id ? 'Worker saved.' : 'Worker added.');
    }

    public function sheets()
    {
        return view('construction.wages', ['sheets' => $this->wages->sheets()]);
    }

    public function sheetCreate()
    {
        return view('construction.wage-form', ['workers' => $this->wages->workers(true), 'projects' => $this->projects->options(), 'banks' => $this->projects->bankOptions(), 'next' => $this->wages->nextNumber()]);
    }

    public function sheetStore(Request $request)
    {
        $lines = [];
        foreach ((array) $request->input('worker_id', []) as $i => $wid) {
            $lines[] = ['worker_id' => $wid, 'project_id' => $request->input("project_id.$i"), 'days' => $request->input("days.$i"), 'rate' => $request->input("rate.$i"),
                'amount' => $request->input("amount.$i"), 'note' => $request->input("note.$i")];
        }
        $id = $this->wages->createSheet($request->only(['date_from', 'date_to', 'date', 'paid_ledger_id', 'notes']) + ['lines' => $lines]);

        return redirect()->route('construction.wages.show', $id)->with('message', 'Wage sheet posted.');
    }

    public function sheetShow(int $id)
    {
        $s = $this->wages->sheet($id);
        abort_unless($s, 404);

        return view('construction.wage-show', ['s' => $s, 'banks' => $s->status === 'unpaid' ? $this->projects->bankOptions() : []]);
    }

    public function sheetPay(Request $request, int $id)
    {
        $this->wages->paySheet($id, $request->only(['date', 'ledger_id']));

        return redirect()->route('construction.wages.show', $id)->with('message', 'Wage sheet paid.');
    }

    public function sheetVoid(int $id)
    {
        $this->wages->voidSheet($id);

        return redirect()->route('construction.wages.show', $id)->with('message', 'Wage sheet voided.');
    }
}
