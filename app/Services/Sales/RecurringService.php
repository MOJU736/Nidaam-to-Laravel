<?php

namespace App\Services\Sales;

use App\Services\Accounting\ActivityLog;
use App\Support\Money;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Invoices that repeat on a schedule (rent, retainers, monthly service contracts). Ported from the legacy Recurring_model.
 *
 * When one comes due it is created as a DRAFT invoice - you check it and issue it by hand; nothing reaches the books or an
 * inbox on its own. A template is made from an existing invoice ("Make recurring"), then keeps the customer, lines, discount,
 * tax and notes. There is no guaranteed scheduler on shared hosting, so due invoices are created whenever someone opens the
 * Invoices list, the Recurring list or the Dashboard (runDue()), and by `php artisan recurring:run` where a scheduler exists:
 * every occurrence that has come due since the last run is created, dated on its own due date, so nothing is skipped.
 */
class RecurringService
{
    /** At most this many invoices per template per run - protects against a typo like a start date years ago. */
    const MAX_CATCH_UP = 24;

    public function __construct(protected SalesService $sales) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    /** Older company databases have no recurring tables (or invoices.recurring_id) yet; made the first time. Call outside a transaction. */
    public function ensureSchema(): void
    {
        static $done = [];
        $key = config('database.connections.tenant.database');
        if (isset($done[$key])) {
            return;
        }
        $schema = Schema::connection('tenant');
        if (! $schema->hasTable('recurring_invoices')) {
            $schema->create('recurring_invoices', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('name', 150);
                $t->bigInteger('customer_id');
                $t->string('frequency', 10)->default('monthly');
                $t->integer('every')->default(1);
                $t->integer('anchor_day')->default(1);
                $t->date('start_date');
                $t->date('next_date')->nullable();
                $t->date('end_date')->nullable();
                $t->integer('due_days')->nullable();
                $t->string('notes', 500)->nullable();
                $t->decimal('tax_percent', 6, 3)->nullable();
                $t->decimal('tax_total', 25, 2)->nullable();
                $t->string('discount_type', 10)->nullable();
                $t->decimal('discount_value', 25, 4)->nullable();
                $t->string('status', 10)->default('active');
                $t->integer('created_count')->default(0);
                $t->bigInteger('last_invoice_id')->nullable();
                $t->dateTime('last_run_at')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->index(['status', 'next_date'], 'status_next');
            });
        }
        if (! $schema->hasTable('recurring_invoice_items')) {
            $schema->create('recurring_invoice_items', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->bigInteger('recurring_id');
                $t->bigInteger('item_id')->nullable();
                $t->string('description', 255);
                $t->decimal('qty', 15, 2)->default(1);
                $t->decimal('unit_price', 25, 2)->default(0);
                $t->string('discount_type', 10)->nullable();
                $t->decimal('discount_value', 25, 4)->nullable();
                $t->integer('sort_order')->default(0);
                $t->index(['recurring_id'], 'recurring_id');
            });
        }
        if ($schema->hasTable('invoices') && ! $schema->hasColumn('invoices', 'recurring_id')) {
            $schema->table('invoices', fn (Blueprint $t) => $t->bigInteger('recurring_id')->nullable());
        }
        $done[$key] = true;
    }

    public const FREQUENCIES = ['weekly' => 'Week', 'monthly' => 'Month', 'quarterly' => 'Quarter (3 months)', 'yearly' => 'Year'];

    /** "Every month", "Every 2 weeks", "Every quarter" ... */
    public function scheduleText(object|array $r): string
    {
        $r = (object) $r;
        $every = max(1, (int) $r->every);
        $words = ['weekly' => ['week', 'weeks'], 'monthly' => ['month', 'months'], 'quarterly' => ['quarter', 'quarters'], 'yearly' => ['year', 'years']];
        $w = $words[$r->frequency] ?? ['month', 'months'];

        return $every === 1 ? 'Every '.$w[0] : "Every $every ".$w[1];
    }

    /** The occurrence after $date. Months keep to the day of the month the schedule started on (the 31st becomes the last day of shorter months, then goes back to the 31st). */
    public function nextOccurrence(string $date, string $frequency, int $every, int $anchorDay): string
    {
        $every = max(1, $every);
        if ($frequency === 'weekly') {
            return date('Y-m-d', strtotime($date.' +'.(7 * $every).' days'));
        }
        $months = $every * ($frequency === 'yearly' ? 12 : ($frequency === 'quarterly' ? 3 : 1));
        $y = (int) substr($date, 0, 4);
        $m = (int) substr($date, 5, 2) + $months;
        $y += intdiv($m - 1, 12);
        $m = (($m - 1) % 12) + 1;
        $last = (int) date('t', mktime(0, 0, 0, $m, 1, $y));

        return sprintf('%04d-%02d-%02d', $y, $m, min(max(1, $anchorDay), $last));
    }

    // ---- reading ----------------------------------------------------------------------

    /** Template lines in the shape SalesService::saveInvoice() takes. */
    protected function invoiceLines($lines): array
    {
        return collect($lines)->map(fn ($l) => ['item_id' => $l->item_id, 'description' => $l->description, 'qty' => $l->qty, 'unit_price' => $l->unit_price,
            'amount' => $this->sales->lineAmount($l->qty, $l->unit_price), 'discount_type' => $l->discount_type, 'discount_value' => $l->discount_value])->all();
    }

    /** What one invoice from this template comes to. */
    public function templateTotal(object $r, $lines): string
    {
        $t = $this->sales->documentTotals($this->invoiceLines($lines), $r->discount_type, $r->discount_value);
        [$tax] = $this->sales->tax($t['taxable'], ['tax_percent' => $r->tax_percent, 'tax_total' => $r->tax_total ?? '0.00']);

        return Money::add($t['taxable'], $tax);
    }

    public function list(): array
    {
        $rows = $this->db()->table('recurring_invoices as r')->join('customers as c', 'c.id', '=', 'r.customer_id')
            ->orderByRaw("FIELD(r.status, 'active', 'paused', 'ended')")->orderBy('r.next_date')->get(['r.*', 'c.name as customer_name']);
        $lines = $this->db()->table('recurring_invoice_items')->orderBy('sort_order')->get()->groupBy('recurring_id');

        return $rows->each(function ($r) use ($lines) {
            $r->schedule = $this->scheduleText($r);
            $r->amount = $this->templateTotal($r, $lines->get($r->id, collect()));
        })->all();
    }

    public function get(int $id): ?object
    {
        $r = $this->db()->table('recurring_invoices as r')->join('customers as c', 'c.id', '=', 'r.customer_id')->where('r.id', $id)->first(['r.*', 'c.name as customer_name']);
        if (! $r) {
            return null;
        }
        $r->items = $this->db()->table('recurring_invoice_items')->where('recurring_id', $id)->orderBy('sort_order')->get();
        $r->schedule = $this->scheduleText($r);

        return $r;
    }

    /** A new template's starting values, copied from an invoice. Null if it can't be repeated. */
    public function prefillFromInvoice(int $invoiceId): ?object
    {
        $inv = $this->db()->table('invoices as i')->join('customers as c', 'c.id', '=', 'i.customer_id')->where('i.id', $invoiceId)->first(['i.*', 'c.name as customer_name']);
        if (! $inv || $inv->status === 'void') {
            return null;
        }
        $lines = $this->db()->table('invoice_items')->where('invoice_id', $invoiceId)->orderBy('sort_order')->get();
        $dueDays = $inv->due_date ? max(0, (int) round((strtotime($inv->due_date) - strtotime($inv->date)) / 86400)) : null;
        $next = $this->nextOccurrence(substr($inv->date, 0, 10), 'monthly', 1, (int) substr($inv->date, 8, 2));
        if ($next < now()->toDateString()) {
            $next = now()->toDateString();
        }

        return (object) ['id' => null, 'name' => $inv->customer_name.' - '.($lines->first()->description ?? 'invoice'), 'customer_id' => $inv->customer_id, 'customer_name' => $inv->customer_name,
            'frequency' => 'monthly', 'every' => 1, 'start_date' => $next, 'next_date' => $next, 'end_date' => null, 'due_days' => $dueDays, 'notes' => $inv->notes,
            'tax_percent' => $inv->tax_percent, 'tax_total' => $inv->tax_percent !== null ? null : $inv->tax_total, 'discount_type' => $inv->discount_type, 'discount_value' => $inv->discount_value,
            'status' => 'active', 'items' => $lines->map(fn ($l) => (object) ['item_id' => $l->item_id, 'description' => $l->description, 'qty' => $l->qty, 'unit_price' => $l->unit_price,
                'discount_type' => $l->discount_type, 'discount_value' => $l->discount_value])];
    }

    // ---- saving -----------------------------------------------------------------------

    /** Create or update a template. $lines: description, qty, unit_price, item_id, discount_*, remove. Returns its id. */
    public function save(array $d, array $lines, ?int $id = null): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            $this->fail('Give the recurring invoice a name, e.g. "Office rent".');
        }
        $customer = $this->db()->table('customers')->where('id', (int) ($d['customer_id'] ?? 0))->first() ?? $this->fail('Choose a customer.');
        $frequency = isset(self::FREQUENCIES[$d['frequency'] ?? '']) ? $d['frequency'] : 'monthly';
        $every = (int) ($d['every'] ?? 0);
        if ($every < 1 || $every > 99) {
            $this->fail('"Every" must be a number from 1 to 99.');
        }
        $start = (string) ($d['start_date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $this->fail('Enter the date of the first (next) invoice.');
        }
        $end = ! empty($d['end_date']) ? $d['end_date'] : null;
        if ($end !== null && $end < $start) {
            $this->fail('The end date is before the first invoice.');
        }
        $dueDays = ($d['due_days'] ?? '') !== '' && $d['due_days'] !== null ? (int) $d['due_days'] : null;
        if ($dueDays !== null && ($dueDays < 0 || $dueDays > 365)) {
            $this->fail('Due in must be between 0 and 365 days.');
        }

        $clean = [];
        foreach ($lines as $l) {
            if (! empty($l['remove'])) {
                continue;
            }
            $desc = trim((string) ($l['description'] ?? ''));
            $qty = str_replace(',', '', trim((string) ($l['qty'] ?? '')));
            $price = str_replace(',', '', trim((string) ($l['unit_price'] ?? '')));
            if ($desc === '' && $price === '') {
                continue;
            }
            if ($desc === '') {
                $this->fail('Every line needs a description.');
            }
            if (! is_numeric($qty) || (float) $qty <= 0) {
                $this->fail('Every line needs a quantity greater than zero.');
            }
            if (! is_numeric($price) || (float) $price < 0) {
                $this->fail('Every line needs a price of zero or more.');
            }
            $clean[] = ['item_id' => ! empty($l['item_id']) ? (int) $l['item_id'] : null, 'description' => mb_substr($desc, 0, 255), 'qty' => $qty, 'unit_price' => $price,
                'discount_type' => ! empty($l['discount_type']) ? $l['discount_type'] : null, 'discount_value' => ($l['discount_value'] ?? '') !== '' ? $l['discount_value'] : null];
        }
        if (! $clean) {
            $this->fail('Keep at least one line.');
        }

        $pct = ($d['tax_percent'] ?? '') !== '' && $d['tax_percent'] !== null ? $d['tax_percent'] : null;
        $status = in_array($d['status'] ?? '', ['active', 'paused'], true) ? $d['status'] : 'active';
        $row = ['name' => mb_substr($name, 0, 150), 'customer_id' => (int) $customer->id, 'frequency' => $frequency, 'every' => $every, 'anchor_day' => (int) substr($start, 8, 2),
            'start_date' => $start, 'next_date' => $start, 'end_date' => $end, 'due_days' => $dueDays, 'notes' => ($d['notes'] ?? '') !== '' ? mb_substr((string) $d['notes'], 0, 500) : null,
            'tax_percent' => $pct, 'tax_total' => $pct !== null ? null : (($d['tax_total'] ?? '') !== '' ? str_replace(',', '', $d['tax_total']) : null),
            'discount_type' => ! empty($d['discount_type']) ? $d['discount_type'] : null, 'discount_value' => ($d['discount_value'] ?? '') !== '' ? $d['discount_value'] : null, 'status' => $status];

        $rid = $this->db()->transaction(function () use ($id, $row, $clean) {
            if ($id) {
                $this->db()->table('recurring_invoices')->where('id', $id)->first() ?? $this->fail('Recurring invoice not found.');
                $this->db()->table('recurring_invoices')->where('id', $id)->update($row);
                $this->db()->table('recurring_invoice_items')->where('recurring_id', $id)->delete();
                $rid = $id;
            } else {
                $rid = $this->db()->table('recurring_invoices')->insertGetId($row + ['created_at' => now()]);
            }
            foreach ($clean as $i => $c) {
                $this->db()->table('recurring_invoice_items')->insert($c + ['recurring_id' => $rid, 'sort_order' => $i]);
            }

            return $rid;
        });
        ActivityLog::add(($id ? 'Updated' : 'Created').' recurring invoice "'.$row['name'].'" ('.$this->scheduleText($row).')');

        return (int) $rid;
    }

    public function setStatus(int $id, string $status): void
    {
        $r = $this->db()->table('recurring_invoices')->where('id', $id)->first() ?? $this->fail('Recurring invoice not found.');
        if ($status === 'active' && $r->end_date && $r->next_date > $r->end_date) {
            $this->fail('Its end date has passed. Edit it and set a later end date first.');
        }
        $update = ['status' => $status];
        if ($status === 'active' && $r->next_date < now()->toDateString()) {
            // resuming after a pause doesn't back-fill the missed ones: the next invoice is the next scheduled date from today
            $next = $r->next_date;
            for ($guard = 0; $next < now()->toDateString() && $guard < 1000; $guard++) {
                $next = $this->nextOccurrence($next, $r->frequency, $r->every, $r->anchor_day);
            }
            $update['next_date'] = $next;
        }
        $this->db()->table('recurring_invoices')->where('id', $id)->update($update);
        ActivityLog::add(($status === 'active' ? 'Resumed' : 'Paused').' recurring invoice "'.$r->name.'"');
    }

    public function delete(int $id): void
    {
        $r = $this->db()->table('recurring_invoices')->where('id', $id)->first() ?? $this->fail('Recurring invoice not found.');
        $this->db()->transaction(function () use ($id) {
            $this->db()->table('recurring_invoice_items')->where('recurring_id', $id)->delete();
            $this->db()->table('recurring_invoices')->where('id', $id)->delete();
        });
        ActivityLog::add('Deleted recurring invoice "'.$r->name.'" (invoices already created are kept)');
    }

    // ---- creating the invoices ------------------------------------------------------------

    /** A draft invoice for every occurrence that has come due (up to $today) on every active template. Returns how many were made. */
    public function runDue(?string $today = null): int
    {
        $today ??= now()->toDateString();
        $this->ensureSchema();
        $made = 0;
        foreach ($this->db()->table('recurring_invoices')->where('status', 'active')->where('next_date', '<=', $today)->pluck('id') as $id) {
            $made += $this->runOne((int) $id, $today, false);
        }

        return $made;
    }

    /** runDue() for page views (Invoices, Dashboard): never breaks the page, and says what it made. */
    public function runDueAndNotify(): void
    {
        try {
            $made = $this->runDue();
            if ($made > 0) {
                session()->now('message', $made.' recurring invoice'.($made === 1 ? ' was' : 's were').' created as draft'.($made === 1 ? '' : 's').' - check and issue '.($made === 1 ? 'it' : 'them').' below.');
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** $now = true is "Create now": the next scheduled invoice straight away (dated today), moving the schedule on, even if it isn't due. */
    public function runOne(int $id, ?string $today = null, bool $now = false): int
    {
        $today ??= now()->toDateString();
        $made = 0;
        $name = '';
        $this->db()->transaction(function () use ($id, $today, $now, &$made, &$name) {
            $r = $this->db()->selectOne('SELECT * FROM recurring_invoices WHERE id = ? FOR UPDATE', [$id]);
            if (! $r || ($r->status !== 'active' && ! $now)) {
                return;
            }
            $name = $r->name;
            $lines = $this->db()->table('recurring_invoice_items')->where('recurring_id', $id)->orderBy('sort_order')->get();
            if ($lines->isEmpty()) {
                return;
            }
            $next = $r->next_date;
            $last = $r->last_invoice_id;
            $ended = false;
            while (($now ? $made < 1 : $next <= $today) && $made < self::MAX_CATCH_UP) {
                if ($r->end_date && $next > $r->end_date) {
                    $ended = true;
                    break;
                }
                $date = $now ? $today : $next;
                $invoiceId = $this->sales->saveInvoice([
                    'customer_id' => $r->customer_id, 'date' => $date, 'due_date' => $r->due_days !== null ? date('Y-m-d', strtotime($date.' +'.(int) $r->due_days.' days')) : null,
                    'notes' => $r->notes, 'tax_percent' => $r->tax_percent ?? '', 'tax_total' => $r->tax_total ?? '0.00', 'discount_type' => $r->discount_type, 'discount_value' => $r->discount_value,
                ], $this->invoiceLines($lines));
                $this->db()->table('invoices')->where('id', $invoiceId)->update(['recurring_id' => $id]);
                $last = $invoiceId;
                $made++;
                $next = $this->nextOccurrence($next, $r->frequency, (int) $r->every, (int) $r->anchor_day);
            }
            if (! $ended && $r->end_date && $next > $r->end_date) {
                $ended = true;
            }
            $this->db()->table('recurring_invoices')->where('id', $id)->update(['next_date' => $next, 'created_count' => (int) $r->created_count + $made, 'last_invoice_id' => $last,
                'last_run_at' => now(), 'status' => $ended ? 'ended' : $r->status]);
        });
        if ($made > 0) {
            ActivityLog::add("Created $made draft invoice".($made === 1 ? '' : 's').' from recurring invoice "'.$name.'"');
        }

        return $made;
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['recurring' => $message]);
    }
}
