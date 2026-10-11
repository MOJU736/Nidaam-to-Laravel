<?php

namespace App\Services\Construction;

use App\Models\Tenant\Setting;
use App\Services\Documents\DocumentService;
use App\Services\Documents\PdfService;
use Illuminate\Support\Facades\DB;

/** The PDFs of Construction: the estimate (bill of quantities), the measurement sheet of a measured claim, and the weekly site report. */
class ConstructionDocs
{
    public function __construct(protected PdfService $pdf, protected DocumentService $docs, protected EstimateService $estimates, protected SiteService $site, protected ConstructionService $projects) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    /** The company's heading: name, address, email and the logo (as an embedded picture) when there is one. */
    protected function company(): array
    {
        $c = $this->docs->company();
        $logo = Setting::first()?->logo;
        $path = $logo ? public_path('branding/logo/'.$logo) : null;
        $c['logo'] = $path && is_file($path) ? 'data:'.(mime_content_type($path) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($path)) : null;

        return $c;
    }

    protected function customer(object $project): ?object
    {
        return $this->db()->table('customers')->where('id', $project->customer_id)->first();
    }

    /** @return array{0:string,1:string} PDF bytes and file name */
    public function estimate(int $estimateId): array
    {
        $e = $this->estimates->estimate($estimateId) ?? abort(404);
        $p = $this->projects->project((int) $e->project_id);
        $bytes = $this->pdf->fromHtml(view('construction.pdf.estimate', ['p' => $p, 'e' => $e, 'company' => $this->company(), 'customer' => $this->customer($p)])->render());

        return [$bytes, 'Estimate '.$p->number.'-'.(int) $e->number.'.pdf'];
    }

    public function reportFile(object $p, object $r): string
    {
        return 'Site report '.$p->number.'-'.(int) $r->number.' ('.substr((string) $r->week_start, 0, 10).').pdf';
    }

    public function report(int $reportId): array
    {
        $r = $this->site->report($reportId) ?? abort(404);
        $p = $this->projects->project((int) $r->project_id);
        $photos = $r->photos->map(function ($ph) {
            $path = public_path('uploads/site/'.$ph->file);

            return is_file($path) ? ['src' => 'data:'.(mime_content_type($path) ?: 'image/jpeg').';base64,'.base64_encode((string) file_get_contents($path)), 'caption' => $ph->caption] : null;
        })->filter()->values()->all();
        $bytes = $this->pdf->fromHtml(view('construction.pdf.report', ['p' => $p, 'r' => $r, 'photos' => $photos, 'company' => $this->company(), 'fields' => SiteService::REPORT_TEXT_FIELDS])->render());

        return [$bytes, $this->reportFile($p, $r)];
    }

    /** The measurement sheet of a measured claim: each BOQ line with previous, this claim and to-date quantities. */
    public function claimSheet(int $claimId): array
    {
        $c = $this->db()->table('project_claims')->where('id', $claimId)->first() ?? abort(404);
        $p = $this->projects->project((int) $c->project_id);
        $lines = $this->db()->table('claim_items as ci')->leftJoin('estimate_items as ei', 'ei.id', '=', 'ci.estimate_item_id')->leftJoin('estimate_sections as es', 'es.id', '=', 'ei.section_id')
            ->where('ci.claim_id', $claimId)->orderBy('es.sort')->orderBy('ei.sort')->get(['ci.*', 'ei.description', 'ei.unit', 'ei.qty', 'es.name as section_name']);
        // the previous live measured claim, for the "previous" and "this claim" columns
        $prevId = $this->db()->table('project_claims as c')->join('invoices as i', 'i.id', '=', 'c.invoice_id')->join('claim_items as x', 'x.claim_id', '=', 'c.id')
            ->where('c.project_id', $p->id)->where('c.kind', 'claim')->where('c.id', '<', $claimId)->where('i.status', 'issued')->orderByDesc('c.id')->value('c.id');
        $prev = $prevId ? $this->db()->table('claim_items')->where('claim_id', $prevId)->get()->keyBy('estimate_item_id') : collect();
        $bytes = $this->pdf->fromHtml(view('construction.pdf.claim-sheet', ['p' => $p, 'c' => $c, 'lines' => $lines, 'prev' => $prev, 'company' => $this->company(), 'customer' => $this->customer($p)])->render());

        return [$bytes, 'Measurement sheet '.$p->number.'-'.(int) $c->number.'.pdf'];
    }
}
