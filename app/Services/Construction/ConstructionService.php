<?php

namespace App\Services\Construction;

use App\Models\Tenant\Setting;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\EntryService;
use App\Services\Payroll\PayrollService;
use App\Services\Sales\Allocations;
use App\Services\Sales\SalesService;
use App\Support\Money;
use App\Support\Tables;
use Illuminate\Database\Schema\Blueprint as B;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Construction - projects (legacy Projects_model). A project belongs to a customer and has a contract amount, variations, a budget per
 * cost type, and its own retention % and client advance used by progress claims.
 *
 * Costs are NOT stored here: a cost is any ledger line (entryitems row) tagged with project_id + cost_type. Bill lines, quick
 * expenses, Money Out, journal lines and wage sheets copy their tag onto the entryitems they post, so a voided bill drops out of the
 * project by itself and every figure matches the books. "Billed" = issued sales invoices marked with the project (before tax).
 */
class ConstructionService
{
    public const COST_TYPES = EntryService::COST_TYPES;

    public const STATUSES = ['active' => 'Active', 'on_hold' => 'On hold', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

    public function __construct(protected ChartOfAccounts $chart, protected EntryService $entries, protected SalesService $sales, protected Allocations $allocs, protected PayrollService $ledgers, protected EstimateService $estimates) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['projects' => $message]);
    }

    public static function enabled(): bool
    {
        return (bool) Setting::first()?->construction_enabled;
    }

    // ---- tables -------------------------------------------------------------------------------------------------

    /** The construction tables and the project tag columns, made the first time Projects is opened (DDL: never inside a transaction). */
    public function ensureTables(): void
    {
        static $done = [];
        $key = config('database.connections.tenant.database');
        if (isset($done[$key])) {
            return;
        }
        $s = Schema::connection('tenant');
        $make = function (string $table, callable $cols) use ($s) {
            if (! $s->hasTable($table)) {
                $s->create($table, function (B $t) use ($cols) {
                    $t->engine = 'InnoDB';
                    $t->bigIncrements('id');
                    $cols($t);
                });
            }
        };
        $make('projects', function (B $t) {
            $t->unsignedBigInteger('number');
            $t->string('name', 255);
            $t->unsignedBigInteger('customer_id')->index();
            $t->string('site', 255)->nullable();
            $t->decimal('contract_amount', 25, 2)->default(0);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->string('status', 20)->default('active')->index();
            $t->decimal('retention_percent', 6, 3)->default(0);
            $t->decimal('advance_amount', 25, 2)->default(0);
            $t->string('notes', 1000)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->string('claim_method', 10)->default('percent');
            $t->unsignedBigInteger('estimate_id')->nullable();
        });
        $make('project_budgets', function (B $t) {
            $t->unsignedBigInteger('project_id');
            $t->string('cost_type', 20);
            $t->decimal('amount', 25, 2)->default(0);
            $t->unique(['project_id', 'cost_type'], 'project_cost_type');
        });
        $make('project_variations', function (B $t) {
            $t->unsignedBigInteger('project_id')->index();
            $t->date('date');
            $t->string('description', 500);
            $t->decimal('amount', 25, 2)->default(0);
            $t->dateTime('created_at')->nullable();
        });
        $make('project_claims', function (B $t) {
            $t->unsignedBigInteger('project_id')->index();
            $t->string('kind', 20)->default('claim');
            $t->integer('number')->default(0);
            $t->date('date');
            $t->decimal('percent_to_date', 7, 3)->nullable();
            $t->decimal('gross_to_date', 25, 2)->default(0);
            $t->decimal('gross', 25, 2)->default(0);
            $t->decimal('retention', 25, 2)->default(0);
            $t->decimal('advance', 25, 2)->default(0);
            $t->decimal('net', 25, 2)->default(0);
            $t->unsignedBigInteger('invoice_id')->nullable()->index();
            $t->string('notes', 500)->nullable();
            $t->dateTime('created_at')->nullable();
        });
        $make('project_advances', function (B $t) {
            $t->unsignedBigInteger('project_id')->index();
            $t->unsignedBigInteger('payment_id')->index();
            $t->dateTime('created_at')->nullable();
        });
        $make('workers', function (B $t) {
            $t->string('name', 255);
            $t->string('phone', 50)->nullable();
            $t->string('pay_type', 10)->default('daily');
            $t->decimal('rate', 25, 2)->default(0);
            $t->string('trade', 100)->nullable();
            $t->unsignedBigInteger('default_project_id')->nullable();
            $t->string('status', 10)->default('active');
            $t->dateTime('created_at')->nullable();
        });
        $make('wage_sheets', function (B $t) {
            $t->unsignedBigInteger('number');
            $t->date('date_from');
            $t->date('date_to');
            $t->date('date');
            $t->decimal('total', 25, 2)->default(0);
            $t->unsignedBigInteger('paid_ledger_id')->nullable();
            $t->date('paid_date')->nullable();
            $t->unsignedBigInteger('entry_id')->nullable();
            $t->unsignedBigInteger('paid_entry_id')->nullable();
            $t->string('status', 10)->default('posted');
            $t->string('notes', 500)->nullable();
            $t->dateTime('created_at')->nullable();
        });
        $make('wage_lines', function (B $t) {
            $t->unsignedBigInteger('sheet_id')->index();
            $t->unsignedBigInteger('worker_id');
            $t->unsignedBigInteger('project_id')->nullable();
            $t->decimal('days', 7, 2)->nullable();
            $t->decimal('rate', 25, 2)->default(0);
            $t->decimal('amount', 25, 2)->default(0);
            $t->string('note', 255)->nullable();
        });
        // estimates (bill of quantities), rate list, schedule, weekly site reports, site stores and stock
        $make('estimates', function (B $t) {
            $t->unsignedBigInteger('project_id')->index();
            $t->integer('number')->default(1);
            $t->string('status', 10)->default('draft');
            $t->date('date');
            $t->string('title', 255)->nullable();
            $t->decimal('tax_percent', 7, 3)->default(0);
            $t->string('tax_name', 100)->nullable();
            $t->decimal('margin_percent', 6, 2)->default(0);
            $t->decimal('subtotal', 25, 2)->default(0);
            $t->decimal('tax_total', 25, 2)->default(0);
            $t->decimal('total', 25, 2)->default(0);
            $t->string('notes', 2000)->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->string('approved_by', 150)->nullable();
            $t->dateTime('created_at')->nullable();
        });
        $make('estimate_sections', function (B $t) {
            $t->unsignedBigInteger('estimate_id')->index();
            $t->string('name', 255);
            $t->integer('sort')->default(0);
        });
        $make('estimate_items', function (B $t) {
            $t->unsignedBigInteger('estimate_id')->index();
            $t->unsignedBigInteger('section_id')->index();
            $t->string('description', 500);
            $t->string('unit', 20)->nullable();
            $t->decimal('qty', 15, 3)->default(0);
            $t->decimal('rate', 25, 2)->default(0);
            $t->decimal('amount', 25, 2)->default(0);
            $t->string('cost_type', 20)->default('materials');
            $t->integer('sort')->default(0);
        });
        $make('construction_units', function (B $t) {
            $t->string('name', 20);
        });
        $make('construction_rates', function (B $t) {
            $t->string('description', 500);
            $t->string('unit', 20)->nullable();
            $t->decimal('rate', 25, 2)->default(0);
            $t->string('cost_type', 20)->default('materials');
            $t->dateTime('updated_at')->nullable();
        });
        $make('project_section_budgets', function (B $t) {
            $t->unsignedBigInteger('project_id')->index();
            $t->string('name', 255);
            $t->integer('sort')->default(0);
            $t->decimal('estimate_amount', 25, 2)->default(0);
            $t->decimal('budget', 25, 2)->default(0);
        });
        $make('claim_items', function (B $t) {
            $t->unsignedBigInteger('claim_id')->index();
            $t->unsignedBigInteger('estimate_item_id');
            $t->decimal('qty_to_date', 15, 3)->default(0);
            $t->decimal('rate', 25, 2)->default(0);
            $t->decimal('amount_to_date', 25, 2)->default(0);
        });
        $make('project_stages', function (B $t) {
            $t->unsignedBigInteger('project_id')->index();
            $t->integer('sort')->default(0);
            $t->string('name', 255);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->decimal('weight', 6, 2)->default(0);
            $t->decimal('percent_done', 6, 2)->default(0);
            $t->string('notes', 500)->nullable();
        });
        $make('site_reports', function (B $t) {
            $t->unsignedBigInteger('project_id')->index();
            $t->integer('number')->default(1);
            $t->date('week_start');
            $t->date('week_end');
            $t->string('status', 10)->default('draft');
            $t->decimal('planned_pct', 6, 2)->nullable();
            $t->decimal('actual_pct', 6, 2)->nullable();
            $t->string('weather', 255)->nullable();
            foreach (['work_done', 'equipment', 'quality', 'safety', 'challenges', 'actions', 'next_week'] as $c) {
                $t->text($c)->nullable();
            }
            $t->mediumText('auto_json')->nullable();
            $t->string('prepared_by', 150)->nullable();
            $t->dateTime('submitted_at')->nullable();
            $t->string('approved_by', 150)->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->string('return_note', 500)->nullable();
            $t->dateTime('created_at')->nullable();
        });
        $make('site_report_photos', function (B $t) {
            $t->unsignedBigInteger('report_id')->index();
            $t->string('file', 255);
            $t->string('caption', 255)->nullable();
            $t->dateTime('created_at')->nullable();
        });
        $make('stores', function (B $t) {
            $t->string('name', 255);
            $t->unsignedBigInteger('project_id')->nullable();
            $t->string('status', 10)->default('active');
            $t->dateTime('created_at')->nullable();
        });
        $make('store_stock', function (B $t) {
            $t->unsignedBigInteger('store_id');
            $t->unsignedBigInteger('item_id');
            $t->decimal('qty', 15, 2)->default(0);
            $t->unique(['store_id', 'item_id'], 'store_item');
        });
        $make('stock_moves', function (B $t) {
            $t->unsignedBigInteger('number');
            $t->string('kind', 10);
            $t->date('date');
            $t->unsignedBigInteger('item_id')->index();
            $t->decimal('qty', 15, 2);
            $t->unsignedBigInteger('from_store_id')->default(0);
            $t->unsignedBigInteger('to_store_id')->nullable();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->decimal('unit_cost', 25, 2)->default(0);
            $t->decimal('amount', 25, 2)->default(0);
            $t->unsignedBigInteger('entry_id')->nullable();
            $t->string('status', 10)->default('posted');
            $t->string('notes', 500)->nullable();
            $t->dateTime('created_at')->nullable();
        });
        foreach (['entryitems', 'bill_items', 'expenses'] as $table) {
            if ($s->hasTable($table) && ! $s->hasColumn($table, 'project_id')) {
                $s->table($table, function (B $t) {
                    $t->unsignedBigInteger('project_id')->nullable()->index();
                    $t->string('cost_type', 20)->nullable();
                });
            }
        }
        if ($s->hasTable('invoices') && ! $s->hasColumn('invoices', 'project_id')) {
            $s->table('invoices', fn (B $t) => $t->unsignedBigInteger('project_id')->nullable()->index());
        }
        $done[$key] = true;
    }

    // ---- tags ("<project id>:<cost type>" from the project pickers) -----------------------------------------------

    /** [project id, cost type], or [null, null] for "no project". An unknown project or cost type is an error. */
    public function parseTag($value, bool $needCostType = true): array
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0') {
            return [null, null];
        }
        [$pid, $type] = array_pad(explode(':', $value, 2), 2, '');
        if ((int) $pid <= 0 || ! $this->db()->table('projects')->where('id', (int) $pid)->exists()) {
            $this->fail('That project does not exist.');
        }
        if (! $needCostType) {
            return [(int) $pid, null];
        }
        if (! array_key_exists($type, self::COST_TYPES)) {
            $this->fail('Choose the cost type for the project.');
        }

        return [(int) $pid, $type];
    }

    public function label(object|array $p): string
    {
        $p = (object) $p;

        return '#'.(int) $p->number.' '.$p->name;
    }

    /** id => "#3 Name" for showing a tag. */
    public function labelsFor(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids || ! Tables::has('projects')) {
            return [];
        }

        return $this->db()->table('projects')->whereIn('id', $ids)->get(['id', 'number', 'name'])->mapWithKeys(fn ($p) => [(int) $p->id => $this->label($p)])->all();
    }

    /** id => label for the project pickers (active and on-hold projects, plus those already on the document). */
    public function options(array $keep = [], bool $withCustomer = false): array
    {
        $keep = array_filter(array_map('intval', $keep));
        $rows = $this->db()->table('projects')->where(fn ($q) => $q->whereIn('status', ['active', 'on_hold'])->when($keep, fn ($x) => $x->orWhereIn('id', $keep)))->orderByDesc('number')->get(['id', 'number', 'name', 'customer_id']);

        return $rows->map(fn ($p) => ['id' => (int) $p->id, 'customer_id' => (int) $p->customer_id, 'label' => $this->label($p)])->all();
    }

    // ---- projects -------------------------------------------------------------------------------------------------

    public function project(int $id): ?object
    {
        return $this->db()->table('projects')->leftJoin('customers', 'customers.id', '=', 'projects.customer_id')->where('projects.id', $id)->first(['projects.*', 'customers.name as customer_name']);
    }

    public function projects(?string $status = null)
    {
        return $this->db()->table('projects')->leftJoin('customers', 'customers.id', '=', 'projects.customer_id')->when($status, fn ($q) => $q->where('projects.status', $status))
            ->orderByDesc('projects.number')->get(['projects.*', 'customers.name as customer_name']);
    }

    public function nextNumber(): int
    {
        return (int) $this->db()->table('projects')->max('number') + 1;
    }

    public function budgets(int $projectId): array
    {
        $out = array_fill_keys(array_keys(self::COST_TYPES), '0.00');
        foreach ($this->db()->table('project_budgets')->where('project_id', $projectId)->get() as $b) {
            if (isset($out[$b->cost_type])) {
                $out[$b->cost_type] = $b->amount;
            }
        }

        return $out;
    }

    protected function money($v, string $what, bool $allowNegative = false): string
    {
        $v = trim(str_replace(',', '', (string) $v));
        if ($v === '') {
            return '0.00';
        }
        if (! is_numeric($v) || (! $allowNegative && (float) $v < 0)) {
            $this->fail($what);
        }

        return Money::round($v);
    }

    /** @param array $d name, customer_id, site, contract_amount, start_date, end_date, status, retention_percent, advance_amount, notes, budgets[type] */
    public function saveProject(array $d, ?int $id = null): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            $this->fail('Give the project a name.');
        }
        if (empty($d['customer_id']) || ! $this->db()->table('customers')->where('id', (int) $d['customer_id'])->exists()) {
            $this->fail('Choose the client (customer) for this project.');
        }
        $contract = $this->money($d['contract_amount'] ?? '', 'Enter the contract amount (0 or more).');
        $advance = $this->money($d['advance_amount'] ?? '', 'The client advance must be 0 or more.');
        $ret = trim(str_replace(['%', ','], ['', '.'], (string) ($d['retention_percent'] ?? '')));
        $ret = $ret === '' ? '0' : $ret;
        if (! is_numeric($ret) || (float) $ret < 0 || (float) $ret >= 100) {
            $this->fail('Retention must be a percent from 0 to less than 100.');
        }
        $status = array_key_exists($d['status'] ?? '', self::STATUSES) ? $d['status'] : 'active';
        $start = ! empty($d['start_date']) ? $d['start_date'] : null;
        $end = ! empty($d['end_date']) ? $d['end_date'] : null;
        if ($start && $end && $end < $start) {
            $this->fail('The end date is before the start date.');
        }
        $budgets = [];
        foreach (self::COST_TYPES as $k => $label) {
            $budgets[$k] = $this->money($d['budgets'][$k] ?? '', "The $label budget must be a number, 0 or more.");
        }
        $row = ['name' => $name, 'customer_id' => (int) $d['customer_id'], 'site' => trim((string) ($d['site'] ?? '')), 'contract_amount' => $contract, 'start_date' => $start, 'end_date' => $end,
            'status' => $status, 'retention_percent' => number_format((float) $ret, 3, '.', ''), 'advance_amount' => $advance, 'notes' => trim((string) ($d['notes'] ?? ''))];

        $new = ! $id;
        $id = $this->db()->transaction(function () use ($row, $budgets, $id) {
            if ($id) {
                $old = $this->project($id) ?? $this->fail('Project not found.');
                if ((int) $old->customer_id !== $row['customer_id'] && $this->db()->table('invoices')->where('project_id', $id)->where('status', '!=', 'void')->exists()) {
                    $this->fail("This project already has invoices for {$old->customer_name}, so its client can't be changed.");
                }
                $this->db()->table('projects')->where('id', $id)->update($row);
            } else {
                $id = $this->db()->table('projects')->insertGetId($row + ['number' => $this->nextNumber(), 'created_at' => now()]);
            }
            foreach ($budgets as $k => $amt) {
                $this->db()->table('project_budgets')->updateOrInsert(['project_id' => $id, 'cost_type' => $k], ['amount' => $amt]);
            }

            return $id;
        });
        ActivityLog::add(($new ? 'Created' : 'Updated').' project '.$this->label($this->project($id)));

        return $id;
    }

    /** Only a project nothing was posted or billed to can be deleted; otherwise mark it Cancelled. */
    public function deleteProject(int $id): void
    {
        $p = $this->project($id) ?? $this->fail('Project not found.');
        $used = $this->db()->table('entryitems')->where('project_id', $id)->count() + $this->db()->table('invoices')->where('project_id', $id)->count()
            + $this->db()->table('bill_items')->where('project_id', $id)->count() + $this->db()->table('expenses')->where('project_id', $id)->count()
            + $this->db()->table('wage_lines')->where('project_id', $id)->count();
        if ($used > 0) {
            $this->fail("This project has costs or invoices, so it can't be deleted. Set its status to Completed or Cancelled instead.");
        }
        $this->db()->transaction(function () use ($id) {
            foreach (['project_budgets', 'project_variations', 'project_claims', 'project_advances'] as $t) {
                $this->db()->table($t)->where('project_id', $id)->delete();
            }
            $this->db()->table('workers')->where('default_project_id', $id)->update(['default_project_id' => null]);
            $this->deleteDependents($id);
            $this->db()->table('projects')->where('id', $id)->delete();
        });
        ActivityLog::add('Deleted project '.$this->label($p));
    }

    /** Estimates, schedule and site reports of a project go with it (they have their own tables once those are in use). */
    protected function deleteDependents(int $id): void // (the deleteProject guard already refused a project with costs, invoices or wage lines)
    {
        foreach ($this->db()->table('estimates')->where('project_id', $id)->pluck('id') as $eid) {
            $this->db()->table('estimate_items')->where('estimate_id', $eid)->delete();
            $this->db()->table('estimate_sections')->where('estimate_id', $eid)->delete();
        }
        $this->db()->table('estimates')->where('project_id', $id)->delete();
        foreach ($this->db()->table('site_reports')->where('project_id', $id)->pluck('id') as $rid) {
            foreach ($this->db()->table('site_report_photos')->where('report_id', $rid)->pluck('file') as $file) {
                @unlink(public_path('uploads/site/'.$file));
            }
            $this->db()->table('site_report_photos')->where('report_id', $rid)->delete();
        }
        $this->db()->table('site_reports')->where('project_id', $id)->delete();
        foreach (['project_stages', 'project_section_budgets'] as $t) {
            $this->db()->table($t)->where('project_id', $id)->delete();
        }
        $this->db()->table('stores')->where('project_id', $id)->update(['project_id' => null]);
    }

    /** How claims on this project are worked out: % complete, or measured quantities x the approved estimate's rates. */
    public function setClaimMethod(int $projectId, string $method): void
    {
        $method = $method === 'measured' ? 'measured' : 'percent';
        if ($method === 'measured' && ! $this->estimates->approved($projectId)) {
            $this->fail('Approve the estimate first - measured claims are priced at its rates.');
        }
        $this->db()->table('projects')->where('id', $projectId)->update(['claim_method' => $method]);
    }

    // ---- variations -------------------------------------------------------------------------------------------------

    public function variations(int $projectId)
    {
        return $this->db()->table('project_variations')->where('project_id', $projectId)->orderBy('date')->orderBy('id')->get();
    }

    public function variationsTotal(int $projectId): string
    {
        return Money::round($this->db()->table('project_variations')->where('project_id', $projectId)->sum('amount'));
    }

    public function currentContract(int $projectId): string
    {
        return Money::add($this->db()->table('projects')->where('id', $projectId)->value('contract_amount') ?? 0, $this->variationsTotal($projectId));
    }

    public function addVariation(int $projectId, array $d): void
    {
        $p = $this->project($projectId) ?? $this->fail('Project not found.');
        $desc = trim((string) ($d['description'] ?? ''));
        if ($desc === '') {
            $this->fail('Describe the variation (what was added or removed).');
        }
        if (empty($d['date'])) {
            $this->fail('Enter the variation date.');
        }
        $amt = $this->money($d['amount'] ?? '', 'Enter the variation amount: positive for extra work, negative (e.g. -500) for work removed.', true);
        if (Money::isZero($amt)) {
            $this->fail('Enter the variation amount: positive for extra work, negative (e.g. -500) for work removed.');
        }
        if (Money::cmp(Money::add($this->currentContract($projectId), $amt), 0) < 0) {
            $this->fail('That would make the contract less than zero.');
        }
        $this->db()->table('project_variations')->insert(['project_id' => $projectId, 'date' => $d['date'], 'description' => $desc, 'amount' => $amt, 'created_at' => now()]);
        ActivityLog::add('Added variation '.Money::format($amt).' to project '.$this->label($p));
    }

    public function deleteVariation(int $variationId): int
    {
        $v = $this->db()->table('project_variations')->where('id', $variationId)->first() ?? $this->fail('Variation not found.');
        $this->db()->table('project_variations')->where('id', $variationId)->delete();
        ActivityLog::add('Removed variation "'.$v->description.'" from project #'.$v->project_id);

        return (int) $v->project_id;
    }

    // ---- figures ---------------------------------------------------------------------------------------------------

    /** Costs to date per project and cost type from the ledger: [project id => [cost type => amount]]. Debits add, credits subtract. */
    public function costsByType(?array $projectIds = null): array
    {
        if ($projectIds !== null && ! ($ids = array_filter(array_map('intval', $projectIds)))) {
            return [];
        }
        $q = $this->db()->table('entryitems as ei')->join('entries as e', 'e.id', '=', 'ei.entry_id')->whereNotNull('ei.project_id')->whereNotNull('ei.cost_type')
            ->when($projectIds !== null, fn ($x) => $x->whereIn('ei.project_id', $ids))
            ->groupBy('ei.project_id', 'ei.cost_type')->selectRaw("ei.project_id, ei.cost_type, SUM(CASE WHEN ei.dc = 'D' THEN ei.amount ELSE -ei.amount END) AS amt");
        $out = [];
        foreach ($q->get() as $r) {
            $out[(int) $r->project_id][$r->cost_type] = Money::round($r->amt);
        }

        return $out;
    }

    /** Billed (issued invoices before tax), invoiced and received, per project. */
    public function billing(?array $projectIds = null): array
    {
        if ($projectIds !== null && ! ($ids = array_filter(array_map('intval', $projectIds)))) {
            return [];
        }
        $invoices = $this->db()->table('invoices')->where('status', 'issued')->whereNotNull('project_id')->when($projectIds !== null, fn ($q) => $q->whereIn('project_id', $ids))->get(['id', 'project_id', 'total', 'tax_total']);
        $paid = $invoices->isEmpty() ? [] : $this->allocs->paidByDoc('ar', $invoices->pluck('id')->all());
        // a claim's invoice bills its full work value (gross) though retention is held back; a retention release collects money already billed
        $claims = $invoices->isEmpty() ? collect() : $this->db()->table('project_claims')->whereIn('invoice_id', $invoices->pluck('id')->all())->get(['invoice_id', 'kind', 'gross'])->keyBy('invoice_id');
        $out = [];
        foreach ($invoices as $inv) {
            $pid = (int) $inv->project_id;
            $out[$pid] ??= ['billed' => '0.00', 'invoiced' => '0.00', 'received' => '0.00', 'count' => 0];
            $c = $claims[$inv->id] ?? null;
            $here = $c ? ($c->kind === 'claim' ? $c->gross : '0.00') : Money::sub($inv->total, $inv->tax_total ?: 0);
            $out[$pid]['billed'] = Money::add($out[$pid]['billed'], $here);
            $out[$pid]['invoiced'] = Money::add($out[$pid]['invoiced'], $inv->total);
            $out[$pid]['received'] = Money::add($out[$pid]['received'], $paid[$inv->id] ?? 0);
            $out[$pid]['count']++;
        }

        return $out;
    }

    /** One summary row per project (the Projects list): the project plus contract, costs, billed, profit ... */
    public function summaries($projects): array
    {
        $ids = collect($projects)->pluck('id')->all();
        $costs = $this->costsByType($ids);
        $billing = $this->billing($ids);
        $vars = $ids ? $this->db()->table('project_variations')->whereIn('project_id', $ids)->groupBy('project_id')->selectRaw('project_id, SUM(amount) t')->pluck('t', 'project_id') : collect();
        $bud = $ids ? $this->db()->table('project_budgets')->whereIn('project_id', $ids)->groupBy('project_id')->selectRaw('project_id, SUM(amount) t')->pluck('t', 'project_id') : collect();
        $out = [];
        foreach ($projects as $p) {
            $id = (int) $p->id;
            $cost = '0.00';
            foreach ($costs[$id] ?? [] as $amt) {
                $cost = Money::add($cost, $amt);
            }
            $b = $billing[$id] ?? ['billed' => '0.00', 'invoiced' => '0.00', 'received' => '0.00', 'count' => 0];
            $v = Money::round($vars[$id] ?? 0);
            $contract = Money::add($p->contract_amount, $v);
            $budget = Money::round($bud[$id] ?? 0);
            $row = (object) (((array) $p) + [
                'contract' => $contract, 'variations' => $v, 'budget' => $budget, 'cost' => $cost, 'billed' => $b['billed'], 'invoiced' => $b['invoiced'], 'received' => $b['received'],
                'owed' => Money::sub($b['invoiced'], $b['received']), 'profit' => Money::sub($b['billed'], $cost), 'left_to_bill' => Money::sub($contract, $b['billed']),
                'budget_used_pct' => Money::positive($budget) ? (int) round((float) $cost / (float) $budget * 100) : null,
                'billed_pct' => Money::positive($contract) ? (int) round((float) $b['billed'] / (float) $contract * 100) : null,
                'costs_by_type' => $costs[$id] ?? [],
            ]);
            $out[$id] = $row;
        }

        return $out;
    }

    /** The latest cost lines of a project, newest first, with a link to where each came from. */
    public function recentCosts(int $projectId, int $limit = 50): array
    {
        $rows = $this->db()->table('entryitems as ei')->join('entries as e', 'e.id', '=', 'ei.entry_id')->leftJoin('entrytypes as t', 't.id', '=', 'e.entrytype_id')->leftJoin('ledgers as l', 'l.id', '=', 'ei.ledger_id')
            ->where('ei.project_id', $projectId)->whereNotNull('ei.cost_type')->orderByDesc('e.date')->orderByDesc('ei.id')->limit($limit)
            ->get(['ei.id', 'ei.amount', 'ei.dc', 'ei.cost_type', 'ei.narration', 'e.id as entry_id', 'e.date', 'e.number as entry_number', 'e.entrytype_id', 't.label as entrytype_label', 'l.name as ledger_name'])->all();
        if (! $rows) {
            return [];
        }
        $eids = array_unique(array_map(fn ($r) => $r->entry_id, $rows));
        $bills = $this->db()->table('bills')->join('vendors', 'vendors.id', '=', 'bills.vendor_id')->whereIn('bills.entry_id', $eids)->get(['bills.id', 'bills.number', 'bills.entry_id', 'vendors.name as vendor'])->keyBy('entry_id');
        $expenses = $this->db()->table('expenses')->whereIn('entry_id', $eids)->get(['id', 'number', 'entry_id'])->keyBy('entry_id');
        $sheets = Tables::has('wage_sheets') ? $this->db()->table('wage_sheets')->whereIn('entry_id', $eids)->get(['id', 'number', 'entry_id'])->keyBy('entry_id') : collect();
        foreach ($rows as $r) {
            $eid = $r->entry_id;
            $r->signed = $r->dc === 'D' ? $r->amount : Money::neg($r->amount);
            if (isset($sheets[$eid])) {
                [$r->source, $r->url] = ['Wage sheet '.$sheets[$eid]->number, route('construction.wages.show', $sheets[$eid]->id)];
            } elseif (isset($bills[$eid])) {
                [$r->source, $r->url] = ['Bill #'.$bills[$eid]->number.' - '.$bills[$eid]->vendor, route('bills.show', $bills[$eid]->id)];
            } elseif (isset($expenses[$eid])) {
                [$r->source, $r->url] = ['Expense EXP-'.str_pad((string) $expenses[$eid]->number, 4, '0', STR_PAD_LEFT), route('expenses.show', $expenses[$eid]->id)];
            } else {
                [$r->source, $r->url] = ['Journal '.$r->entry_number, $r->entrytype_label ? route('entries.show', [$r->entrytype_label, $eid]) : null];
            }
        }

        return $rows;
    }

    /** The invoices of a project (not void), with their payment status. */
    public function invoices(int $projectId): array
    {
        $rows = $this->db()->table('invoices')->where('project_id', $projectId)->where('status', '!=', 'void')->orderBy('date')->orderBy('id')->get();

        return $rows->isEmpty() ? [] : $this->allocs->decorate('ar', $rows);
    }

    // ---- progress claims, retention, client advance ------------------------------------------------------------------
    // A claim bills the work done since the last claim: this claim = contract x % complete to date - claimed before.
    // Retention (the project's %) is held back with a "Less retention" line posted to Retention Receivable; the client advance
    // (received earlier as a customer payment) is recovered as the same share (advance / contract) of each claim.

    public function claims(int $projectId): array
    {
        $rows = $this->db()->table('project_claims as c')->leftJoin('invoices as i', 'i.id', '=', 'c.invoice_id')->where('c.project_id', $projectId)->orderBy('c.date')->orderBy('c.id')
            ->get(['c.*', 'i.number as invoice_number', 'i.status as invoice_status', 'i.total as invoice_total'])->all();
        foreach ($rows as $r) {
            $r->live = ! empty($r->invoice_id) && $r->invoice_status === 'issued';
        }

        return $rows;
    }

    /** Advance payments recorded for a project, with what is still unrecovered. */
    public function advances(int $projectId): array
    {
        $rows = $this->db()->table('project_advances as a')->join('payments as p', 'p.id', '=', 'a.payment_id')->where('a.project_id', $projectId)->orderBy('p.date')->orderBy('p.id')
            ->get(['a.id as advance_id', 'p.*'])->all();
        foreach ($rows as $r) {
            $r->left = $r->status === 'received' ? $this->allocs->available('ar', 'pay', (int) $r->id) : '0.00';
        }

        return $rows;
    }

    public function claimTotals(int $projectId): array
    {
        $t = ['claimed' => '0.00', 'percent' => '0', 'retention_held' => '0.00', 'retention_released' => '0.00', 'retention_due' => '0.00', 'advance_received' => '0.00', 'advance_recovered' => '0.00', 'advance_left' => '0.00', 'claims' => 0];
        foreach ($this->claims($projectId) as $c) {
            if (! $c->live) {
                continue;
            }
            if ($c->kind === 'claim') {
                $t['claimed'] = Money::add($t['claimed'], $c->gross);
                $t['retention_held'] = Money::add($t['retention_held'], $c->retention);
                $t['advance_recovered'] = Money::add($t['advance_recovered'], $c->advance);
                $t['percent'] = (string) $c->percent_to_date;
                $t['claims']++;
            } elseif ($c->kind === 'retention') {
                $t['retention_released'] = Money::add($t['retention_released'], $c->gross);
            }
        }
        $t['retention_due'] = Money::sub($t['retention_held'], $t['retention_released']);
        foreach ($this->advances($projectId) as $a) {
            if ($a->status === 'received') {
                $t['advance_received'] = Money::add($t['advance_received'], $a->amount);
                $t['advance_left'] = Money::add($t['advance_left'], $a->left);
            }
        }

        return $t;
    }

    /**
     * Works out a claim without saving it. Two ways, per project: % complete ($percent of the whole job done to date), or measured
     * ($measured = [estimate item id => qty done to date], priced at the approved estimate's rates; items left out keep the qty of the last
     * measured claim). Returns the figures; throws when nothing can be claimed.
     */
    public function claimPreview(int $projectId, $percent, ?array $measured = null): array
    {
        $p = $this->project($projectId) ?? $this->fail('Project not found.');
        $contract = $this->currentContract($projectId);
        if (! Money::positive($contract)) {
            $this->fail('This project has no contract amount yet. Set it (Edit) or approve the estimate before claiming.');
        }
        $t = $this->claimTotals($projectId);
        if ($measured !== null) {
            $e = $this->estimates->approved($projectId) ?? $this->fail('Measured claims need an approved estimate (Estimate tab).');
            $prev = $this->estimates->measuredToDate($projectId);
            $lines = [];
            $grossToDate = '0.00';
            foreach ($e->sections as $s) {
                foreach ($s->items as $it) {
                    $iid = (int) $it->id;
                    $q = isset($measured[$iid]) ? trim(str_replace(',', '', (string) $measured[$iid])) : ($prev[$iid]['qty'] ?? '0');
                    $q = $q === '' ? '0' : $q;
                    if (! is_numeric($q) || (float) $q < 0) {
                        $this->fail("\"{$it->description}\": the quantity done must be a number, 0 or more.");
                    }
                    if ((float) $q > (float) $it->qty + 0.0005) {
                        $this->fail("\"{$it->description}\": ".rtrim(rtrim(number_format((float) $q, 3, '.', ''), '0'), '.').' is more than the '.rtrim(rtrim((string) $it->qty, '0'), '.')." {$it->unit} in the estimate. Record extra work as a variation.");
                    }
                    if ((float) $q <= 0) {
                        continue;
                    }
                    $amt = Money::mul($q, $it->rate);
                    $grossToDate = Money::add($grossToDate, $amt);
                    $lines[] = ['estimate_item_id' => $iid, 'description' => $it->description, 'unit' => $it->unit, 'qty' => $it->qty, 'qty_to_date' => number_format((float) $q, 3, '.', ''), 'rate' => $it->rate, 'amount_to_date' => $amt];
                }
            }
            $gross = Money::sub($grossToDate, $t['claimed']);
            if (! Money::positive($gross)) {
                $this->fail('Nothing new to claim: work measured to date is '.Money::format($grossToDate).' and '.Money::format($t['claimed']).' is already claimed.');
            }
            $pct = min(100, (float) $grossToDate / (float) $contract * 100);

            return ['method' => 'measured', 'items' => $lines] + $this->claimSplit($p, $t, $contract, $pct, $grossToDate, $gross);
        }
        $percent = trim(str_replace(['%', ','], ['', '.'], (string) $percent));
        if ($percent === '' || ! is_numeric($percent) || (float) $percent <= 0 || (float) $percent > 100) {
            $this->fail('Enter the % complete to date, more than 0 and at most 100.');
        }
        if ((float) $percent <= (float) $t['percent']) {
            $this->fail('The last claim was already '.rtrim(rtrim($t['percent'], '0'), '.').'% complete. Enter a higher % to date.');
        }
        $grossToDate = Money::percentOf($contract, $percent);
        $gross = Money::sub($grossToDate, $t['claimed']);
        if (! Money::positive($gross)) {
            $this->fail("Nothing new to claim at $percent% - ".Money::format($t['claimed']).' is already claimed.');
        }

        return ['method' => 'percent'] + $this->claimSplit($p, $t, $contract, (float) $percent, $grossToDate, $gross);
    }

    /** Retention and advance recovery on a claim: the same for both claim methods. */
    protected function claimSplit(object $p, array $t, string $contract, float $percent, string $grossToDate, string $gross): array
    {
        $retention = Money::percentOf($gross, $p->retention_percent);
        $advance = '0.00';
        if (Money::positive($p->advance_amount)) {
            $advance = $percent >= 100 ? $t['advance_left'] : Money::round(bcdiv(bcmul($gross, (string) $p->advance_amount, 8), $contract, 8)); // final claim takes back whatever is left
        }
        if (Money::cmp($advance, $t['advance_left']) > 0) {
            $advance = $t['advance_left'];
        }
        $afterRetention = Money::sub($gross, $retention);
        if (Money::cmp($advance, $afterRetention) > 0) {
            $advance = $afterRetention;
        }

        return ['contract' => $contract, 'percent' => number_format($percent, 3, '.', ''), 'gross_to_date' => $grossToDate, 'previous' => $t['claimed'], 'gross' => $gross, 'retention' => $retention,
            'retention_percent' => $p->retention_percent, 'advance' => $advance, 'advance_left' => $t['advance_left'], 'net' => Money::sub($afterRetention, $advance)];
    }

    protected function guard(?string $date): void
    {
        if ($this->chart->companyLocked()) {
            $this->fail('This company is locked, so nothing can be posted. An administrator can unlock it under Settings.');
        }
        if ($date && ($err = $this->entries->closedPeriodError(substr($date, 0, 10)))) {
            $this->fail($err);
        }
    }

    protected function flag(string $name): ?string
    {
        return $this->db()->table('app_flags')->where('name', $name)->value('value');
    }

    protected function setFlag(string $name, string $value): void
    {
        $this->db()->statement('INSERT INTO app_flags (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$name, $value]);
    }

    /** The two service items a claim invoice uses: 'claim' (the work done) and 'retention' (the "Less retention" line to Retention Receivable). */
    public function serviceItem(string $key): int
    {
        if (! Tables::has('app_flags')) {
            Schema::connection('tenant')->create('app_flags', function (B $t) {
                $t->string('name', 64)->primary();
                $t->string('value', 255)->nullable();
            });
        }
        $flag = "construction_{$key}_item_id";
        if (($id = (int) $this->flag($flag)) > 0 && $this->db()->table('items')->where('id', $id)->exists()) {
            return $id;
        }
        if ($key === 'retention') {
            $ledger = (int) $this->ledgers->ledgerFor('retention')->id;
            $row = ['name' => 'Retention', 'description' => 'Retention held back / released (construction)', 'sales_ledger_id' => $ledger];
        } else {
            // the chart's "Contract Revenue" when there is one, otherwise the default sales account
            $income = (int) $this->chart->topLevelGroupId('income');
            $parents = \App\Models\Tenant\Group::pluck('parent_id', 'id')->all();
            $rev = null;
            foreach (\App\Models\Tenant\Ledger::where('type', 0)->where('name', 'like', '%contract revenue%')->orderBy('id')->get() as $l) {
                for ($g = (int) $l->group_id, $i = 0; $g > 0 && $i < 20; $i++, $g = (int) ($parents[$g] ?? 0)) {
                    if ($g === $income) {
                        $rev = $l;
                        break 2;
                    }
                }
            }
            $salesLedger = $rev ? (int) $rev->id : (int) ($this->sales->settings()->sales_ledger_id ?? 0);
            if (! $salesLedger) {
                $this->fail('Set the sales (income) account under Sales settings first, so progress claims know where to post.');
            }
            $row = ['name' => 'Progress claim (contract work)', 'description' => 'Construction work done to date', 'sales_ledger_id' => $salesLedger];
        }
        $row += ['type' => 'service', 'sales_price' => '0.00', 'expense_ledger_id' => null, 'quantity_on_hand' => '0', 'purchase_cost' => '0.00', 'status' => 1, 'created_at' => now()];
        $cols = Schema::connection('tenant')->getColumnListing('items');
        $id = (int) $this->db()->table('items')->insertGetId(array_intersect_key($row, array_flip($cols)));
        $this->setFlag($flag, (string) $id);

        return $id;
    }

    protected function nextClaimNumber(int $projectId, string $kind): int
    {
        return (int) $this->db()->table('project_claims')->where('project_id', $projectId)->where('kind', $kind)->max('number') + 1;
    }

    /** Builds, saves and issues a claim / retention invoice; returns its id. */
    protected function makeInvoice(object $p, string $date, string $notes, array $lines): int
    {
        $items = array_map(fn ($l) => ['item_id' => $l['item_id'], 'description' => $l['description'], 'qty' => 1, 'unit_price' => $l['amount'], 'amount' => $l['amount'], 'discount_type' => null, 'discount_value' => null], $lines);
        $id = $this->sales->saveInvoice(['customer_id' => $p->customer_id, 'date' => $date, 'due_date' => null, 'notes' => $notes, 'tax_total' => '0.00', 'tax_percent' => null, 'project_id' => (int) $p->id], $items);
        $this->sales->issueInvoice($id);

        return $id;
    }

    /** Create a progress claim: an issued invoice, with the advance taken back by applying the advance payment(s) to it. */
    public function createClaim(int $projectId, array $d): int
    {
        $p = $this->project($projectId) ?? $this->fail('Project not found.');
        if (empty($d['date'])) {
            $this->fail('Enter the claim date.');
        }
        $this->guard($d['date']);
        $measured = ($p->claim_method ?? 'percent') === 'measured' ? (array) ($d['measured'] ?? []) : null;
        $pv = $this->claimPreview($projectId, $d['percent'] ?? '', $measured);
        $claimItem = $this->serviceItem('claim');
        $retItem = Money::positive($pv['retention']) ? $this->serviceItem('retention') : 0;
        $number = $this->nextClaimNumber($projectId, 'claim');
        $pct = rtrim(rtrim($pv['percent'], '0'), '.');
        $lines = [['item_id' => $claimItem, 'amount' => $pv['gross'],
            'description' => $pv['method'] === 'measured'
                ? "Progress claim $number - {$p->name}: work measured to date ".Money::format($pv['gross_to_date'])." ($pct% of ".Money::format($pv['contract']).'), less '.Money::format($pv['previous']).' claimed before - see the measurement sheet'
                : "Progress claim $number - {$p->name}: $pct% complete to date (".Money::format($pv['gross_to_date']).' of '.Money::format($pv['contract']).', less '.Money::format($pv['previous']).' claimed before)']];
        if (Money::positive($pv['retention'])) {
            $lines[] = ['item_id' => $retItem, 'amount' => Money::neg($pv['retention']), 'description' => 'Less retention '.rtrim(rtrim((string) $p->retention_percent, '0'), '.').'% (paid at handover)'];
        }

        $claimId = $this->db()->transaction(function () use ($p, $d, $pv, $lines, $number, $projectId) {
            $invId = $this->makeInvoice($p, $d['date'], trim("Progress claim $number for project #{$p->number} {$p->name}".(! empty($d['notes']) ? '. '.$d['notes'] : '')), $lines);
            $left = $pv['advance'];
            foreach ($this->advances($projectId) as $a) {
                if (! Money::positive($left)) {
                    break;
                }
                if (! Money::positive($a->left)) {
                    continue;
                }
                $take = Money::cmp($a->left, $left) < 0 ? $a->left : $left;
                $this->allocs->allocate('ar', (int) $a->id, [$invId => $take], 'advance');
                $left = Money::sub($left, $take);
            }

            $claimId = $this->db()->table('project_claims')->insertGetId(['project_id' => $projectId, 'kind' => 'claim', 'number' => $number, 'date' => $d['date'], 'percent_to_date' => $pv['percent'],
                'gross_to_date' => $pv['gross_to_date'], 'gross' => $pv['gross'], 'retention' => $pv['retention'], 'advance' => $pv['advance'], 'net' => $pv['net'], 'invoice_id' => $invId,
                'notes' => isset($d['notes']) ? trim((string) $d['notes']) : null, 'created_at' => now()]);
            foreach ($pv['items'] ?? [] as $ci) {
                $this->db()->table('claim_items')->insert(['claim_id' => $claimId, 'estimate_item_id' => $ci['estimate_item_id'], 'qty_to_date' => $ci['qty_to_date'], 'rate' => $ci['rate'], 'amount_to_date' => $ci['amount_to_date']]);
            }

            return $claimId;
        });
        ActivityLog::add("Progress claim $number ($pct%) for project ".$this->label($p));

        return $claimId;
    }

    /** Invoice the retention held so far (at handover). */
    public function releaseRetention(int $projectId, array $d): int
    {
        $p = $this->project($projectId) ?? $this->fail('Project not found.');
        if (empty($d['date'])) {
            $this->fail('Enter the date.');
        }
        $this->guard($d['date']);
        $t = $this->claimTotals($projectId);
        if (! Money::positive($t['retention_due'])) {
            $this->fail('No retention is held on this project.');
        }
        $retItem = $this->serviceItem('retention');
        $number = $this->nextClaimNumber($projectId, 'retention');
        $id = $this->db()->transaction(function () use ($p, $d, $t, $retItem, $number, $projectId) {
            $invId = $this->makeInvoice($p, $d['date'], "Retention release for project #{$p->number} {$p->name}", [['item_id' => $retItem, 'amount' => $t['retention_due'], 'description' => "Retention released - project #{$p->number} {$p->name}"]]);

            return $this->db()->table('project_claims')->insertGetId(['project_id' => $projectId, 'kind' => 'retention', 'number' => $number, 'date' => $d['date'], 'gross' => $t['retention_due'],
                'net' => $t['retention_due'], 'invoice_id' => $invId, 'created_at' => now()]);
        });
        ActivityLog::add('Released retention '.Money::format($t['retention_due']).' on project '.$this->label($p));

        return $id;
    }

    /** Record the client's advance: a customer payment kept unapplied for this project. */
    public function recordAdvance(int $projectId, array $d): int
    {
        $p = $this->project($projectId) ?? $this->fail('Project not found.');

        return $this->db()->transaction(function () use ($p, $d, $projectId) {
            $payId = $this->sales->receivePayment(['customer_id' => $p->customer_id, 'ledger_id' => $d['ledger_id'] ?? null, 'date' => $d['date'] ?? null, 'amount' => $d['amount'] ?? 0, 'wht_rate' => 0,
                'method' => $d['method'] ?? '', 'reference' => $d['reference'] ?? '', 'notes' => "Advance for project #{$p->number} {$p->name}", 'apply' => []]);
            $this->db()->table('project_advances')->insert(['project_id' => $projectId, 'payment_id' => $payId, 'created_at' => now()]);

            return $payId;
        });
    }

    /** The claim behind an invoice (claim invoices can only be voided, not edited). */
    public function claimForInvoice(int $invoiceId): ?object
    {
        return Tables::has('project_claims') ? $this->db()->table('project_claims')->where('invoice_id', $invoiceId)->first() : null;
    }

    /** Cash/bank accounts for the advance and wage payment forms: id => name. */
    public function bankOptions(): array
    {
        return \App\Models\Tenant\Ledger::where('type', 1)->orderBy('name')->pluck('name', 'id')->all();
    }
}
