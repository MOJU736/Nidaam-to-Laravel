<?php

namespace App\Http\Controllers;

use App\Services\Construction\ConstructionDocs;
use App\Services\Construction\ConstructionService;
use App\Services\Construction\EstimateService;
use App\Services\Construction\SiteService;
use App\Services\Construction\SiteStockService;
use App\Services\Documents\EmailService;
use App\Services\Documents\PdfService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Construction: the schedule, weekly site reports, claims by measurement (sheet and method) and site stock. */
class SiteController extends Controller
{
    public function __construct(protected SiteService $site, protected ConstructionService $projects, protected EstimateService $estimates, protected SiteStockService $stock, protected ConstructionDocs $docs) {}

    protected function project(int $id): object
    {
        $p = $this->projects->project($id);
        abort_unless($p, 404);

        return $p;
    }

    // ---- schedule ---------------------------------------------------------------------------------------------------

    public function schedule(int $id)
    {
        $p = $this->project($id);
        $stages = $this->site->stages($id);

        return view('construction.schedule', ['p' => $p, 'stages' => $stages, 'progress' => $this->site->progress($id, null, $stages)]);
    }

    public function scheduleSave(Request $request, int $id)
    {
        $this->project($id);
        $rows = [];
        foreach ((array) $request->input('name', []) as $i => $n) {
            $rows[] = ['name' => $n] + collect(['start_date', 'end_date', 'weight', 'percent_done', 'notes'])->mapWithKeys(fn ($k) => [$k => $request->input("$k.$i")])->all();
        }
        $this->site->saveStages($id, $rows);

        return redirect()->route('projects.schedule', $id)->with('message', 'Schedule saved.');
    }

    public function scheduleStandard(int $id)
    {
        $this->site->loadStandardStages($this->project($id));

        return redirect()->route('projects.schedule', $id)->with('message', 'The 15 standard stages are in - adjust the dates and weights to this job.');
    }

    // ---- weekly site reports ---------------------------------------------------------------------------------------------

    public function reports(int $id)
    {
        return view('construction.reports', ['p' => $this->project($id), 'reports' => $this->site->reports($id), 'progress' => $this->site->progress($id), 'statuses' => SiteService::REPORT_STATUSES]);
    }

    public function reportAdd(Request $request, int $id)
    {
        $this->project($id);
        $rid = $this->site->newReport($id, (string) $request->input('week') ?: null);

        return redirect()->route('projects.report', $rid);
    }

    public function report(int $rid)
    {
        $r = $this->site->report($rid);
        abort_unless($r, 404);
        $p = $this->project((int) $r->project_id);
        $phone = preg_replace('/\D+/', '', (string) DB::connection('tenant')->table('customers')->where('id', $p->customer_id)->value('phone'));

        return view('construction.report', ['p' => $p, 'r' => $r, 'canApprove' => $this->estimates->canApprove(), 'fields' => SiteService::REPORT_TEXT_FIELDS, 'statuses' => SiteService::REPORT_STATUSES,
            'costTypes' => ConstructionService::COST_TYPES, 'whatsapp' => $phone !== '' ? 'https://wa.me/'.$phone.'?text='.rawurlencode($this->whatsAppText($p, $r)) : null,
            'customerEmail' => (string) (DB::connection('tenant')->table('customers')->where('id', $p->customer_id)->value('email') ?? '')]);
    }

    protected function whatsAppText(object $p, object $r): string
    {
        $a = $r->auto ?: [];
        $t = "*Weekly site report {$r->number}* - {$p->name}\n".Money::date($r->week_start).' to '.Money::date($r->week_end)."\n";
        if ($r->actual_pct !== null) {
            $t .= 'Progress: '.(float) $r->actual_pct.'% done (planned '.(float) $r->planned_pct."%)\n";
        }
        if (! empty($a['workers'])) {
            $t .= 'Workers on site: '.(int) $a['workers'].' ('.(float) $a['man_days']." man-days)\n";
        }
        foreach (['work_done' => 'Work done', 'next_week' => 'Next week', 'challenges' => 'Challenges'] as $k => $label) {
            if (trim((string) $r->{$k}) !== '') {
                $t .= "\n*$label:*\n".trim($r->{$k})."\n";
            }
        }

        return $t."\n".(\App\Models\Tenant\Setting::first()->name ?? '');
    }

    public function reportSave(Request $request, int $rid)
    {
        $r = $this->site->report($rid);
        abort_unless($r, 404);
        $in = $request->only(array_keys(SiteService::REPORT_TEXT_FIELDS));
        if ($request->boolean('refresh')) {
            $in['refresh'] = 1;
        }
        $this->site->saveReport($rid, $in);
        if ($request->input('then') === 'submit') {
            $this->site->reportStatus($rid, 'submit');

            return redirect()->route('projects.report', $rid)->with('message', 'Report sent for approval.');
        }

        return redirect()->route('projects.report', $rid)->with('message', $request->boolean('refresh') ? 'Saved, and the figures are brought up to date.' : 'Report saved.');
    }

