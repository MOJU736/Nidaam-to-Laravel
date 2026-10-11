<?php

namespace App\Services\Sales;

use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which payment (or credit note) paid which invoice / bill. Ported from the legacy Allocations_model.
 *
 * Two sides share the same code:
 *   'ar'  customer payments / credit notes  -> invoices
 *   'ap'  vendor payments / supplier credits -> bills
 *
 * A "source" is a payment ('pay') or an issued credit note ('credit'). One row in payment_allocations says
 * "this much of source S went to document D". It never touches the ledger - the money was already posted when
 * the payment was recorded - so applying, re-applying or removing never changes a balance or a report; it only
 * changes which documents show as paid. What is left on a source is "unapplied": kept as the party's credit.
 *
 * Rules: a document can't take more than its total; a source can't give more than it has left (amount - applied -
 * refunded); both belong to the same customer/vendor; only a live source and an issued document count.
 */
class Allocations
{
    protected array $sides = [
        'ar' => ['doc' => 'invoices', 'pay' => 'payments', 'party' => 'customer_id', 'live' => 'received', 'prefix' => 'INV-', 'credit' => 'CN-'],
        'ap' => ['doc' => 'bills', 'pay' => 'vendor_payments', 'party' => 'vendor_id', 'live' => 'paid', 'prefix' => 'BILL-', 'credit' => 'VC-'],
    ];

    protected function db()
    {
        return DB::connection('tenant');
    }

    protected function cfg(string $side): array
    {
        return $this->sides[$side] ?? throw new \InvalidArgumentException("Unknown allocation side: $side");
    }

    /** Where a source of this type lives. */
    protected function src(string $side, string $type): array
    {
        $c = $this->cfg($side);

        return match ($type) {
            'credit' => ['table' => 'credit_notes', 'party' => 'party_id', 'amount' => 'total', 'live' => 'issued', 'prefix' => $c['credit'], 'where' => ['side' => $side]],
            'pay' => ['table' => $c['pay'], 'party' => $c['party'], 'amount' => 'amount', 'live' => $c['live'], 'prefix' => '', 'where' => []],
        };
    }

