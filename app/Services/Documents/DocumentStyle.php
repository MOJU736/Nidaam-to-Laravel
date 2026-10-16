<?php

namespace App\Services\Documents;

use App\Models\Tenant\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company's document look: which of the 11 designs it prints on, the colours, font, logo, signature, stamp and footer, the words
 * on the page, and which parts are shown. One look for every document (quotation, invoice, credit note, receipt, statement ...).
 * Ported from the legacy Sales_model template functions; settings live in document_template_settings (one row, id 1).
 *
 * Each design keeps its own saved look (style_json, keyed by the design number); a design nobody touched prints as made.
 */
class DocumentStyle
{
    /** The 11 designs: name, its own colours, and the page layout it uses (classic, card, band, ledger, center, sidebar, stripe, split, minimal, elegant, bold). */
    public const DESIGNS = [
        1 => ['name' => 'Classic blue', 'main' => '#1f3f99', 'second' => '#2f5fd0', 'row' => '#f7f9fc', 'layout' => 'classic'],
        2 => ['name' => 'Fresh green cards', 'main' => '#145a32', 'second' => '#1e8449', 'row' => '#f4faf6', 'layout' => 'card'],
        3 => ['name' => 'Warm amber band', 'main' => '#7e5109', 'second' => '#b9770e', 'row' => '#fbf6ee', 'layout' => 'band'],
        4 => ['name' => 'Black & white ledger', 'main' => '#111111', 'second' => '#222222', 'row' => '#f5f5f5', 'layout' => 'ledger'],
        5 => ['name' => 'Deep teal centred', 'main' => '#0b5345', 'second' => '#117a65', 'row' => '#f2f9f7', 'layout' => 'center'],
        6 => ['name' => 'Navy sidebar', 'main' => '#20304f', 'second' => '#33496f', 'row' => '#f4f6fa', 'layout' => 'sidebar'],
        7 => ['name' => 'Navy & orange', 'main' => '#1b3a5c', 'second' => '#f07c1d', 'row' => '#f4f7fb', 'layout' => 'stripe'],
        8 => ['name' => 'Blue & orange split', 'main' => '#1f5a85', 'second' => '#eb6c03', 'row' => '#d9e2f3', 'layout' => 'split'],
        9 => ['name' => 'Modern minimal', 'main' => '#0f766e', 'second' => '#14b8a6', 'row' => '#f0fdfa', 'layout' => 'minimal'],
        10 => ['name' => 'Charcoal & gold', 'main' => '#26262b', 'second' => '#b08d57', 'row' => '#f8f5ef', 'layout' => 'elegant', 'font' => 'serif'],
        11 => ['name' => 'Royal bold', 'main' => '#1d4ed8', 'second' => '#1e3a8a', 'row' => '#eff4ff', 'layout' => 'bold'],
    ];

    public const FONTS = [
        'sans' => ['Clean (sans-serif)', "'DejaVu Sans', sans-serif"],
        'serif' => ['Classic (serif)', "'DejaVu Serif', serif"],
        'modern' => ['Modern (Helvetica; Latin letters only)', "Helvetica, 'DejaVu Sans', sans-serif"],
        'mono' => ['Typewriter', "'DejaVu Sans Mono', monospace"],
    ];

    /** Parts that can be hidden: key => label. */
    public const PARTS = ['notes' => 'Notes', 'payment' => 'Payment information', 'signature' => 'Signature', 'stamp' => 'Stamp', 'received' => '"Received by" box', 'contact' => 'Footer contact line', 'words' => 'Amount in words'];

    /** Words that can be changed: key => [label, the usual text]. */
    public const WORDS = [
        'title_invoice' => ['Invoice title', 'INVOICE'], 'title_quotation' => ['Quotation title', 'QUOTATION'], 'title_credit' => ['Credit note title', 'CREDIT NOTE'],
        'title_receipt' => ['Receipt title', 'PAYMENT RECEIPT'], 'title_statement' => ['Statement title', 'STATEMENT'],
        'w_desc' => ['Description column', 'Description'], 'w_qty' => ['Quantity column', 'Qty'], 'w_price' => ['Price column', 'Price'], 'w_amount' => ['Amount column', 'Amount'],
        'w_total' => ['Total line', 'Total'], 'w_sign' => ['Signature label', 'Authorized Signature'], 'w_received' => ['"Received by" label', 'Received by'],
    ];

    protected function db()
    {
        return DB::connection('tenant');
    }

