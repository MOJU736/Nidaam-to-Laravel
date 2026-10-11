<?php

namespace App\Services\Documents;

use App\Models\Tenant\Customer;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Vendor;
use App\Services\Credits\CreditService;
use App\Services\Parties\PartyService;
use App\Services\Purchases\PurchasesService;
use App\Services\Sales\Allocations;
use App\Services\Sales\SalesService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Builds the printable / emailable form of every document: invoices, quotations, credit notes and supplier credits, payment
 * receipts, bills, purchase orders, vendor remittance advice and customer / vendor statements. Each is described as one array
 * ($d) that the pdf.document page lays out, so every document shares one clean look.
 *
 * `build($type, $id, $opts)` returns ['d' => data, 'file' => file name, 'party' => the party row, 'mail' => defaults for the email form].
 */
class DocumentService
{
    /** Document types and their words. */
    public const TYPES = ['invoice', 'quotation', 'credit-note', 'supplier-credit', 'receipt', 'bill', 'purchase-order', 'remittance', 'customer-statement', 'vendor-statement'];

    /** Types that can be emailed (bills and purchase orders are ours, not something we send to the vendor). */
    public const EMAILABLE = ['invoice', 'quotation', 'credit-note', 'supplier-credit', 'receipt', 'remittance', 'customer-statement', 'vendor-statement'];

    public function __construct(
        protected SalesService $sales, protected PurchasesService $purchases, protected CreditService $credits, protected Allocations $allocs,
        protected PartyService $parties, protected PdfService $pdf,
    ) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    public function company(): array
    {
        $s = Setting::first();

        return ['name' => (string) ($s->name ?? ''), 'address' => (string) ($s->address ?? ''), 'email' => (string) ($s->email ?? '')];
    }

    protected function m($v): string
    {
        return Money::format($v);
    }

    protected function qty($q): string
    {
        return rtrim(rtrim(number_format((float) $q, 2, '.', ','), '0'), '.');
    }

    protected function bank(): array
    {
        $s = $this->sales->settings();
        $out = [];
        foreach ([['Bank', $s->bank_name ?? null], ['Account name', $s->bank_account_name ?? null], ['IBAN', $s->bank_iban ?? null], ['Account number', $s->bank_account_number ?? null]] as [$l, $v]) {
            if (! empty($v)) {
                $out[] = [$l, $v];
            }
        }

        return $out;
    }

    /** Lines of an invoice / quotation / bill / credit: description, qty, price, amount (after the line's own discount). */
    protected function lineRows($items): array
    {
        return collect($items)->map(function ($i) {
            $disc = Money::positive($i->discount_amount ?? 0);
            $net = Money::sub($i->amount, $i->discount_amount ?? 0);

            return ['description' => $i->description, 'sub' => $disc ? (($i->discount_type ?? '') === 'percent' ? rtrim(rtrim((string) $i->discount_value, '0'), '.').'% off' : $this->m($i->discount_amount).' off') : null,
                'qty' => $this->qty($i->qty), 'price' => $this->m($i->unit_price), 'amount' => $this->m($net)];
        })->all();
    }

    protected const LINE_COLUMNS = [['h' => 'Description', 'k' => 'description'], ['h' => 'Qty', 'k' => 'qty', 'align' => 'r', 'w' => '8%'], ['h' => 'Price', 'k' => 'price', 'align' => 'r', 'w' => '16%'], ['h' => 'Amount', 'k' => 'amount', 'align' => 'r', 'w' => '18%']];

    /** Subtotal, discount, tax and total rows. */
    protected function totalRows(object $doc, string $totalLabel): array
    {
        $rows = [['Subtotal', $this->m($doc->subtotal), false]];
        if (Money::positive($doc->discount_amount ?? 0)) {
            $rows[] = ['Discount'.((($doc->discount_type ?? '') === 'percent') ? ' ('.rtrim(rtrim((string) $doc->discount_value, '0'), '.').'%)' : ''), '-'.$this->m($doc->discount_amount), false];
        }
        if (Money::positive($doc->tax_total ?? 0)) {
            $rows[] = ['Tax'.(($doc->tax_percent ?? null) !== null ? ' ('.rtrim(rtrim((string) $doc->tax_percent, '0'), '.').'%)' : ''), $this->m($doc->tax_total), false];
        }
        $rows[] = [$totalLabel, $this->m($doc->total), true];

        return $rows;
    }

    protected function party(object $o, string $prefix): array
    {
        return ['name' => $o->{$prefix.'_name'}, 'address' => $o->{$prefix.'_address'} ?? null, 'phone' => $o->{$prefix.'_phone'} ?? null, 'email' => $o->{$prefix.'_email'} ?? null];
    }

