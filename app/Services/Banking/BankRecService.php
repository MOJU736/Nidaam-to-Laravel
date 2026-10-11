<?php

namespace App\Services\Banking;

use App\Models\Tenant\Ledger;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Reports\ReportService;
use App\Support\Money;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation, QuickBooks style. Ported from the legacy Bankrec_model.
 *
 * One bank_recs row per reconciliation of one account against one statement: the statement's ending date and balance, the
 * beginning balance it started from, and a status (draft | done | undone). The lines ticked as cleared are in bank_rec_items;
 * when a reconciliation is finished each ticked line also gets entryitems.reconciliation_date = the statement date, so
 * "cleared" always means reconciliation_date IS NOT NULL.
 *
 * Beginning balance = the account's opening balance + every line already cleared. Cleared balance = beginning + ticked money in
 * - ticked money out. A reconciliation can be finished when that equals the statement's ending balance.
 */
class BankRecService
{
    public function __construct(protected ReportService $reports, protected ChartOfAccounts $chart) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    /** Older company databases have no reconciliation tables yet; they are made the first time banking is opened. */
    public function ensureTables(): void
    {
        $schema = Schema::connection('tenant');
        if (! $schema->hasTable('bank_recs')) {
            $schema->create('bank_recs', function (Blueprint $t) {
                $t->increments('id');
                $t->bigInteger('ledger_id');
                $t->date('statement_date');
                $t->decimal('statement_balance', 25, 2)->default(0);
                $t->decimal('begin_balance', 25, 2)->default(0);
                $t->string('status', 10)->default('draft');
                $t->dateTime('created_at');
                $t->string('created_by', 191)->default('');
                $t->dateTime('finished_at')->nullable();
                $t->string('finished_by', 191)->nullable();
                $t->index(['ledger_id', 'status'], 'ledger_status');
            });
        }
        if (! $schema->hasTable('bank_rec_items')) {
            $schema->create('bank_rec_items', function (Blueprint $t) {
                $t->unsignedInteger('rec_id');
                $t->bigInteger('entryitem_id');
                $t->primary(['rec_id', 'entryitem_id']);
                $t->index(['entryitem_id'], 'entryitem');
            });
        }
    }

    protected function who(): string
    {
        $u = auth()->user();

        return $u ? (trim(($u->first_name ?? '').' '.($u->last_name ?? '')) ?: (string) ($u->username ?? '')) : '';
    }

    protected function guardLocked(): void
    {
        if ($this->chart->companyLocked()) {
            $this->fail('This company is locked, so nothing can be changed. An administrator can unlock it under Settings.');
        }
    }

    // ---- accounts -------------------------------------------------------------------------

    protected function reconcilable(Ledger $l): bool
    {
        if ((int) $l->type === 1) {
            return true;
        }

        return (int) $l->reconciliation === 1 && in_array($this->chart->typeOfGroup((int) $l->group_id), ['Asset', 'Liability', 'Equity'], true);
    }

    /** Bank / cash accounts and any balance-sheet account set to "reconcile", banks first. */
    public function accounts(): array
    {
        return Ledger::where(fn ($q) => $q->where('reconciliation', 1)->orWhere('type', 1))->orderByDesc('type')->orderBy('name')->get()->filter(fn ($l) => $this->reconcilable($l))->values()->all();
    }

    public function account(int $id): ?Ledger
    {
        $l = Ledger::find($id);

        return $l && $this->reconcilable($l) ? $l : null;
    }

    /** 'D' or 'C': the side money coming into this account is on. */
    public function normal(Ledger $l): string
    {
        return $this->reports->nature($l)['normal'];
    }

    /** Opening balance plus the movement of lines matching $where, on the account's normal side. */
    protected function balanceOf(Ledger $l, ?string $upto, bool $clearedOnly): string
    {
        $normal = $this->normal($l);
        $q = $this->db()->table('entryitems')->join('entries', 'entries.id', '=', 'entryitems.entry_id')->where('entryitems.ledger_id', $l->id)
            ->when($upto, fn ($x) => $x->where('entries.date', '<=', $upto))->when($clearedOnly, fn ($x) => $x->whereNotNull('entryitems.reconciliation_date'))
            ->selectRaw("SUM(CASE WHEN entryitems.dc = 'D' THEN entryitems.amount ELSE 0 END) AS dr, SUM(CASE WHEN entryitems.dc = 'C' THEN entryitems.amount ELSE 0 END) AS cr")->first();
        $net = Money::sub($q->dr ?? 0, $q->cr ?? 0);
        $net = $normal === 'D' ? $net : Money::neg($net);

        return Money::add(Money::signed(['dc' => $l->op_balance_dc, 'amount' => Money::add($l->op_balance ?: 0, 0)], $normal), $net);
    }

    /** Everything in the books for this account (up to a date). */
    public function bookBalance(Ledger $l, ?string $upto = null): string
    {
        return $this->balanceOf($l, $upto, false);
    }

