<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Setting;
use App\Services\Accounting\ActivityLog;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentStyle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Settings → Templates: which design documents print on, the colours, logo, signature, stamp, footer and words. Administrators only. */
class DocumentTemplatesController extends Controller
{
    public function __construct(protected DocumentStyle $style, protected DocumentService $docs)
    {
        $this->style->ensureTable();
    }

    public function edit(Request $request)
    {
        $s = $this->style->settings();
        $design = (int) ($request->query('design') ?: $s->template_id ?: 1);
        $design = isset(DocumentStyle::DESIGNS[$design]) ? $design : 1;

        return view('settings.templates', [
            's' => $s, 'design' => $design, 'chosen' => (int) ($s->template_id ?: 1), 'designs' => DocumentStyle::DESIGNS, 'saved' => $this->style->styleFor($design, $s), 'defaults' => $this->style->defaults($design),
            'fonts' => DocumentStyle::FONTS, 'parts' => DocumentStyle::PARTS, 'words' => DocumentStyle::WORDS, 'company' => Setting::first(),
        ]);
    }

    /** One uploaded picture saved under public/branding/<folder>/, replacing nothing (older files stay). Returns the file name. */
    protected function upload(Request $request, string $field, string $folder, string $prefix): ?string
    {
        if (! $request->hasFile($field)) {
            return null;
        }
        $request->validate([$field => ['image', 'mimes:png,jpg,jpeg,gif', 'max:2048']]);
        $file = $request->file($field);
        $name = $prefix.time().'.'.strtolower($file->getClientOriginalExtension() ?: 'png'); // short: settings.logo is 25 characters
        $dir = public_path("branding/$folder");
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file->move($dir, $name);

        return $name;
    }

    protected function options(Request $request): array
    {
        return $this->style->clean((array) $request->input('look'));
    }

    protected function companyRow(Request $request): array
    {
        $d = $request->validate(['signature_name' => ['nullable', 'string', 'max:150'], 'signature_title' => ['nullable', 'string', 'max:150'], 'footer_address' => ['nullable', 'string', 'max:255'],
            'footer_phone' => ['nullable', 'string', 'max:100'], 'footer_email' => ['nullable', 'string', 'max:150'], 'footer_website' => ['nullable', 'string', 'max:150'], 'qty_label' => ['nullable', 'string', 'max:30'],
            'price_label' => ['nullable', 'string', 'max:30'], 'payment_extra' => ['nullable', 'string', 'max:1000'], 'footer_note' => ['nullable', 'string', 'max:255']]);

        $row = array_map(fn ($v) => ($v === null || trim((string) $v) === '') ? null : trim((string) $v), $d);
        if ($request->has('cf_form') && \App\Support\Tables::has('document_template_settings', 'cf1_label')) { // the custom-field boxes were on the page
            foreach (range(1, 5) as $i) {
                $label = trim((string) $request->input("cf{$i}_label"));
                $docs = array_values(array_intersect(['invoice', 'quotation'], (array) $request->input("cf{$i}_docs")));
                if (in_array('certificate', explode(',', (string) ($this->style->settings()->{"cf{$i}_docs"} ?? '')), true)) {
                    $docs[] = 'certificate'; // certificates are not in this app yet; keep the old choice so nothing is lost
                }
                $row["cf{$i}_label"] = $label === '' ? null : mb_substr($label, 0, 100);
                $row["cf{$i}_docs"] = $label === '' || ! $docs ? null : implode(',', $docs);
            }
        }

        return $row;
    }