    protected function base(string $title, string $number): array
    {
        return ['title' => $title, 'number' => $number, 'company' => $this->company(), 'party_label' => '', 'party' => null, 'meta' => [], 'columns' => [], 'rows' => [], 'totals' => [], 'big' => [], 'notes' => null, 'bank' => [], 'footer' => null, 'stamp' => null];
    }

    // ---- the documents ------------------------------------------------------------------

    /** @return array{d: array, file: string, party: ?object, mail: array}|null */
    public function build(string $type, int $id, array $opts = []): ?array
    {
        $built = match ($type) {
            'invoice' => $this->invoice($id),
            'quotation' => $this->quotation($id),
            'credit-note' => $this->credit('ar', $id),
            'supplier-credit' => $this->credit('ap', $id),
            'receipt' => $this->receipt($id),
            'bill' => $this->bill($id),
            'purchase-order' => $this->purchaseOrder($id),
            'remittance' => $this->remittance($id),
            'customer-statement' => $this->statement('ar', $id, $opts),
            'vendor-statement' => $this->statement('ap', $id, $opts),
            default => null,
        };
        if ($built) {
            $built['d']['kind'] = ['invoice' => 'invoice', 'quotation' => 'quotation', 'credit-note' => 'credit', 'supplier-credit' => 'credit', 'receipt' => 'receipt', 'bill' => 'bill', 'purchase-order' => 'po', 'remittance' => 'remittance', 'customer-statement' => 'statement', 'vendor-statement' => 'statement'][$type] ?? 'other';
            $built['d']['pad'] = in_array($type, ['invoice', 'quotation'], true);
        }

        return $built;
    }

    protected function mail(string $co, string $title, string $label, string $name, ?string $email, string $extra, string $table, int $partyId): array
    {
        return ['to' => (string) $email, 'subject' => trim("$title $label")." from $co", 'message' => "Hello $name,\n\nPlease find attached $extra.\n\nThank you,\n$co", 'party_table' => $table, 'party_id' => $partyId, 'has_email' => (bool) $email];
    }

    /** The custom fields (Settings → Templates) set to print on this kind of document and filled in on it, as meta rows. */
    protected function customMeta(object $doc, string $kind): array
    {
        $rows = [];
        foreach (app(DocumentStyle::class)->customFields() as $i => [$label, $docs]) {
            $value = trim((string) ($doc->{"cf{$i}_value"} ?? ''));
            if ($value !== '' && in_array($kind, $docs, true)) {
                $rows[] = [$label, $value];
            }
        }

        return $rows;
    }

    public function invoice(int $id): ?array
    {
        $inv = $this->sales->invoice($id);
        if (! $inv) {
            return null;
        }
        $label = $this->sales->label('invoice', $inv->number);
        $d = $this->base('INVOICE', $label);
        $d['party_label'] = 'Billed to';
        $d['party'] = $this->party($inv, 'customer');
        $d['meta'] = array_filter([['Invoice #', $label], ['Date', Money::date($inv->date)], $inv->due_date ? ['Due', Money::date($inv->due_date)] : null]);
        $d['meta'] = array_merge(array_values($d['meta']), $this->customMeta($inv, 'invoice'));
        $d['columns'] = self::LINE_COLUMNS;
        $d['rows'] = $this->lineRows($inv->items);
        $d['totals'] = $this->totalRows($inv, 'Total');
        if ($inv->status === 'issued') {
            $dec = collect($this->allocs->decorate('ar', [$inv]))->first();
            if (Money::positive($dec->paid)) {
                $d['totals'][] = ['Paid', $this->m($dec->paid), false];
                $d['totals'][] = ['Balance due', $this->m($dec->balance_due), true];
            }
            $d['stamp'] = ! Money::positive($dec->balance_due) ? 'PAID' : null;
        } elseif ($inv->status === 'void') {
            $d['stamp'] = 'VOID';
        }
        $d['notes'] = $inv->notes;
        $d['total_raw'] = $inv->total;
        $d['bank'] = $this->bank();
        $d['footer'] = 'We appreciate your business and look forward to serving you!';
        $co = $d['company']['name'];

        return ['d' => $d, 'file' => "Invoice $label.pdf", 'party' => $this->db()->table('customers')->where('id', $inv->customer_id)->first(),
            'mail' => $this->mail($co, 'Invoice', $label, $inv->customer_name, $inv->customer_email, "your invoice $label for ".$this->m($inv->total).($inv->due_date ? ', due '.Money::date($inv->due_date) : ''), 'customers', (int) $inv->customer_id)];
    }

