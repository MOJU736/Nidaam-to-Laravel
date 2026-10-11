<?php

namespace App\Http\Controllers;

use App\Services\Construction\ConstructionDocs;
use App\Services\Construction\ConstructionService;
use App\Services\Construction\EstimateService;
use App\Services\Documents\EmailService;
use App\Services\Documents\PdfService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Construction: a project's estimate (bill of quantities), the rate list and the units. */
class EstimateController extends Controller
{
    public function __construct(protected EstimateService $estimates, protected ConstructionService $projects, protected ConstructionDocs $docs) {}

    protected function project(int $id): object
    {
        $p = $this->projects->project($id);
        abort_unless($p, 404);

        return $p;
    }

    public function show(Request $request, int $id)
    {
        $p = $this->project($id);
        $eid = (int) $request->query('e');
        $e = $eid ? $this->estimates->estimate($eid) : $this->estimates->current($id);
        if ($e && (int) $e->project_id !== $id) {
            $e = $this->estimates->current($id);
        }
        $copyFrom = [];
        foreach ($this->projects->projects() as $op) {
            foreach ($this->estimates->estimates((int) $op->id) as $oe) {
                $copyFrom[$oe->id] = $this->projects->label($op).' - estimate '.$oe->number.' ('.Money::format($oe->subtotal).')';
            }
        }
        $taxes = DB::connection('tenant')->table('tax_rates')->where('on_sales', 1)->orderBy('rate')->get(['name', 'rate']);
        $sections = $e ? $e->sections->map(fn ($s) => ['name' => $s->name, 'items' => $s->items->map(fn ($i) => ['description' => $i->description, 'unit' => $i->unit, 'qty' => rtrim(rtrim((string) $i->qty, '0'), '.'),
            'rate' => $i->rate, 'cost_type' => $i->cost_type])->values()->all()])->values()->all() : [];

        return view('construction.estimate', ['p' => $p, 'e' => $e, 'history' => $this->estimates->estimates($id), 'rates' => $this->estimates->rates(), 'units' => $this->estimates->units(), 'taxes' => $taxes,
            'copyFrom' => $copyFrom, 'canApprove' => $this->estimates->canApprove(), 'hasClaims' => $this->projects->claimTotals($id)['claims'] > 0, 'sections' => $sections,
            'customerEmail' => (string) (DB::connection('tenant')->table('customers')->where('id', $p->customer_id)->value('email') ?? ''), 'projects' => $this->projects]);
    }

    public function create(Request $request, int $id)
    {
        $this->project($id);
        $eid = $this->estimates->create($id, (int) $request->input('from_id') ?: null);

        return redirect()->to(route('projects.estimate', $id).'?e='.$eid);
    }

    /** A revision of an approved estimate: a new draft copied from it. */
    public function revise(int $eid)
    {
        $e = $this->estimates->estimate($eid);
        abort_unless($e, 404);
        $nid = $this->estimates->create((int) $e->project_id, $eid);

        return redirect()->to(route('projects.estimate', $e->project_id).'?e='.$nid)->with('message', 'Revision started - change it, then approve it to replace the current estimate.');
    }

    public function save(Request $request, int $eid)
    {
        $e = $this->estimates->estimate($eid);
        abort_unless($e, 404);
        $sections = json_decode((string) $request->input('sections_json'), true);
        $this->estimates->save($eid, $request->only(['date', 'title', 'tax_percent', 'tax_name', 'margin_percent', 'notes']) + ['sections' => is_array($sections) ? $sections : []], $request->boolean('save_rates'));
        $msg = 'Estimate saved.';
        if ($request->input('then') === 'approve') {
            $this->estimates->approve($eid);
            $msg = 'Estimate approved - the contract and budgets are set from it.';
        }

        return redirect()->to(route('projects.estimate', $e->project_id).'?e='.$eid)->with('message', $msg);
    }

    public function approve(int $eid)
    {
        $e = $this->estimates->estimate($eid);
        abort_unless($e, 404);
        $this->estimates->approve($eid);

        return redirect()->to(route('projects.estimate', $e->project_id).'?e='.$eid)->with('message', 'Estimate approved - the contract and budgets are set from it.');
    }

    public function destroy(int $eid)
    {
        $pid = $this->estimates->delete($eid);

        return redirect()->route('projects.estimate', $pid)->with('message', 'Draft estimate deleted.');
    }

    public function pdf(Request $request, int $eid)
    {
        [$bytes, $file] = $this->docs->estimate($eid);

        return app(PdfService::class)->response($bytes, $file, (bool) $request->query('download'));
    }

    public function email(Request $request, int $eid)
    {
        $e = $this->estimates->estimate($eid);
        abort_unless($e, 404);
        $p = $this->project((int) $e->project_id);
        $mail = app(EmailService::class);
        $to = $mail->addresses((string) ($request->input('email') ?: DB::connection('tenant')->table('customers')->where('id', $p->customer_id)->value('email')));
        if (! $to) {
            throw ValidationException::withMessages(['projects' => 'Enter a valid email address to send to.']);
        }
        $company = (string) (\App\Models\Tenant\Setting::first()->name ?? '');
        [$bytes, $file] = $this->docs->estimate($eid);
        $who = $mail->send($to, [], [], "Estimate for {$p->name} from $company", '<p>Hello '.e($p->customer_name).',</p><p>Please find attached our estimate (bill of quantities) for <strong>'.e($p->name).'</strong>, total '.e(Money::format($e->total)).'.</p><p>Thank you.<br>'.e($company).'</p>', $bytes, $file);

        return redirect()->to(route('projects.estimate', $p->id).'?e='.$eid)->with('message', "Estimate emailed to $who.");
    }

    // ---- rate list and units ------------------------------------------------------------------------------------

    public function rates(Request $request)
    {
        $edit = (int) $request->query('edit');

        return view('construction.rates', ['rates' => $this->estimates->rates(), 'units' => $this->estimates->units(), 'ownUnits' => array_values(array_diff($this->estimates->units(), EstimateService::UNITS)),
            'edit' => $edit ? DB::connection('tenant')->table('construction_rates')->where('id', $edit)->first() : null]);
    }

    public function rateSave(Request $request, ?int $id = null)
    {
        $this->estimates->saveRate($request->only(['description', 'unit', 'rate', 'cost_type']), $id);

        return redirect()->route('construction.rates')->with('message', 'Rate saved.');
    }

    public function rateDelete(int $id)
    {
        $this->estimates->deleteRate($id);

        return redirect()->route('construction.rates');
    }

    public function unitAdd(Request $request)
    {
        $this->estimates->addUnit((string) $request->input('name'));

        return redirect()->to(route('construction.rates').'#units')->with('message', 'Unit added.');
    }

    public function unitDelete(Request $request)
    {
        $this->estimates->deleteUnit((string) $request->input('name'));

        return redirect()->to(route('construction.rates').'#units');
    }
}