    /** Older company databases may have no template settings table; made on first use (outside any transaction). */
    public function ensureTable(): void
    {
        $schema = Schema::connection('tenant');
        if (! $schema->hasTable('document_template_settings')) {
            $schema->create('document_template_settings', function (Blueprint $t) {
                $t->integer('id')->primary();
                $t->integer('template_id')->default(1);
                $t->boolean('template_default_applied')->default(0);
                foreach (range(1, 5) as $i) {
                    $t->string("cf{$i}_label", 100)->nullable();
                    $t->string("cf{$i}_docs", 50)->nullable();
                }
                foreach (['signature_image' => 255, 'stamp_image' => 255, 'signature_name' => 150, 'signature_title' => 150, 'footer_address' => 255, 'footer_phone' => 100, 'footer_email' => 150,
                    'footer_website' => 150, 'qty_label' => 30, 'price_label' => 30, 'payment_extra' => 1000, 'footer_note' => 255] as $col => $len) {
                    $t->string($col, $len)->nullable();
                }
                $t->text('style_json')->nullable();
                $t->boolean('template_retrofit_applied')->default(0);
            });
        }
    }

    public function settings(): object
    {
        $saved = \App\Support\Tables::has('document_template_settings') ? $this->db()->table('document_template_settings')->where('id', 1)->first() : null;

        return $saved ?? (object) ['id' => 1, 'template_id' => 1, 'style_json' => null, 'signature_image' => null, 'stamp_image' => null, 'signature_name' => null,
            'signature_title' => null, 'footer_address' => null, 'footer_phone' => null, 'footer_email' => null, 'footer_website' => null, 'qty_label' => null, 'price_label' => null, 'payment_extra' => null, 'footer_note' => null];
    }

    /** The five custom fields that have a name: number => [label, [document kinds they print on]]. Kinds: invoice, quotation. */
    public function customFields(?object $s = null): array
    {
        $s ??= $this->settings();
        $out = [];
        foreach (range(1, 5) as $i) {
            $label = trim((string) ($s->{"cf{$i}_label"} ?? ''));
            if ($label !== '') {
                $out[$i] = [$label, array_values(array_filter(explode(',', (string) ($s->{"cf{$i}_docs"} ?? ''))))];
            }
        }

        return $out;
    }

    public function save(array $row): void
    {
        $q = $this->db()->table('document_template_settings')->where('id', 1);
        $q->exists() ? $q->update($row) : $this->db()->table('document_template_settings')->insert(['id' => 1] + $row);
    }

    /** Every design's saved look: design number => style. */
    public function styles(?object $s = null): array
    {
        $s ??= $this->settings();
        $all = ! empty($s->style_json) ? json_decode($s->style_json, true) : [];

        return is_array($all) ? $all : [];
    }

    /** Keeps only known options with safe values. */
    public function clean(array $in): array
    {
        $out = [];
        foreach (['c_main', 'c_second', 'c_head', 'c_row'] as $k) {
            if (! empty($in[$k]) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $in[$k])) {
                $out[$k] = strtolower($in[$k]);
            }
        }
        if (! empty($in['font']) && isset(self::FONTS[$in['font']])) {
            $out['font'] = $in['font'];
        }
        if (! empty($in['size']) && in_array($in['size'], ['small', 'large'], true)) {
            $out['size'] = $in['size'];
        }
        if (! empty($in['logo_size']) && in_array($in['logo_size'], ['s', 'm', 'l'], true)) {
            $out['logo_size'] = $in['logo_size'];
        }
        if (! empty($in['logo_pos']) && in_array($in['logo_pos'], ['left', 'center', 'right'], true)) {
            $out['logo_pos'] = $in['logo_pos'];
        }
        if (isset($in['rows']) && $in['rows'] !== '' && is_numeric($in['rows'])) {
            $out['rows'] = max(0, min(8, (int) $in['rows']));
        }
        // signature and stamp: size in percent, moved by pixels; footer: size, move, alignment
        foreach (['sig_size' => [40, 220, 100], 'stamp_size' => [40, 220, 100], 'foot_size' => [70, 140, 100], 'sig_x' => [-250, 250, 0], 'sig_y' => [-150, 150, 0], 'stamp_x' => [-250, 250, 0], 'stamp_y' => [-150, 150, 0], 'foot_y' => [-20, 80, 0]] as $k => [$lo, $hi, $same]) {
            if (isset($in[$k]) && $in[$k] !== '' && is_numeric($in[$k])) {
                $v = max($lo, min($hi, (int) $in[$k]));
                if ($v !== $same) {
                    $out[$k] = $v;
                }
            }
        }
        if (! empty($in['foot_align']) && in_array($in['foot_align'], ['left', 'center', 'right'], true)) {
            $out['foot_align'] = $in['foot_align'];
        }
        $hide = array_values(array_unique(array_filter((array) ($in['hide'] ?? []), fn ($h) => isset(self::PARTS[$h]))));
        if ($hide) {
            $out['hide'] = $hide;
        }
        foreach (self::WORDS as $k => $w) {
            if (isset($in[$k]) && trim((string) $in[$k]) !== '') {
                $out[$k] = mb_substr(trim((string) $in[$k]), 0, 40);
            }
        }