    public static function docLabel(string $prefix, $number): string
    {
        return $prefix === '' ? (string) (int) $number : $prefix.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    protected function source(string $side, string $type, int $id): ?object
    {
        $s = $this->src($side, $type);

        return $this->db()->table($s['table'])->where($s['where'])->where('id', $id)->first();
    }

    /** A vendor payment posted straight to an expense account never went to Accounts Payable, so it can't pay a bill. */
    protected function allocatable(string $side, object $pay): bool
    {
        if ($side !== 'ap' || empty($pay->posted_ledger_id)) {
            return true;
        }
        $ledger = $this->db()->table('vendors')->where('id', $pay->vendor_id)->value('ledger_id');

        return (int) $ledger === (int) $pay->posted_ledger_id;
    }

    // ---- reading --------------------------------------------------------------

    /** Sum allocated to each document: [doc id => amount]. */
    public function paidByDoc(string $side, ?array $docIds = null): array
    {
        if ($docIds !== null && ! $docIds) {
            return [];
        }

        return $this->db()->table('payment_allocations')->where('side', $side)
            ->when($docIds !== null, fn ($q) => $q->whereIn('doc_id', array_map('intval', $docIds)))
            ->groupBy('doc_id')->selectRaw('doc_id, SUM(amount) AS paid')->pluck('paid', 'doc_id')->all();
    }

    /** Sum allocated from each source: [source id => amount]. */
    public function appliedBySource(string $side, array $ids, string $type = 'pay'): array
    {
        if (! $ids) {
            return [];
        }

        return $this->db()->table('payment_allocations')->where('side', $side)->where('pay_type', $type)->whereIn('payment_id', array_map('intval', $ids))
            ->groupBy('payment_id')->selectRaw('payment_id, SUM(amount) AS applied')->pluck('applied', 'payment_id')->all();
    }

    /** Cash refunded from each source: [source id => amount]. */
    public function refundedBySource(string $side, array $ids, string $type): array
    {
        if (! $ids) {
            return [];
        }

        return $this->db()->table('credit_refunds')->where('side', $side)->where('src_type', $type)->whereIn('src_id', array_map('intval', $ids))
            ->groupBy('src_id')->selectRaw('src_id, SUM(amount) AS refunded')->pluck('refunded', 'src_id')->all();
    }

    /** Still free to apply or refund: amount - applied - refunded. Zero for a source that isn't live. */
    public function available(string $side, string $type, int $id, ?object $row = null): string
    {
        $s = $this->src($side, $type);
        $row ??= $this->source($side, $type, $id);
        if (! $row || $row->status !== $s['live'] || ($type === 'pay' && ! $this->allocatable($side, $row))) {
            return '0.00';
        }
        $applied = $this->appliedBySource($side, [$id], $type)[$id] ?? 0;
        $refunded = $this->refundedBySource($side, [$id], $type)[$id] ?? 0;

        return Money::sub(Money::sub($row->{$s['amount']}, $applied), $refunded);
    }

    /**
     * Adds paid / balance_due / pay_status to documents (each needs id, status, total, due_date).
     * pay_status: paid | partial | unpaid | overdue for issued documents, null for drafts and voids.
     */
    public function decorate(string $side, iterable $docs): array
    {
        $docs = collect($docs)->map(fn ($d) => (object) (array) $d)->all();
        $issued = array_map(fn ($d) => $d->id, array_filter($docs, fn ($d) => $d->status === 'issued'));
        $paid = $this->paidByDoc($side, $issued);
        $today = now()->toDateString();

        foreach ($docs as $d) {
            if ($d->status !== 'issued') {
                $d->paid = $d->balance_due = $d->pay_status = null;

                continue;
            }
            $d->paid = Money::add($paid[$d->id] ?? 0, 0);
            $d->balance_due = Money::sub($d->total, $d->paid);
            $d->pay_status = ! Money::positive($d->balance_due) ? 'paid'
                : (! empty($d->due_date) && substr((string) $d->due_date, 0, 10) < $today ? 'overdue'
                : (Money::positive($d->paid) ? 'partial' : 'unpaid'));
        }

        return $docs;
    }

    /** Issued documents of one party that still have something to pay, oldest first. */
    public function openDocs(string $side, int $partyId): array
    {
        $c = $this->cfg($side);
        $docs = $this->db()->table($c['doc'])->where($c['party'], $partyId)->where('status', 'issued')
            ->orderBy('date')->orderBy('id')->get(['id', 'number', 'date', 'due_date', 'total', 'status']);

        return collect($this->decorate($side, $docs))->filter(fn ($d) => Money::positive($d->balance_due))
            ->each(fn ($d) => $d->label = self::docLabel($c['prefix'], $d->number))->values()->all();
    }

    /** Live sources of one type for one party, oldest first, with applied / refunded / unapplied / label. */
    protected function liveSources(string $side, string $type, int $partyId): array
    {
        $s = $this->src($side, $type);
        $rows = $this->db()->table($s['table'])->where($s['where'])->where($s['party'], $partyId)->where('status', $s['live'])
            ->when($type === 'credit', fn ($q) => $q->where('kind', '!=', 'writeoff')) // a write-off is never spare credit
            ->orderBy('date')->orderBy('id')->get();
        if ($type === 'pay') {
            $rows = $rows->filter(fn ($r) => $this->allocatable($side, $r));
        }
        $ids = $rows->pluck('id')->all();
        $applied = $this->appliedBySource($side, $ids, $type);
        $refunded = $this->refundedBySource($side, $ids, $type);

        return $rows->each(function ($r) use ($s, $type, $applied, $refunded) {
            $r->type = $type;
            $r->applied = Money::add($applied[$r->id] ?? 0, 0);
            $r->refunded = Money::add($refunded[$r->id] ?? 0, 0);
            $r->unapplied = Money::sub(Money::sub($r->{$s['amount']}, $r->applied), $r->refunded);
            $r->label = self::docLabel($s['prefix'], $r->number);
        })->values()->all();
    }

    /** Everything one party has that can still pay a document (payments and credit notes with money left), oldest first. */
    public function openCredits(string $side, int $partyId): array
    {
        $out = [];
        foreach (['pay', 'credit'] as $type) {
            foreach ($this->liveSources($side, $type, $partyId) as $r) {
                if (Money::positive($r->unapplied)) {
                    $out[] = $r;
                }
            }
        }
        usort($out, fn ($a, $b) => [(string) $a->date, $a->id] <=> [(string) $b->date, $b->id]);

        return $out;
    }

    public function unappliedTotal(string $side, int $partyId): string
    {
        return array_reduce($this->openCredits($side, $partyId), fn ($c, $r) => Money::add($c, $r->unapplied), '0.00');
    }

    /** Allocation rows for one document: each with the source's number, date and label. */
    public function forDoc(string $side, int $docId): array
    {
        $rows = [];
        foreach (['pay', 'credit'] as $type) {
            $s = $this->src($side, $type);
            $found = $this->db()->table('payment_allocations as a')->join("{$s['table']} as p", 'p.id', '=', 'a.payment_id')
                ->where('a.side', $side)->where('a.pay_type', $type)->where('a.doc_id', $docId)
                ->get(array_merge(['a.*', 'p.number', 'p.date'], $type === 'credit' ? ['p.kind'] : []));
            foreach ($found as $r) {
                $r->label = self::docLabel(($r->kind ?? '') === 'writeoff' ? 'WO-' : $s['prefix'], $r->number);
                $rows[] = $r;
            }
        }
        usort($rows, fn ($a, $b) => strcmp((string) $a->date, (string) $b->date));

        return $rows;
    }

    /** Allocation rows for one source, with the document's number, date, total and label. */
    public function forSource(string $side, int $id, string $type = 'pay'): array
    {
        $c = $this->cfg($side);

        return $this->db()->table('payment_allocations as a')->join("{$c['doc']} as d", 'd.id', '=', 'a.doc_id')
            ->where('a.side', $side)->where('a.pay_type', $type)->where('a.payment_id', $id)->orderBy('d.date')
            ->get(['a.*', 'd.number', 'd.date', 'd.total'])->each(fn ($r) => $r->label = self::docLabel($c['prefix'], $r->number))->all();
    }

    public function hasRefunds(string $side, string $type, int $id): bool
    {
        return $this->db()->table('credit_refunds')->where('side', $side)->where('src_type', $type)->where('src_id', $id)->exists();
    }

    // ---- changing ------------------------------------------------------------------

    /**
     * Apply one source to documents: $amounts = [doc id => amount]. Adds to anything this source already has on the
     * same document. Every rule is checked first; nothing is written unless all of them pass.
     */
    public function allocate(string $side, int $sourceId, array $amounts, string $origin = 'manual', string $type = 'pay'): void
    {
        $c = $this->cfg($side);
        $s = $this->src($side, $type);
        $what = $type === 'credit' ? 'credit note' : 'payment';
        $pay = $this->source($side, $type, $sourceId);

        if (! $pay || $pay->status !== $s['live']) {
            $this->fail("That $what can't be applied (it may be a draft or have been voided).");
        }
        if ($type === 'pay' && ! $this->allocatable($side, $pay)) {
            $this->fail("This payment was posted straight to an expense account, not to the vendor's Accounts Payable, so it can't pay a bill.");
        }
        if ($type === 'credit' && ($pay->kind ?? '') === 'writeoff' && $origin !== 'writeoff') {
            $this->fail('A write-off can only clear the invoice it was made for.');
        }

        $clean = [];
        foreach ($amounts as $docId => $amount) {
            $amount = str_replace(',', '', trim((string) $amount));
            if ($amount === '' || ! is_numeric($amount)) {
                continue;
            }
            $amount = Money::round($amount);
            if (Money::positive($amount)) {
                $clean[(int) $docId] = $amount;
            }
        }
        if (! $clean) {
            return;
        }

        $available = $this->available($side, $type, $sourceId, $pay);
        $sum = array_reduce($clean, fn ($c2, $a) => Money::add($c2, $a), '0.00');
        if (Money::cmp($sum, $available) > 0) {
            $this->fail('You tried to apply '.Money::format($sum).' but only '.Money::format($available)." of this $what is left to apply.");
        }

        $docs = $this->db()->table($c['doc'])->whereIn('id', array_keys($clean))->get()->keyBy('id');
        $paid = $this->paidByDoc($side, array_keys($clean));
        foreach ($clean as $docId => $amount) {
            $d = $docs->get($docId) ?? $this->fail('One of the documents no longer exists.');
            $label = self::docLabel($c['prefix'], $d->number);
            if ((int) $d->{$c['party']} !== (int) $pay->{$s['party']}) {
                $this->fail("$label belongs to someone else.");
            }
            if ($d->status !== 'issued') {
                $this->fail("$label isn't issued, so it can't be paid.");
            }
            $due = Money::sub($d->total, $paid[$docId] ?? 0);
            if (Money::cmp($amount, $due) > 0) {
                $this->fail("$label only has ".Money::format($due).' left to pay.');
            }
        }

        foreach ($clean as $docId => $amount) {
            $q = $this->db()->table('payment_allocations')->where(['side' => $side, 'pay_type' => $type, 'payment_id' => $sourceId, 'doc_id' => $docId]);
            if ($existing = (clone $q)->first()) {
                $q->update(['amount' => Money::add($existing->amount, $amount)]);
            } else {
                $this->db()->table('payment_allocations')->insert(['side' => $side, 'pay_type' => $type, 'payment_id' => $sourceId, 'doc_id' => $docId,
                    'amount' => $amount, 'source' => $origin, 'created_at' => now()]);
            }
        }
    }

    /** An oldest-first split of $amount across a party's open documents: [doc id => amount]. Saves nothing. */
    public function fifoSplit(string $side, int $partyId, string $amount): array
    {
        $left = $amount;
        $split = [];
        foreach ($this->openDocs($side, $partyId) as $d) {
            if (! Money::positive($left)) {
                break;
            }
            $take = Money::cmp($left, $d->balance_due) < 0 ? $left : $d->balance_due;
            $split[$d->id] = $take;
            $left = Money::sub($left, $take);
        }

        return $split;
    }

    /** Remove one allocation: the amount goes back to unapplied credit. Returns the row. */
    public function remove(string $side, int $allocationId): object
    {
        $row = $this->db()->table('payment_allocations')->where('id', $allocationId)->where('side', $side)->first() ?? $this->fail('That application was already removed.');
        if ($row->source === 'writeoff') {
            $this->fail('That is a bad-debt write-off. To undo it, open the write-off and click Void.');
        }
        $this->db()->table('payment_allocations')->where('id', $allocationId)->delete();

        return $row;
    }

    /** A document was voided, reverted to draft or deleted. */
    public function releaseDoc(string $side, int $docId): void
    {
        $this->db()->table('payment_allocations')->where('side', $side)->where('doc_id', $docId)->delete();
    }

    /** A source was voided or deleted. */
    public function releaseSource(string $side, int $id, string $type = 'pay'): void
    {
        $this->db()->table('payment_allocations')->where('side', $side)->where('pay_type', $type)->where('payment_id', $id)->delete();
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['sales' => $message]);
    }
}