    public function reportStatus(Request $request, int $rid)
    {
        $action = (string) $request->input('action');
        $this->site->reportStatus($rid, $action, (string) $request->input('note'));
        $msgs = ['submit' => 'Report sent for approval.', 'approve' => 'Report approved.', 'return' => 'Report returned to the engineer.', 'reopen' => 'Report reopened for changes.'];

        return redirect()->route('projects.report', $rid)->with('message', $msgs[$action] ?? 'Done.');
    }

    public function reportDelete(int $rid)
    {
        $pid = $this->site->deleteReport($rid);

        return redirect()->route('projects.reports', $pid)->with('message', 'Report deleted.');
    }

    public function photoAdd(Request $request, int $rid)
    {
        $files = array_filter((array) $request->file('photos'));
        if (! $files) {
            throw ValidationException::withMessages(['projects' => 'Choose a photo.']);
        }
        foreach ($files as $f) {
            $this->site->addPhoto($rid, $f, $request->input('caption'));
        }

        return redirect()->to(route('projects.report', $rid).'#photos')->with('message', count($files) === 1 ? 'Photo added.' : count($files).' photos added.');
    }

    public function photoDelete(int $photoId)
    {
        $rid = $this->site->deletePhoto($photoId);

        return redirect()->to(route('projects.report', $rid).'#photos');
    }

    public function reportPdf(Request $request, int $rid)
    {
        [$bytes, $file] = $this->docs->report($rid);

        return app(PdfService::class)->response($bytes, $file, (bool) $request->query('download'));
    }

    public function reportEmail(Request $request, int $rid)
    {
        $r = $this->site->report($rid);
        abort_unless($r, 404);
        $p = $this->project((int) $r->project_id);
        $mail = app(EmailService::class);
        $to = $mail->addresses((string) ($request->input('email') ?: DB::connection('tenant')->table('customers')->where('id', $p->customer_id)->value('email')));
        if (! $to) {
            throw ValidationException::withMessages(['projects' => 'Enter a valid email address to send to.']);
        }
        $company = (string) (\App\Models\Tenant\Setting::first()->name ?? '');
        [$bytes, $file] = $this->docs->report($rid);
        $body = '<p>Hello '.e($p->customer_name).',</p><p>Please find attached the weekly site report for <strong>'.e($p->name).'</strong>, week of '.e(Money::date($r->week_start)).' to '.e(Money::date($r->week_end)).'.</p>'
            .($r->actual_pct !== null ? '<p>Progress: '.(float) $r->actual_pct.'% done (planned '.(float) $r->planned_pct.'%).</p>' : '').'<p>'.e($company).'</p>';
        $who = $mail->send($to, [], [], "Weekly site report {$r->number} - {$p->name}", $body, $bytes, $file);

        return redirect()->route('projects.report', $rid)->with('message', "Site report emailed to $who.");
    }

    // ---- claims by measurement ---------------------------------------------------------------------------------------------

    public function claimMethod(Request $request, int $id)
    {
        $m = $request->input('method') === 'measured' ? 'measured' : 'percent';
        $this->projects->setClaimMethod($id, $m);

        return redirect()->to(route('projects.show', $id).'#claims')->with('message', $m === 'measured' ? 'Claims on this project are now by measured quantities.' : 'Claims on this project are now by % complete.');
    }

    public function claimSheet(int $cid)
    {
        [$bytes, $file] = $this->docs->claimSheet($cid);

        return app(PdfService::class)->response($bytes, $file);
    }

    // ---- site stock --------------------------------------------------------------------------------------------------------

    public function stock()
    {
        $t = $this->stock->stockTable();
        $names = [0 => 'Main store'];
        foreach ($this->stock->stores() as $s) {
            $names[(int) $s->id] = $s->name;
        }

        return view('construction.stock', ['stores' => $t['stores'], 'rows' => $t['rows'], 'moves' => $this->stock->moves(), 'projects' => $this->projects->options(), 'names' => $names,
            'items' => DB::connection('tenant')->table('items')->where('type', 'inventory')->where('status', 1)->orderBy('name')->get(['id', 'name'])]);
    }

    public function storeAdd(Request $request)
    {
        $this->stock->addStore($request->only(['name', 'project_id']));

        return redirect()->route('construction.stock')->with('message', 'Store added.');
    }

    public function stockMove(Request $request)
    {
        $d = $request->only(['item_id', 'qty', 'date', 'from_store_id', 'to_store_id', 'project_id', 'notes']);
        $request->input('kind') === 'issue' ? $this->stock->issue($d) : $this->stock->transfer($d);

        return redirect()->route('construction.stock')->with('message', $request->input('kind') === 'issue' ? 'Materials issued to the project.' : 'Stock moved.');
    }

    public function stockVoid(int $id)
    {
        $this->stock->voidMove($id);

        return redirect()->route('construction.stock')->with('message', 'Movement voided.');
    }
}