        return $out;
    }

    public function styleFor(int $design, ?object $s = null): array
    {
        $all = $this->styles($s);

        return isset($all[(string) $design]) && is_array($all[(string) $design]) ? $this->clean($all[(string) $design]) : [];
    }

    /** A saved image as a data: address dompdf can embed, from public/branding/<folder>/<file>. Null if there is none. */
    protected function image(string $folder, ?string $file): ?string
    {
        $file = basename((string) $file);
        if ($file === '') {
            return null;
        }
        $path = public_path("branding/$folder/$file");
        if (! is_file($path)) {
            return null;
        }
        $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {'jpg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', default => 'image/png'};

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }

    /**
     * Everything the page needs to dress a document: design, layout, colours, font, images, footer, words and hidden parts.
     * $override replaces the saved look (the Preview window); $design replaces the saved design number.
     */
    public function resolve(?array $override = null, ?int $design = null, ?object $settings = null): array
    {
        $s = $settings ?? $this->settings();
        $tid = $design ?? (int) ($s->template_id ?: 1);
        if (! isset(self::DESIGNS[$tid])) {
            $tid = 1;
        }
        $base = self::DESIGNS[$tid];
        $st = $override !== null ? $this->clean($override) : $this->styleFor($tid, $s);
        $company = Setting::first();
        // the preview can show a company logo that is not saved yet
        if ($settings !== null && isset($settings->_logo)) {
            $company = (object) ($company ? $company->getAttributes() : []);
            $company->logo = $settings->_logo;
        }
        $fontKey = $st['font'] ?? ($base['font'] ?? 'sans');
        $logoPx = ['s' => 70, 'm' => 105, 'l' => 150][$st['logo_size'] ?? 'm'];
        $blank = fn ($v) => isset($v) && trim((string) $v) !== '' ? trim((string) $v) : null;

        return [
            'design' => $tid, 'layout' => $base['layout'], 'name' => $base['name'],
            'main' => $st['c_main'] ?? $base['main'], 'second' => $st['c_second'] ?? $base['second'], 'head' => $st['c_head'] ?? ($st['c_second'] ?? $base['second']), 'row' => $st['c_row'] ?? $base['row'],
            'font' => self::FONTS[$fontKey][1], 'fs' => ['small' => 10.5, 'large' => 13][$st['size'] ?? ''] ?? 11.5,
            'logo' => $this->image('logo', $company->logo ?? null), 'logo_px' => $logoPx, 'logo_pos' => $st['logo_pos'] ?? 'left',
            'signature' => $this->image('signature', $s->signature_image ?? null), 'stamp' => $this->image('stamp', $s->stamp_image ?? null),
            'signature_name' => $blank($s->signature_name ?? null), 'signature_title' => $blank($s->signature_title ?? null),
            'sig' => ['size' => $st['sig_size'] ?? 100, 'x' => $st['sig_x'] ?? 0, 'y' => $st['sig_y'] ?? 0], 'stampbox' => ['size' => $st['stamp_size'] ?? 100, 'x' => $st['stamp_x'] ?? 0, 'y' => $st['stamp_y'] ?? 0],
            'footer' => ['address' => $blank($s->footer_address ?? null) ?? $blank($company->address ?? null), 'phone' => $blank($s->footer_phone ?? null), 'email' => $blank($s->footer_email ?? null) ?? $blank($company->email ?? null),
                'website' => $blank($s->footer_website ?? null), 'align' => $st['foot_align'] ?? 'center', 'size' => $st['foot_size'] ?? 100, 'y' => $st['foot_y'] ?? 0],
            'footer_note' => $blank($s->footer_note ?? null),
            'payment_extra' => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($s->payment_extra ?? ''))), 'strlen')),
            'qty_label' => $blank($s->qty_label ?? null), 'price_label' => $blank($s->price_label ?? null),
            'hide' => $st['hide'] ?? [], 'words' => array_intersect_key($st, self::WORDS), 'rows' => $st['rows'] ?? null,
        ];
    }

    /** Colour defaults of a design, for the pickers. */
    public function defaults(int $design): array
    {
        $b = self::DESIGNS[$design] ?? self::DESIGNS[1];

        return ['c_main' => $b['main'], 'c_second' => $b['second'], 'c_head' => $b['second'], 'c_row' => $b['row']];
    }
}
