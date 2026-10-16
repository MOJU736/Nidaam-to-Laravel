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

    /** What the editor can show: key => label. */
    public const PREVIEWS = ['invoice' => 'Invoice', 'quotation' => 'Quotation', 'receipt' => 'Receipt', 'statement' => 'Customer statement', 'item' => 'Item statement'];

    /** The unsaved look on a real document (the newest one of that kind, else a sample), as a web page to look at. */
    public function preview(Request $request)
    {
        $design = (int) $request->input('design');
        abort_unless(isset(DocumentStyle::DESIGNS[$design]), 422);
        try {
            $company = $this->companyRow($request);
        } catch (\Illuminate\Validation\ValidationException) {
            $company = []; // something typed is too long: show the saved details until it is fixed
        }
        $row = (object) array_merge((array) $this->style->settings(), $company);
        $st = $this->style->resolve($this->options($request), $design, $row);
        foreach (['logo_file' => 'logo', 'signature_file' => 'signature', 'stamp_file' => 'stamp'] as $field => $key) {
            $f = $request->file($field);
            if ($f && $f->isValid() && str_starts_with((string) $f->getMimeType(), 'image/')) {
                $st[$key] = 'data:'.$f->getMimeType().';base64,'.base64_encode((string) file_get_contents($f->getRealPath()));
            }
        }
        foreach (['remove_logo' => 'logo', 'remove_signature' => 'signature', 'remove_stamp' => 'stamp'] as $field => $key) {
            if ($request->boolean($field)) {
                $st[$key] = null;
            }
        }

        $kind = (string) $request->input('doc', 'invoice');
        if ($kind === 'item') {
            return response(view('pdf.table', ['rows' => $this->itemSample(), 'st' => $st, 'preview' => true])->render());
        }
        $d = $this->realOrSample($kind);

        return response(view('pdf.document', ['d' => $d, 'st' => $st, 'preview' => true])->render());
    }

    /** The newest real document of a kind, so the preview shows the company's own words; a made-up one when there is none. */
    protected function realOrSample(string $kind): array
    {
        $db = DB::connection('tenant');
        try {
            $built = match ($kind) {
                'quotation' => ($id = $db->table('quotations')->orderByDesc('id')->value('id')) ? $this->docs->build('quotation', (int) $id) : null,
                'receipt' => ($id = $db->table('payments')->where('status', 'received')->orderByDesc('id')->value('id')) ? $this->docs->build('receipt', (int) $id) : null,
                'statement' => ($id = $db->table('invoices')->where('status', 'issued')->orderByDesc('id')->value('customer_id')) ? $this->docs->build('customer-statement', (int) $id) : null,
                default => ($id = $db->table('invoices')->where('status', 'issued')->orderByDesc('id')->value('id')) ? $this->docs->build('invoice', (int) $id) : null,
            };
        } catch (\Throwable) {
            $built = null;
        }

        return $built['d'] ?? $this->sample($kind);
    }

    /** A made-up item statement (stock movements of one item), shaped like the report that is downloaded as PDF. */
    protected function itemSample(): array
    {
        $co = $this->docs->company()['name'];

        return [[$co], ['Item statement: Sample item'], ['1 Jan '.date('Y').' to '.date('j M Y')], [''], ['Date', 'Details', 'In', 'Out', 'Balance'], [date('d-M-Y', strtotime('-60 days')), 'Opening stock', '', '', '10.00'],
            [date('d-M-Y', strtotime('-45 days')), 'Bill BILL-0003', '40.00', '', '50.00'], [date('d-M-Y', strtotime('-20 days')), 'Invoice INV-0007', '', '12.00', '38.00'], [date('d-M-Y', strtotime('-4 days')), 'Invoice INV-0011', '', '8.00', '30.00'], ['Total', '', '40.00', '20.00', '30.00']];
    }

    /** A made-up document of a kind, for a company that has none yet. */
    protected function sample(string $kind = 'invoice'): array
    {
        $co = $this->docs->company();
        $party = ['name' => 'Sample Customer', 'address' => "12 Example Street\nSomewhere", 'phone' => '+000 000 000', 'email' => 'customer@example.com'];
        $cols = [['h' => 'Description', 'k' => 'description'], ['h' => 'Qty', 'k' => 'qty', 'align' => 'r', 'w' => '8%'], ['h' => 'Price', 'k' => 'price', 'align' => 'r', 'w' => '16%'], ['h' => 'Amount', 'k' => 'amount', 'align' => 'r', 'w' => '18%']];
        $rows = [['description' => 'First item', 'qty' => '2', 'price' => '$50.00', 'amount' => '$100.00'], ['description' => 'Second item', 'qty' => '1', 'price' => '$25.00', 'amount' => '$25.00']];
        $d = ['title' => 'INVOICE', 'kind' => 'invoice', 'number' => 'INV-0001', 'company' => $co, 'party_label' => 'Billed to', 'party' => $party,
            'meta' => [['Invoice #', 'INV-0001'], ['Date', date('d-M-Y')], ['Due', date('d-M-Y', strtotime('+14 days'))]], 'columns' => $cols, 'rows' => $rows,
            'totals' => [['Subtotal', '$125.00', false], ['Total', '$125.00', true]], 'total_raw' => '125.00', 'big' => [], 'notes' => 'Thank you for your business.', 'bank' => [], 'footer' => 'We appreciate your business!', 'stamp' => null, 'pad' => true];
        if ($kind === 'quotation') {
            $d['title'] = 'QUOTATION'; $d['kind'] = 'quotation'; $d['number'] = 'QUO-0001'; $d['party_label'] = 'Prepared for';
            $d['meta'] = [['Quotation #', 'QUO-0001'], ['Date', date('d-M-Y')], ['Valid until', date('d-M-Y', strtotime('+30 days'))]];
        } elseif ($kind === 'receipt') {
            $d['title'] = 'PAYMENT RECEIPT'; $d['kind'] = 'receipt'; $d['number'] = 'PAY-0001'; $d['party_label'] = 'Received from'; $d['pad'] = false;
            $d['meta'] = [['Receipt #', 'PAY-0001'], ['Date', date('d-M-Y')], ['Method', 'Cash']];
            $d['big'] = [['Amount received', '$125.00', true]];
            $d['columns'] = [['h' => 'Invoice', 'k' => 'invoice'], ['h' => 'Date', 'k' => 'date', 'w' => '18%'], ['h' => 'Invoice total', 'k' => 'total', 'align' => 'r', 'w' => '20%'], ['h' => 'Applied', 'k' => 'applied', 'align' => 'r', 'w' => '20%']];
            $d['rows'] = [['invoice' => 'INV-0001', 'date' => date('d-M-Y'), 'total' => '$125.00', 'applied' => '$125.00']];
            $d['totals'] = [['Applied to invoices', '$125.00', false]]; $d['total_raw'] = null; $d['footer'] = 'Thank you for your payment.';
        } elseif ($kind === 'statement') {
            $d['title'] = 'STATEMENT'; $d['kind'] = 'statement'; $d['number'] = ''; $d['party_label'] = 'Statement for'; $d['pad'] = false;
            $d['meta'] = [['Date', date('d-M-Y')], ['Period', 'Beginning to today']];
            $d['columns'] = [['h' => 'Date', 'k' => 'date', 'w' => '14%'], ['h' => 'Details', 'k' => 'details'], ['h' => 'Charges', 'k' => 'in', 'align' => 'r', 'w' => '15%'], ['h' => 'Payments', 'k' => 'out', 'align' => 'r', 'w' => '15%'], ['h' => 'Balance', 'k' => 'bal', 'align' => 'r', 'w' => '16%']];
            $d['rows'] = [['date' => '', 'details' => 'Opening balance', 'in' => '', 'out' => '', 'bal' => '$0.00', '_bold' => true], ['date' => date('d-M-Y', strtotime('-20 days')), 'details' => 'Invoice INV-0001', 'in' => '$125.00', 'out' => '', 'bal' => '$125.00'], ['date' => date('d-M-Y', strtotime('-5 days')), 'details' => 'Payment PAY-0001', 'in' => '', 'out' => '$50.00', 'bal' => '$75.00']];
            $d['totals'] = [['Balance owing', '$75.00', true]]; $d['total_raw'] = null; $d['notes'] = null;
        }

        return $d;
    }
}