    /** Opening balance + every line cleared so far. */
    public function clearedBalance(Ledger $l): string
    {
        return $this->balanceOf($l, null, true);
    }

    // ---- reconciliations ------------------------------------------------------------------

    public function draftFor(int $ledgerId): ?object
    {
        return $this->db()->table('bank_recs')->where('ledger_id', $ledgerId)->where('status', 'draft')->orderByDesc('id')->first();
    }

    public function lastDone(int $ledgerId): ?object
    {
        return $this->db()->table('bank_recs')->where('ledger_id', $ledgerId)->where('status', 'done')->orderByDesc('statement_date')->orderByDesc('id')->first();
    }

    public function get(int $id): ?object
    {
        return $this->db()->table('bank_recs')->where('id', $id)->first();
    }

    public function history(int $ledgerId): array
    {
        return $this->db()->table('bank_recs')->where('ledger_id', $ledgerId)->whereIn('status', ['done', 'undone'])->orderByDesc('statement_date')->orderByDesc('id')->get()->all();
    }

    /** Start (or change the statement details of) the draft for an account. Returns the reconciliation id. */
    public function start(Ledger $l, string $date, string $balance): int
    {
        $this->guardLocked();
        $last = $this->lastDone($l->id);
        if ($last && $date < substr($last->statement_date, 0, 10)) {
            $this->fail('This account is already reconciled to '.\App\Support\Money::date($last->statement_date).' - the next statement must end on or after that day.');
        }

        return $this->db()->transaction(function () use ($l, $date, $balance) {
            if ($draft = $this->draftFor($l->id)) {
                $this->db()->table('bank_recs')->where('id', $draft->id)->update(['statement_date' => $date, 'statement_balance' => $balance, 'begin_balance' => $this->clearedBalance($l)]);
                // lines after the new statement date can't stay ticked
                $keep = array_column($this->openLines($l, $date), 'id');
                $this->db()->table('bank_rec_items')->where('rec_id', $draft->id)->when($keep, fn ($q) => $q->whereNotIn('entryitem_id', $keep))->delete();

                return (int) $draft->id;
            }

            return (int) $this->db()->table('bank_recs')->insertGetId(['ledger_id' => $l->id, 'statement_date' => $date, 'statement_balance' => $balance, 'begin_balance' => $this->clearedBalance($l),
                'status' => 'draft', 'created_at' => now(), 'created_by' => $this->who()]);
        });
    }

    /** Entry lines of this account not cleared yet and dated on or before the statement date (raw rows). */
    protected function openLines(Ledger $l, string $date): array
    {
        return $this->itemQuery()->where('entryitems.ledger_id', $l->id)->whereNull('entryitems.reconciliation_date')->where('entries.date', '<=', $date)->get()->all();
    }

    protected function itemQuery()
    {
        return $this->db()->table('entryitems')->join('entries', 'entries.id', '=', 'entryitems.entry_id')->leftJoin('entrytypes', 'entrytypes.id', '=', 'entries.entrytype_id')
            ->orderBy('entries.date')->orderBy('entries.id')->orderBy('entryitems.id')
            ->select('entryitems.id', 'entryitems.entry_id', 'entryitems.amount', 'entryitems.dc', 'entryitems.narration', 'entries.date', 'entries.number', 'entries.entrytype_id',
                'entries.notes as entry_notes', 'entrytypes.label as type_label', 'entrytypes.name as type_name');
    }

    /** The lines to tick, described: id, date, type, num, name, memo, in, out (one is null), url, ticked. */
    public function lines(Ledger $l, object $rec): array
    {
        $ticked = ! empty($rec->id) ? array_flip($this->db()->table('bank_rec_items')->where('rec_id', $rec->id)->pluck('entryitem_id')->map(fn ($i) => (int) $i)->all()) : [];

        return $this->describe($this->openLines($l, substr((string) $rec->statement_date, 0, 10)), $l, $ticked);
    }

    /** Lines cleared by a finished reconciliation, described like lines(). */
    public function clearedLines(Ledger $l, object $rec): array
    {
        $rows = $this->itemQuery()->join('bank_rec_items', 'bank_rec_items.entryitem_id', '=', 'entryitems.id')->where('bank_rec_items.rec_id', $rec->id)->get()->all();

        return $this->describe($rows, $l, null);
    }

    protected function describe(array $items, Ledger $l, ?array $ticked): array
    {
        $normal = $this->normal($l);
        $ids = array_column($items, 'entry_id');
        $docs = $this->reports->entryDocs($ids);
        $splits = $this->reports->entrySplits($ids, (int) $l->id);
        $types = \App\Models\Tenant\EntryType::get()->keyBy('id');
        $entries = app(\App\Services\Accounting\EntryService::class);
        $out = [];
        foreach ($items as $it) {
            $eid = (int) $it->entry_id;
            $doc = $docs[$eid] ?? null;
            $isIn = $it->dc === $normal;
            $type = $types->get($it->entrytype_id);
            $out[] = ['id' => (int) $it->id, 'date' => $it->date,
                'type' => $doc['type'] ?? (\App\Services\Accounting\EntryService::NAMES[$it->type_label] ?? ($it->type_name ?: 'Journal')),
                'num' => $doc ? $doc['num'] : ($type ? $entries->entryNumber($it->number, $type) : (string) $it->number),
                'name' => $doc ? $doc['name'] : ($splits[$eid] ?? ''), 'memo' => (string) (($it->narration !== null && $it->narration !== '') ? $it->narration : $it->entry_notes),
                'in' => $isIn ? Money::add($it->amount, 0) : null, 'out' => $isIn ? null : Money::add($it->amount, 0),
                'url' => ($doc['url'] ?? null) ?: route('entries.show', [$it->type_label, $eid]), 'ticked' => $ticked === null ? true : isset($ticked[(int) $it->id])];
        }

        return $out;
    }

