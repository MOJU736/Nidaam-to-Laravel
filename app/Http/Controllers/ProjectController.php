<?php

namespace App\Http\Controllers;

use App\Services\Construction\ConstructionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Construction: the project list, a project's page (contract, costs against budget, variations, claims, retention, advance) and its forms. */
class ProjectController extends Controller
{
    public function __construct(protected ConstructionService $projects) {}

    public function index(Request $request)
    {
        $status = array_key_exists((string) $request->query('status'), ConstructionService::STATUSES) ? $request->query('status') : null;
        $rows = $this->projects->summaries($this->projects->projects($status));
        $totals = array_fill_keys(['contract', 'cost', 'billed', 'profit', 'owed'], '0.00');
        foreach ($rows as $r) {
            foreach ($totals as $k => $v) {
                $totals[$k] = \App\Support\Money::add($v, $r->$k);
            }
        }
        $counts = DB::connection('tenant')->table('projects')->groupBy('status')->selectRaw('status, COUNT(*) n')->pluck('n', 'status')->all();
        $counts[''] = array_sum($counts);

        return view('construction.projects', ['rows' => $rows, 'totals' => $totals, 'status' => $status, 'counts' => $counts]);
    }

    protected function form(?int $id, ?object $project, array $budgets)
    {
        return view('construction.project-form', ['project' => $project, 'budgets' => $budgets, 'customers' => DB::connection('tenant')->table('customers')->orderBy('name')->pluck('name', 'id')->all(),
            'next' => $this->projects->nextNumber()]);
    }

    public function create(Request $request)
    {
        return $this->form(null, null, array_fill_keys(array_keys(ConstructionService::COST_TYPES), ''));
    }

    public function edit(int $id)
    {
        $p = $this->projects->project($id);
        abort_unless($p, 404);

        return $this->form($id, $p, $this->projects->budgets($id));
    }

    protected function posted(Request $request): array
    {
        $d = $request->only(['name', 'customer_id', 'site', 'contract_amount', 'start_date', 'end_date', 'status', 'retention_percent', 'advance_amount', 'notes']);
        $d['budgets'] = [];
        foreach (array_keys(ConstructionService::COST_TYPES) as $k) {
            $d['budgets'][$k] = $request->input("budget_$k");
        }

        return $d;
    }

    public function store(Request $request)
    {
        $id = $this->projects->saveProject($this->posted($request));

        return redirect()->route('projects.show', $id)->with('message', 'Project created.');
    }

    public function update(Request $request, int $id)
    {
        $this->projects->saveProject($this->posted($request), $id);

        return redirect()->route('projects.show', $id)->with('message', 'Project saved.');
    }

    public function show(int $id, \App\Services\Construction\EstimateService $est, \App\Services\Construction\SiteService $site)
    {
        $project = $this->projects->project($id);
        abort_unless($project, 404);
        $p = $this->projects->summaries([$project])[$id];

        return view('construction.project', ['p' => $p, 'budgets' => $this->projects->budgets($id), 'variations' => $this->projects->variations($id), 'recent' => $this->projects->recentCosts($id),
            'invoices' => $this->projects->invoices($id), 'claims' => $this->projects->claims($id), 'ct' => $this->projects->claimTotals($id), 'advances' => $this->projects->advances($id),
            'banks' => $this->projects->bankOptions(), 'projects' => $this->projects,
            'progress' => $site->progress($id), 'approvedEstimate' => $approved = $est->approved($id), 'sectionBudgets' => $est->sectionBudgets($id), 'sectionDone' => $est->sectionProgress($id),
            'measuredPrev' => $approved ? $est->measuredToDate($id) : [], 'measuredClaims' => DB::connection('tenant')->table('claim_items')->join('project_claims', 'project_claims.id', '=', 'claim_items.claim_id')
                ->where('project_claims.project_id', $id)->distinct()->pluck('claim_items.claim_id')->flip()->all()]);
    }

    public function destroy(int $id)
    {
        $this->projects->deleteProject($id);

        return redirect()->route('projects.index')->with('message', 'Project deleted.');
    }

    public function variationStore(Request $request, int $id)
    {
        $this->projects->addVariation($id, $request->only(['date', 'description', 'amount']));

        return redirect()->to(route('projects.show', $id).'#variations')->with('message', 'Variation added.');
    }

    public function variationDestroy(int $variationId)
    {
        $pid = $this->projects->deleteVariation($variationId);

        return redirect()->to(route('projects.show', $pid).'#variations')->with('message', 'Variation removed.');
    }

    public function claimStore(Request $request, int $id)
    {
        $this->projects->createClaim($id, $request->only(['date', 'percent', 'notes']) + ['measured' => (array) $request->input('mq', [])]);

        return redirect()->to(route('projects.show', $id).'#claims')->with('message', 'Progress claim created and invoiced.');
    }

    public function retentionRelease(Request $request, int $id)
    {
        $this->projects->releaseRetention($id, $request->only(['date']));

        return redirect()->to(route('projects.show', $id).'#claims')->with('message', 'Retention released and invoiced.');
    }

    public function advanceStore(Request $request, int $id)
    {
        $this->projects->recordAdvance($id, $request->only(['date', 'amount', 'ledger_id', 'reference', 'method']));

        return redirect()->to(route('projects.show', $id).'#claims')->with('message', 'Advance recorded.');
    }
}