    public function update(Request $request)
    {
        $design = (int) $request->input('design');
        abort_unless(isset(DocumentStyle::DESIGNS[$design]), 422);
        $s = $this->style->settings();
        $all = $this->style->styles($s);
        $mine = $this->options($request);
        if ($request->input('then') === 'reset') {
            unset($all[(string) $design]);
        } elseif ($mine) {
            $all[(string) $design] = $mine;
        } else {
            unset($all[(string) $design]);
        }

        $row = $this->companyRow($request) + ['style_json' => $all ? json_encode($all) : null, 'template_default_applied' => 1];
        if ($request->boolean('make_default') || $request->input('then') === 'default') {
            $row['template_id'] = $design;
        }
        if ($sig = $this->upload($request, 'signature_file', 'signature', 'sig_')) {
            $row['signature_image'] = $sig;
        }
        if ($stamp = $this->upload($request, 'stamp_file', 'stamp', 'stamp_')) {
            $row['stamp_image'] = $stamp;
        }
        if ($request->boolean('remove_signature')) {
            $row['signature_image'] = null;
        }
        if ($request->boolean('remove_stamp')) {
            $row['stamp_image'] = null;
        }
        $this->style->save($row);

        if ($logo = $this->upload($request, 'logo_file', 'logo', 'logo_')) {
            DB::connection('tenant')->table('settings')->update(['logo' => $logo]);
        }
        if ($request->boolean('remove_logo')) {
            DB::connection('tenant')->table('settings')->update(['logo' => null]);
        }
        ActivityLog::add("Updated document template (design $design)");

        return redirect()->route('settings.templates', ['design' => $design])->with('message', $request->input('then') === 'reset' ? 'This design is back to how it was made.' : 'Document look saved.');
    }

    /** The unsaved look on a real document (the newest issued invoice, else a sample), as a web page to look at. */
    public function preview(Request $request)
    {
        $design = (int) $request->input('design');
        abort_unless(isset(DocumentStyle::DESIGNS[$design]), 422);
        $row = (object) array_merge((array) $this->style->settings(), $this->companyRow($request));
        if ($request->hasFile('logo_file') && $request->file('logo_file')->isValid()) {
            $mime = $request->file('logo_file')->getMimeType();
            $row->_inline_logo = 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($request->file('logo_file')->getRealPath()));
        }
        $st = $this->style->resolve($this->options($request), $design, $row);
        if (! empty($row->_inline_logo)) {
            $st['logo'] = $row->_inline_logo;
        }
        $id = DB::connection('tenant')->table('invoices')->where('status', 'issued')->orderByDesc('id')->value('id');
        $built = $id ? $this->docs->build('invoice', (int) $id) : null;
        $d = $built['d'] ?? $this->sample();

        return response(view('pdf.document', ['d' => $d, 'st' => $st])->render());
    }

    /** A made-up invoice, for a company that has none yet. */
    protected function sample(): array
    {
        $co = $this->docs->company();

        return ['title' => 'INVOICE', 'kind' => 'invoice', 'number' => 'INV-0001', 'company' => $co, 'party_label' => 'Billed to', 'party' => ['name' => 'Sample Customer', 'address' => "12 Example Street\nSomewhere", 'phone' => '+000 000 000', 'email' => 'customer@example.com'],
            'meta' => [['Invoice #', 'INV-0001'], ['Date', date('d-M-Y')], ['Due', date('d-M-Y', strtotime('+14 days'))]], 'columns' => [['h' => 'Description', 'k' => 'description'], ['h' => 'Qty', 'k' => 'qty', 'align' => 'r', 'w' => '8%'],
                ['h' => 'Price', 'k' => 'price', 'align' => 'r', 'w' => '16%'], ['h' => 'Amount', 'k' => 'amount', 'align' => 'r', 'w' => '18%']],
            'rows' => [['description' => 'First item', 'qty' => '2', 'price' => '$50.00', 'amount' => '$100.00'], ['description' => 'Second item', 'qty' => '1', 'price' => '$25.00', 'amount' => '$25.00']],
            'totals' => [['Subtotal', '$125.00', false], ['Total', '$125.00', true]], 'total_raw' => '125.00', 'big' => [], 'notes' => 'Thank you for your business.', 'bank' => [], 'footer' => 'We appreciate your business!', 'stamp' => null, 'pad' => true];
    }
}