    public function quotation(int $id): ?array
    {
        $q = $this->sales->quotation($id);
        if (! $q) {
            return null;
        }
        $label = $this->sales->label('quotation', $q->number);
        $d = $this->base('QUOTATION', $label);
        $d['party_label'] = 'Prepared for';
        $d['party'] = $this->party($q, 'customer');
        $d['meta'] = array_filter([['Quotation #', $label], ['Date', Money::date($q->date)], $q->expiry_date ? ['Valid until', Money::date($q->expiry_date)] : null]);
        $d['meta'] = array_merge(array_values($d['meta']), $this->customMeta($q, 'quotation'));
        $d['columns'] = self::LINE_COLUMNS;
        $d['rows'] = $this->lineRows($q->items);
        $d['totals'] = $this->totalRows($q, 'Total');
        $d['notes'] = $q->notes;
        $d['total_raw'] = $q->total;
        $d['footer'] = 'Thank you for the opportunity to quote.';

        return ['d' => $d, 'file' => "Quotation $label.pdf", 'party' => $this->db()->table('customers')->where('id', $q->customer_id)->first(),
            'mail' => $this->mail($d['company']['name'], 'Quotation', $label, $q->customer_name, $q->customer_email, "our quotation $label for ".$this->m($q->total), 'customers', (int) $q->customer_id)];
    }

    public function credit(string $side, int $id): ?array
    {
        $cn = $this->credits->get($side, $id);
        if (! $cn) {
            return null;
        }
        $c = $this->credits->cfg($side);
        $wo = $cn->kind === 'writeoff';
        $title = $wo ? 'WRITE-OFF' : strtoupper($c['name']);
        $d = $this->base($title, $cn->label);
        $d['party_label'] = $side === 'ar' ? 'Credited to' : 'From';
        $d['party'] = ['name' => $cn->party_name, 'address' => $cn->party_address, 'email' => $cn->party_email, 'phone' => null];
        $d['meta'] = array_filter([[($wo ? 'Write-off' : $c['name']).' #', $cn->label], ['Date', Money::date($cn->date)], $cn->source_label ? ['For', $cn->source_label] : null]);
        $d['columns'] = self::LINE_COLUMNS;
        $d['rows'] = $this->lineRows($cn->items);
        $d['totals'] = $this->totalRows($cn, 'Total credit');
        $d['notes'] = $cn->notes;
        $d['stamp'] = $cn->status === 'void' ? 'VOID' : null;
        $d['total_raw'] = $cn->total;
        $table = $c['party_table'];
        $what = $wo ? 'write-off' : strtolower($c['name']);

        return ['d' => $d, 'file' => ($wo ? 'Write-off ' : $c['name'].' ').$cn->label.'.pdf', 'party' => $this->db()->table($table)->where('id', $cn->party_id)->first(),
            'mail' => $this->mail($d['company']['name'], ucfirst($what), $cn->label, $cn->party_name, $cn->party_email, ($side === 'ar' ? 'your ' : 'our ').$what.' '.$cn->label.($cn->source_label ? ' for '.$cn->source_label : '').' for '.$this->m($cn->total), $table, (int) $cn->party_id)];
    }