    /** Save which lines are ticked on a draft (only lines that may be ticked). Returns how many. */
    public function saveTicks(Ledger $l, object $rec, array $ids): int
    {
        $this->guardLocked();
        $allowed = array_flip(array_map(fn ($r) => (int) $r->id, $this->openLines($l, substr((string) $rec->statement_date, 0, 10))));
        $keep = [];
        foreach ($ids as $id) {
            if (isset($allowed[(int) $id])) {
                $keep[(int) $id] = true;
            }
        }
        $this->db()->transaction(function () use ($rec, $keep) {
            $this->db()->table('bank_rec_items')->where('rec_id', $rec->id)->delete();
            foreach (array_keys($keep) as $id) {
                $this->db()->table('bank_rec_items')->insert(['rec_id' => $rec->id, 'entryitem_id' => $id]);
            }
        });

        return count($keep);
    }

    /** Ticked in / out, the cleared balance and the difference from the statement. */
    public function totals(object $rec, array $lines): array
    {
        $in = $out = '0.00';
        $nIn = $nOut = 0;
        foreach ($lines as $x) {
            if (! $x['ticked']) {
                continue;
            }
            if ($x['in'] !== null) {
                $in = Money::add($in, $x['in']);
                $nIn++;
            } else {
                $out = Money::add($out, $x['out']);
                $nOut++;
            }
        }
        $cleared = Money::sub(Money::add($rec->begin_balance, $in), $out);

        return ['in' => $in, 'out' => $out, 'n_in' => $nIn, 'n_out' => $nOut, 'cleared' => $cleared, 'difference' => Money::sub($rec->statement_balance, $cleared)];
    }

    /** Finish a draft when the difference is zero. */
    public function finish(Ledger $l, object $rec): void
    {
        $this->guardLocked();
        $rec->begin_balance = $this->clearedBalance($l);
        $lines = $this->lines($l, $rec);
        $t = $this->totals($rec, $lines);
        if (! Money::isZero($t['difference'])) {
            $this->fail('The difference is '.Money::format($t['difference']).' - it must be zero to finish. Tick or untick lines until it is, or save for later.');
        }
        $ids = array_column(array_filter($lines, fn ($x) => $x['ticked']), 'id');
        $this->db()->transaction(function () use ($rec, $ids) {
            if ($ids) {
                $this->db()->table('entryitems')->whereIn('id', $ids)->update(['reconciliation_date' => substr((string) $rec->statement_date, 0, 10)]);
            }
            $this->db()->table('bank_recs')->where('id', $rec->id)->update(['status' => 'done', 'begin_balance' => $rec->begin_balance, 'finished_at' => now(), 'finished_by' => $this->who()]);
        });
        ActivityLog::add("Reconciled {$l->name} to the statement of ".substr((string) $rec->statement_date, 0, 10).' ('.count($ids).' lines cleared)');
    }

    /** Undo the newest finished reconciliation of an account. */
    public function undo(Ledger $l, object $rec): void
    {
        $this->guardLocked();
        $last = $this->lastDone($l->id);
        if (! $last || (int) $last->id !== (int) $rec->id) {
            $this->fail('Only the most recent reconciliation can be undone.');
        }
        if ($this->draftFor($l->id)) {
            $this->fail('Finish or discard the reconciliation in progress first.');
        }
        $ids = $this->db()->table('bank_rec_items')->where('rec_id', $rec->id)->pluck('entryitem_id')->map(fn ($i) => (int) $i)->all();
        $this->db()->transaction(function () use ($rec, $ids) {
            if ($ids) {
                $this->db()->table('entryitems')->whereIn('id', $ids)->update(['reconciliation_date' => null]);
            }
            $this->db()->table('bank_recs')->where('id', $rec->id)->update(['status' => 'undone']);
        });
        ActivityLog::add("Undid the reconciliation of {$l->name} to ".substr((string) $rec->statement_date, 0, 10));
    }

    /** Throw away a draft. */
    public function discard(object $rec): void
    {
        $this->guardLocked();
        $this->db()->table('bank_rec_items')->where('rec_id', $rec->id)->delete();
        $this->db()->table('bank_recs')->where('id', $rec->id)->where('status', 'draft')->delete();
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['banking' => $message]);
    }
}
