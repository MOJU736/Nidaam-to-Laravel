<?php

namespace App\Services\Accounting;

use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Ledger opening/closing balances, ported from the legacy Ledger_model.
 * A balance is ['dc' => 'D'|'C', 'amount' => string, 'dr_total' => string, 'cr_total' => string].
 */
class LedgerBalances
{
    /** Closing balances for many ledgers in two queries. Unknown ids are left out. */
    public function closingBalances(array $ids, ?string $endDate = null, ?string $startDate = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];
        $db = DB::connection('tenant');

        foreach (array_chunk($ids, 500) as $chunk) {
            $sums = $db->table('entryitems')
                ->leftJoin('entries', 'entries.id', '=', 'entryitems.entry_id')
                ->whereIn('entryitems.ledger_id', $chunk)
                ->when($startDate, fn ($q) => $q->where('entries.date', '>=', $startDate))
                ->when($endDate, fn ($q) => $q->where('entries.date', '<=', $endDate))
                ->groupBy('entryitems.ledger_id')
                ->selectRaw("entryitems.ledger_id AS lid, SUM(CASE WHEN entryitems.dc = 'D' THEN entryitems.amount ELSE 0 END) AS dr, SUM(CASE WHEN entryitems.dc = 'C' THEN entryitems.amount ELSE 0 END) AS cr")
                ->get()->keyBy('lid');

            foreach ($db->table('ledgers')->whereIn('id', $chunk)->get(['id', 'op_balance', 'op_balance_dc']) as $op) {
                $s = $sums->get($op->id);
                // with a start date the opening balance does not apply (it belongs to the very start)
                $out[(int) $op->id] = $this->settle($startDate ? 0 : $op->op_balance, $op->op_balance_dc, $s->dr ?? 0, $s->cr ?? 0);
            }
        }

        return $out;
    }

    public function closingBalance(int $ledgerId, ?string $endDate = null, ?string $startDate = null): array
    {
        return $this->closingBalances([$ledgerId], $endDate, $startDate)[$ledgerId]
            ?? ['dc' => 'D', 'amount' => '0.00', 'dr_total' => '0.00', 'cr_total' => '0.00'];
    }

    /** Balance carried in to $startDate: the opening balance plus everything dated before it. */
    public function openingBalance(int $ledgerId, ?string $startDate = null): array
    {
        $op = DB::connection('tenant')->table('ledgers')->where('id', $ledgerId)->first(['op_balance', 'op_balance_dc']);
        if (! $op) {
            return ['dc' => 'D', 'amount' => '0.00'];
        }
        if ($startDate === null) {
            return ['dc' => $op->op_balance_dc, 'amount' => Money::add($op->op_balance ?: 0, 0)];
        }

        $sums = DB::connection('tenant')->table('entryitems')
            ->leftJoin('entries', 'entries.id', '=', 'entryitems.entry_id')
            ->where('entryitems.ledger_id', $ledgerId)
            ->where('entries.date', '<', $startDate)
            ->selectRaw("SUM(CASE WHEN entryitems.dc = 'D' THEN entryitems.amount ELSE 0 END) AS dr, SUM(CASE WHEN entryitems.dc = 'C' THEN entryitems.amount ELSE 0 END) AS cr")
            ->first();

        $b = $this->settle($op->op_balance, $op->op_balance_dc, $sums->dr ?? 0, $sums->cr ?? 0);

        return ['dc' => $b['dc'], 'amount' => $b['amount']];
    }

    /** Opening balance + movements -> the net balance and which side it sits on. */
    protected function settle($opBalance, string $opDc, $dr, $cr): array
    {
        $op = $opBalance ?: 0;
        [$drT, $crT] = $opDc === 'D' ? [Money::add($op, $dr), Money::add($cr, 0)] : [Money::add($dr, 0), Money::add($op, $cr)];

        $cmp = Money::cmp($drT, $crT);
        if ($cmp > 0) {
            [$amount, $dc] = [Money::sub($drT, $crT), 'D'];
        } elseif ($cmp === 0) {
            [$amount, $dc] = ['0.00', $opDc];
        } else {
            [$amount, $dc] = [Money::sub($crT, $drT), 'C'];
        }

        return ['dc' => $dc, 'amount' => $amount, 'dr_total' => Money::add($dr, 0), 'cr_total' => Money::add($cr, 0)];
    }
}