    public function receipt(int $id): ?array
    {
        $p = $this->sales->payment($id);
        if (! $p) {
            return null;
        }
        $label = Allocations::docLabel('', $p->number);
        $cust = $this->db()->table('customers')->where('id', $p->customer_id)->first();
        $apps = $this->allocs->forSource('ar', $id);
        $applied = array_reduce($apps, fn ($c, $a) => Money::add($c, $a->amount), '0.00');
        $refunded = $this->allocs->refundedBySource('ar', [$id], 'pay')[$id] ?? 0;
        $left = $this->allocs->available('ar', 'pay', $id);
        $d = $this->base('PAYMENT RECEIPT', $label);
        $d['party_label'] = 'Received from';
        $d['party'] = ['name' => $p->customer_name, 'address' => $cust->address ?? null, 'phone' => $cust->phone ?? null, 'email' => $cust->email ?? null];
        $d['meta'] = array_filter([['Receipt #', $label], ['Date', Money::date($p->date)], $p->method ? ['Method', $p->method] : null, $p->reference ? ['Reference', $p->reference] : null]);
        $d['big'] = [['Amount received', $this->m($p->amount), true]];
        if (Money::positive($p->wht_amount ?? 0)) {
            $d['big'][] = ['Tax withheld by the customer', $this->m($p->wht_amount), false];
        }
        $d['columns'] = [['h' => 'Invoice', 'k' => 'invoice'], ['h' => 'Date', 'k' => 'date', 'w' => '18%'], ['h' => 'Invoice total', 'k' => 'total', 'align' => 'r', 'w' => '20%'], ['h' => 'Applied', 'k' => 'applied', 'align' => 'r', 'w' => '20%']];
        $d['rows'] = array_map(fn ($a) => ['invoice' => $a->label, 'date' => Money::date($a->date), 'total' => $this->m($a->total), 'applied' => $this->m($a->amount)], $apps);
        $d['empty'] = 'This payment has not been applied to an invoice.';
        $d['totals'] = array_filter([['Applied to invoices', $this->m($applied), false], Money::positive($refunded) ? ['Refunded', $this->m($refunded), false] : null, $p->status === 'received' ? ['Kept as your credit', $this->m($left), true] : null]);
        $d['stamp'] = $p->status === 'void' ? 'VOID' : null;
        $d['footer'] = 'Thank you for your payment.';

        return ['d' => $d, 'file' => "Receipt $label.pdf", 'party' => $cust,
            'mail' => $this->mail($d['company']['name'], 'Payment receipt', $label, $p->customer_name, $cust->email ?? null, "your receipt $label for the payment of ".$this->m($p->amount).' received on '.Money::date($p->date), 'customers', (int) $p->customer_id)];
    }

    public function bill(int $id): ?array
    {
        $b = $this->purchases->bill($id);
        if (! $b) {
            return null;
        }
        $label = $this->purchases->billLabel($b->number);
        $d = $this->base('BILL', $label);
        $d['party_label'] = 'From';
        $d['party'] = $this->party($b, 'vendor');
        $d['meta'] = array_filter([['Bill #', $label], $b->vendor_ref ? ['Their reference', $b->vendor_ref] : null, ['Date', Money::date($b->date)], $b->due_date ? ['Due', Money::date($b->due_date)] : null]);
        $d['columns'] = self::LINE_COLUMNS;
        $d['rows'] = $this->lineRows($b->items);
        $d['totals'] = $this->totalRows($b, 'Total');
        if ($b->status === 'issued') {
            $dec = collect($this->allocs->decorate('ap', [$b]))->first();
            if (Money::positive($dec->paid)) {
                $d['totals'][] = ['Paid', $this->m($dec->paid), false];
                $d['totals'][] = ['Balance due', $this->m($dec->balance_due), true];
            }
        }
        $d['stamp'] = $b->status === 'void' ? 'VOID' : null;
        $d['notes'] = $b->notes;

        return ['d' => $d, 'file' => "Bill $label.pdf", 'party' => $this->db()->table('vendors')->where('id', $b->vendor_id)->first(), 'mail' => []];
    }

    public function purchaseOrder(int $id): ?array
    {
        $p = $this->purchases->purchaseOrder($id);
        if (! $p) {
            return null;
        }
        $label = $this->purchases->poLabel($p->number);
        $d = $this->base('PURCHASE ORDER', $label);
        $d['party_label'] = 'To';
        $d['party'] = $this->party($p, 'vendor');
        $d['meta'] = [['Order #', $label], ['Date', Money::date($p->date)]];
        $d['columns'] = self::LINE_COLUMNS;
        $d['rows'] = $this->lineRows($p->items);
        $d['totals'] = $this->totalRows($p, 'Total');
        $d['notes'] = $p->notes;

        return ['d' => $d, 'file' => "Purchase order $label.pdf", 'party' => $this->db()->table('vendors')->where('id', $p->vendor_id)->first(), 'mail' => []];
    }

