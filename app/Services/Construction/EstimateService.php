<?php

namespace App\Services\Construction;

use App\Services\Accounting\ActivityLog;
use App\Services\Auth\Permissions;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Construction - estimates (bill of quantities), the rate list and units (legacy Construction_model). An estimate has sections and items
 * (unit, qty, rate, cost type). Approving it sets the project's contract (before tax) and its budgets, per cost type and per section, at
 * the estimate amounts less the expected margin %. Measured progress claims use the approved estimate's items.
 */
class EstimateService
{
    /** Standard units; a company can add its own. */
    public const UNITS = ['m', 'm²', 'm³', 'kg', 'ton', 'pcs', 'bag', 'trip', 'day', 'LS'];

    public function __construct(protected Permissions $permissions) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['projects' => $message]);
    }

    /** Admins and managers approve estimates and site reports. */
    public function canApprove(): bool
    {
        $u = auth()->user();

        return $u && ($u->isAdmin() || $this->permissions->groupName($u) === 'manager');
    }

    public function who(): string
    {
        $u = auth()->user();

        return $u ? (trim(($u->first_name ?? '').' '.($u->last_name ?? '')) ?: (string) ($u->username ?? '')) : '';
    }

    /** "1,250.5" -> "1250.50"; null when it is not a number or is negative. */
    protected function num($v, int $dp = 2): ?string
    {
        $v = str_replace([',', ' '], '', (string) $v);
        $v = $v === '' ? '0' : $v;
        if (! is_numeric($v) || (float) $v < 0) {
            return null;
        }

        return number_format(round((float) $v, $dp), $dp, '.', '');
    }

    // ---- units and the rate list ------------------------------------------------------------------------------------

    public function units(): array
    {
        $out = self::UNITS;
        foreach ($this->db()->table('construction_units')->orderBy('name')->pluck('name') as $n) {
            if (! in_array($n, $out, true)) {
                $out[] = $n;
            }
        }

        return $out;
    }

    public function addUnit(string $name): void
    {
        $name = mb_substr(trim($name), 0, 20);
        if ($name === '') {
            $this->fail('Type the unit, e.g. roll.');
        }
        if (in_array(mb_strtolower($name), array_map('mb_strtolower', $this->units()), true)) {
            $this->fail('That unit is already in the list.');
        }
        $this->db()->table('construction_units')->insert(['name' => $name]);
    }

    public function deleteUnit(string $name): void
    {
        $this->db()->table('construction_units')->where('name', $name)->delete();
    }

    public function rates()
    {
        return $this->db()->table('construction_rates')->orderBy('description')->get();
    }

    public function saveRate(array $d, ?int $id = null): void
    {
        $desc = mb_substr(trim((string) ($d['description'] ?? '')), 0, 500);
        if ($desc === '') {
            $this->fail('Type the description.');
        }
        $rate = $this->num($d['rate'] ?? '') ?? $this->fail('The rate must be a number, 0 or more.');
        $type = array_key_exists($d['cost_type'] ?? '', ConstructionService::COST_TYPES) ? $d['cost_type'] : 'materials';
        $row = ['description' => $desc, 'unit' => mb_substr(trim((string) ($d['unit'] ?? '')), 0, 20), 'rate' => $rate, 'cost_type' => $type, 'updated_at' => now()];
        if ($id) {
            $this->db()->table('construction_rates')->where('id', $id)->update($row);
        } elseif ($same = $this->db()->table('construction_rates')->where('description', $desc)->first()) {
            $this->db()->table('construction_rates')->where('id', $same->id)->update($row);
        } else {
            $this->db()->table('construction_rates')->insert($row);
        }
    }

    public function deleteRate(int $id): void
    {
        $this->db()->table('construction_rates')->where('id', $id)->delete();
    }

    // ---- estimates ------------------------------------------------------------------------------------------------------

    public function estimates(int $projectId)
    {
        return $this->db()->table('estimates')->where('project_id', $projectId)->orderByDesc('number')->get();
    }

    /** An estimate with its sections, each with its items and total. */
    public function estimate(int $id): ?object
    {
        $e = $this->db()->table('estimates')->where('id', $id)->first();
        if (! $e) {
            return null;
        }
        $items = $this->db()->table('estimate_items')->where('estimate_id', $id)->orderBy('sort')->orderBy('id')->get();
        $e->sections = $this->db()->table('estimate_sections')->where('estimate_id', $id)->orderBy('sort')->orderBy('id')->get()->each(function ($s) use ($items) {
            $s->items = $items->where('section_id', $s->id)->values();
            $s->total = $s->items->reduce(fn ($c, $i) => Money::add($c, $i->amount), '0.00');
        });

        return $e;
    }

    /** The estimate to show on the project: a newer draft (a revision) if there is one, else the approved one, else the newest. */
    public function current(int $projectId): ?object
    {
        $eid = $this->db()->table('projects')->where('id', $projectId)->value('estimate_id');
        if ($eid && $this->db()->table('estimates')->where('id', $eid)->exists()) {
            $draft = $this->db()->table('estimates')->where('project_id', $projectId)->where('status', 'draft')->where('id', '>', $eid)->orderByDesc('id')->value('id');

            return $this->estimate((int) ($draft ?: $eid));
        }
        $id = $this->db()->table('estimates')->where('project_id', $projectId)->orderByDesc('id')->value('id');

        return $id ? $this->estimate((int) $id) : null;
    }

    public function approved(int $projectId): ?object
    {
        $eid = $this->db()->table('projects')->where('id', $projectId)->value('estimate_id');

        return $eid ? $this->estimate((int) $eid) : null;
    }

    /** A new draft: empty (with the usual sections) or a copy of another estimate. Returns its id. */
    public function create(int $projectId, ?int $fromId = null): int
    {
        if ($this->db()->table('estimates')->where('project_id', $projectId)->where('status', 'draft')->exists()) {
            $this->fail('This project already has a draft estimate - open it from the Estimate tab.');
        }
        $from = $fromId ? $this->estimate($fromId) : null;
        $num = (int) $this->db()->table('estimates')->where('project_id', $projectId)->max('number') + 1;

        return $this->db()->transaction(function () use ($projectId, $from, $num) {
            $id = $this->db()->table('estimates')->insertGetId(['project_id' => $projectId, 'number' => $num, 'status' => 'draft', 'date' => now()->toDateString(), 'title' => $from->title ?? 'Bill of Quantities',
                'tax_percent' => $from->tax_percent ?? 0, 'tax_name' => $from->tax_name ?? null, 'margin_percent' => $from->margin_percent ?? 0, 'notes' => $from->notes ?? null, 'created_at' => now()]);
            if ($from) {
                foreach ($from->sections as $s) {
                    $sid = $this->db()->table('estimate_sections')->insertGetId(['estimate_id' => $id, 'name' => $s->name, 'sort' => $s->sort]);
                    foreach ($s->items as $it) {
                        $this->db()->table('estimate_items')->insert(['estimate_id' => $id, 'section_id' => $sid, 'description' => $it->description, 'unit' => $it->unit, 'qty' => $it->qty, 'rate' => $it->rate,
                            'amount' => $it->amount, 'cost_type' => $it->cost_type, 'sort' => $it->sort]);
                    }
                }
                $this->totals($id);
            } else {
                foreach (['Preliminaries', 'Substructure', 'Superstructure', 'Finishes', 'Services (MEP)', 'External works'] as $i => $n) {
                    $this->db()->table('estimate_sections')->insert(['estimate_id' => $id, 'name' => $n, 'sort' => $i]);
                }
            }
            ActivityLog::add("Started estimate $num for project #$projectId");

            return $id;
        });
    }

    protected function totals(int $id): void
    {
        $e = $this->db()->table('estimates')->where('id', $id)->first();
        $sub = Money::round($this->db()->table('estimate_items')->where('estimate_id', $id)->sum('amount'));
        $tax = Money::percentOf($sub, $e->tax_percent);
        $this->db()->table('estimates')->where('id', $id)->update(['subtotal' => $sub, 'tax_total' => $tax, 'total' => Money::add($sub, $tax)]);
    }

    /**
     * Saves a draft from the editor. $in: date, title, tax_percent, tax_name, margin_percent, notes, sections = [[name, items => [[description, unit, qty, rate, cost_type]]]].
     * $saveRates also puts new descriptions in the rate list.
     */
    public function save(int $id, array $in, bool $saveRates = false): void
    {
        $e = $this->db()->table('estimates')->where('id', $id)->first() ?? $this->fail('That estimate no longer exists.');
        if ($e->status !== 'draft') {
            $this->fail("An approved estimate can't be changed. Start a revision instead.");
        }
        $date = (string) ($in['date'] ?? '');
        if (! \App\Services\Settings\SettingsService::isDate($date)) {
            $this->fail('Enter the estimate date.');
        }
        $tax = trim(str_replace('%', '', (string) ($in['tax_percent'] ?? ''))) ?: '0';
        if (! is_numeric($tax) || (float) $tax < 0 || (float) $tax > 100) {
            $this->fail('Tax must be a % from 0 to 100.');
        }
        $margin = trim(str_replace('%', '', (string) ($in['margin_percent'] ?? ''))) ?: '0';
        if (! is_numeric($margin) || (float) $margin < 0 || (float) $margin >= 100) {
            $this->fail('The expected margin must be a % from 0 to less than 100.');
        }
        $clean = [];
        foreach ((array) ($in['sections'] ?? []) as $s) {
            $name = mb_substr(trim((string) ($s['name'] ?? '')), 0, 255);
            $items = [];
            foreach ((array) ($s['items'] ?? []) as $it) {
                $desc = mb_substr(trim((string) ($it['description'] ?? '')), 0, 500);
                $qty = $this->num($it['qty'] ?? '', 3);
                $rate = $this->num($it['rate'] ?? '');
                if ($desc === '' && (float) $qty == 0 && (float) $rate == 0) {
                    continue;
                }
                if ($desc === '') {
                    $this->fail('A line in "'.($name ?: 'a section').'" has no description.');
                }
                $qty ?? $this->fail("\"$desc\": the quantity must be a number, 0 or more.");
                $rate ?? $this->fail("\"$desc\": the rate must be a number, 0 or more.");
                $items[] = ['description' => $desc, 'unit' => mb_substr(trim((string) ($it['unit'] ?? '')), 0, 20), 'qty' => $qty, 'rate' => $rate, 'amount' => Money::mul($qty, $rate),
                    'cost_type' => array_key_exists($it['cost_type'] ?? '', ConstructionService::COST_TYPES) ? $it['cost_type'] : 'materials'];
            }
            if ($name === '' && ! $items) {
                continue;
            }
            $clean[] = ['name' => $name ?: 'Section '.(count($clean) + 1), 'items' => $items];
        }
        $this->db()->transaction(function () use ($id, $in, $date, $tax, $margin, $clean, $saveRates) {
            $this->db()->table('estimates')->where('id', $id)->update(['date' => $date, 'title' => mb_substr(trim((string) ($in['title'] ?? '')), 0, 255) ?: 'Bill of Quantities',
                'tax_percent' => number_format((float) $tax, 3, '.', ''), 'tax_name' => mb_substr(trim((string) ($in['tax_name'] ?? '')), 0, 100) ?: null,
                'margin_percent' => number_format((float) $margin, 2, '.', ''), 'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 2000) ?: null]);
            $this->db()->table('estimate_items')->where('estimate_id', $id)->delete();
            $this->db()->table('estimate_sections')->where('estimate_id', $id)->delete();
            foreach ($clean as $si => $s) {
                $sid = $this->db()->table('estimate_sections')->insertGetId(['estimate_id' => $id, 'name' => $s['name'], 'sort' => $si]);
                foreach ($s['items'] as $ii => $it) {
                    $this->db()->table('estimate_items')->insert(['estimate_id' => $id, 'section_id' => $sid, 'sort' => $ii] + $it);
                    if ($saveRates && ! $this->db()->table('construction_rates')->where('description', $it['description'])->exists()) {
                        $this->saveRate($it);
                    }
                }
            }
            $this->totals($id);
        });
    }

    public function delete(int $id): int
    {
        $e = $this->db()->table('estimates')->where('id', $id)->first();
        if (! $e || $e->status !== 'draft') {
            $this->fail('Only a draft estimate can be deleted.');
        }
        $this->db()->transaction(function () use ($id) {
            $this->db()->table('estimate_items')->where('estimate_id', $id)->delete();
            $this->db()->table('estimate_sections')->where('estimate_id', $id)->delete();
            $this->db()->table('estimates')->where('id', $id)->delete();
        });

        return (int) $e->project_id;
    }

    /**
     * Approve: the project's contract = the estimate before tax; budgets per cost type and per section = estimate amounts less the margin %.
     * A revision can only be approved while nothing has been claimed yet - after that, extra or removed work goes in Variations.
     */
    public function approve(int $id): void
    {
        if (! $this->canApprove()) {
            $this->fail('Only an administrator or manager can approve an estimate.');
        }
        $e = $this->estimate($id);
        if (! $e || $e->status !== 'draft') {
            $this->fail('Only a draft estimate can be approved.');
        }
        if (! Money::positive($e->subtotal)) {
            $this->fail('The estimate has no amounts yet.');
        }
        $pid = (int) $e->project_id;
        $live = $this->db()->table('project_claims as c')->join('invoices as i', 'i.id', '=', 'c.invoice_id')->where('c.project_id', $pid)->where('c.kind', 'claim')->where('i.status', 'issued')->exists();
        if ($live) {
            $this->fail("This project already has progress claims, so its contract can't be replaced. Record extra or removed work under Variations instead.");
        }
        $keep = bcdiv(bcsub('100', (string) $e->margin_percent, 4), '100', 6);
        $byType = array_fill_keys(array_keys(ConstructionService::COST_TYPES), '0.00');
        $this->db()->transaction(function () use ($e, $pid, $keep, &$byType, $id) {
            $this->db()->table('project_section_budgets')->where('project_id', $pid)->delete();
            foreach ($e->sections as $si => $s) {
                if ($s->items->isEmpty()) {
                    continue;
                }
                foreach ($s->items as $it) {
                    $byType[$it->cost_type] = Money::add($byType[$it->cost_type], $it->amount);
                }
                $this->db()->table('project_section_budgets')->insert(['project_id' => $pid, 'name' => $s->name, 'sort' => $si, 'estimate_amount' => $s->total, 'budget' => Money::round(bcmul((string) $s->total, $keep, 8))]);
            }
            foreach ($byType as $k => $amt) {
                $this->db()->table('project_budgets')->updateOrInsert(['project_id' => $pid, 'cost_type' => $k], ['amount' => Money::round(bcmul($amt, $keep, 8))]);
            }
            $this->db()->table('estimates')->where('project_id', $pid)->where('status', 'approved')->update(['status' => 'replaced']);
            $this->db()->table('estimates')->where('id', $id)->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->who()]);
            $this->db()->table('projects')->where('id', $pid)->update(['contract_amount' => $e->subtotal, 'estimate_id' => $id]);
        });
        ActivityLog::add("Approved estimate {$e->number} for project #$pid: contract {$e->subtotal}");
    }

    public function sectionBudgets(int $projectId)
    {
        return $this->db()->table('project_section_budgets')->where('project_id', $projectId)->orderBy('sort')->get();
    }

    /** Latest measured qty / amount to date per estimate item, from the live (issued) measured claims: [item id => ['qty', 'amount']]. */
    public function measuredToDate(int $projectId): array
    {
        $out = [];
        $claims = $this->db()->table('project_claims as c')->join('invoices as i', 'i.id', '=', 'c.invoice_id')->where('c.project_id', $projectId)->where('c.kind', 'claim')->where('i.status', 'issued')
            ->orderBy('c.id')->pluck('c.id');
        foreach ($claims as $cid) {
            foreach ($this->db()->table('claim_items')->where('claim_id', $cid)->get() as $ci) {
                $out[(int) $ci->estimate_item_id] = ['qty' => $ci->qty_to_date, 'amount' => $ci->amount_to_date];
            }
        }

        return $out;
    }

    /** Work done per section from the measured claims (qty to date x rate): [section name => amount]. */
    public function sectionProgress(int $projectId): array
    {
        $e = $this->approved($projectId);
        if (! $e) {
            return [];
        }
        $done = $this->measuredToDate($projectId);
        $out = [];
        foreach ($e->sections as $s) {
            $out[$s->name] = $s->items->reduce(fn ($c, $it) => Money::add($c, $done[(int) $it->id]['amount'] ?? 0), '0.00');
        }

        return $out;
    }
}