    /** Remittance advice: tells the vendor what a payment to them was for. */
    public function remittance(int $id): ?array
    {
        $p = $this->purchases->payment($id);
        if (! $p) {
            return null;
        }
        $label = Allocations::docLabel('', $p->number);
        $v = $this->db()->table('vendors')->where('id', $p->vendor_id)->first();
        $apps = $this->allocs->forSource('ap', $id);
        $d = $this->base('REMITTANCE ADVICE', $label);
        $d['party_label'] = 'Paid to';
        $d['party'] = ['name' => $p->vendor_name, 'address' => $v->address ?? null, 'phone' => $v->phone ?? null, 'email' => $v->email ?? null];
        $d['meta'] = array_filter([['Payment #', $label], ['Date', Money::date($p->date)], $p->method ? ['Method', $p->method] : null, $p->reference ? ['Reference', $p->reference] : null]);
        $d['big'] = [['Amount paid', $this->m($p->amount), true]];
        $d['columns'] = [['h' => 'Bill', 'k' => 'bill'], ['h' => 'Date', 'k' => 'date', 'w' => '18%'], ['h' => 'Bill total', 'k' => 'total', 'align' => 'r', 'w' => '20%'], ['h' => 'Paid', 'k' => 'paid', 'align' => 'r', 'w' => '20%']];
        $d['rows'] = array_map(fn ($a) => ['bill' => $a->label, 'date' => Money::date($a->date), 'total' => $this->m($a->total), 'paid' => $this->m($a->amount)], $apps);
        $d['empty'] = 'This payment was not applied to a specific bill.';
        $d['stamp'] = $p->status === 'void' ? 'VOID' : null;
        $d['footer'] = 'Thank you for your business.';

        return ['d' => $d, 'file' => "Remittance $label.pdf", 'party' => $v,
            'mail' => $this->mail($d['company']['name'], 'Remittance advice', $label, $p->vendor_name, $v->email ?? null, 'the remittance advice for our payment of '.$this->m($p->amount).' made on '.Money::date($p->date), 'vendors', (int) $p->vendor_id)];
    }

    /** A customer's / vendor's statement of account for a period, with a running balance. $opts: start, end (Y-m-d). */
    public function statement(string $side, int $id, array $opts = []): ?array
    {
        $c = $this->parties->config($side);
        $party = $c['party']::find($id);
        if (! $party) {
            return null;
        }
        $start = $opts['start'] ?? null;
        $end = $opts['end'] ?? null;
        $st = $this->parties->statement($side, $party, $start, $end);
        $d = $this->base('STATEMENT', '');
        $d['party_label'] = $side === 'ar' ? 'Statement for' : 'Vendor';
        $d['party'] = ['name' => $party->name, 'address' => $party->address, 'phone' => $party->phone, 'email' => $party->email];
        $d['meta'] = [['Date', Money::date(now())], ['Period', ($start ? Money::date($start) : 'Beginning').' to '.($end ? Money::date($end) : 'today')]];
        $in = $side === 'ar' ? 'Charges' : 'Bills';
        $d['columns'] = [['h' => 'Date', 'k' => 'date', 'w' => '14%'], ['h' => 'Details', 'k' => 'details'], [ 'h' => $in, 'k' => 'in', 'align' => 'r', 'w' => '15%'], ['h' => 'Payments', 'k' => 'out', 'align' => 'r', 'w' => '15%'], ['h' => 'Balance', 'k' => 'bal', 'align' => 'r', 'w' => '16%']];
        $signed = fn ($b) => Money::signed($b, $c['normal']);
        $rows = [['date' => '', 'details' => 'Opening balance', 'in' => '', 'out' => '', 'bal' => $this->m($signed($st['opening'])), '_bold' => true]];
        foreach ($st['transactions'] as $t) {
            $isIn = $t['dc'] === $c['normal'];
            $rows[] = ['date' => Money::date($t['date']), 'details' => trim($t['label'].($t['narration'] ? ' - '.$t['narration'] : '')), 'in' => $isIn ? $this->m($t['amount']) : '', 'out' => $isIn ? '' : $this->m($t['amount']),
                'bal' => $t['running'] === null ? '' : $this->m($t['running'])];
        }
        $d['rows'] = $rows;
        $d['totals'] = [[$side === 'ar' ? 'Balance owing' : 'Balance we owe', $this->m($signed($st['closing'])), true]];
        $label = $side === 'ar' ? 'Statement of account' : 'Vendor statement';

        return ['d' => $d, 'file' => "Statement {$party->name}.pdf", 'party' => $party,
            'mail' => $this->mail($d['company']['name'], $label, '', $party->name, $party->email, 'your statement of account, showing a balance of '.$this->m($signed($st['closing'])), $c['route'], (int) $party->id)];
    }

    // ---- output ---------------------------------------------------------------------------

    /** The PDF bytes for a built document. */
    public function pdfBytes(array $built): string
    {
        return $this->pdf->fromHtml(view('pdf.document', ['d' => $built['d'], 'st' => app(DocumentStyle::class)->resolve()])->render());
    }

    /** A table of rows (the same rows a report downloads as CSV) as a PDF. First cells of single-cell rows are headings. */
    public function tablePdf(array $rows, string $orientation = 'portrait'): string
    {
        return $this->pdf->fromHtml(view('pdf.table', ['rows' => $rows])->render(), 'a4', $orientation);
    }
}
