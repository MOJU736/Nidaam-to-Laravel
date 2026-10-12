<?php

namespace App\Services\Reports;

use App\Models\Tenant\Ledger;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\LedgerBalances;
use App\Support\Money;
use App\Support\Tables;
use Illuminate\Support\Facades\DB;

/** What the Dashboard shows: cash in the bank, who owes you, what to pay soon, a six-month income and expense chart, recent activity. */
class DashboardService
{
    public function __construct(protected LedgerBalances $balances, protected ChartOfAccounts $chart, protected ActivityReports $activity) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    public function data(): array
    {
        $today = now()->toDateString();

        // cash and bank accounts (a bank / cash ledger is type 1)
        $banks = Ledger::where('type', 1)->orderBy('name')->get(['id', 'name']);
        $closing = $this->balances->closingBalances($banks->pluck('id')->all(), $today);
        $accounts = [];
        $cash = '0.00';
        foreach ($banks as $b) {
            $c = $closing[(int) $b->id] ?? ['dc' => 'D', 'amount' => '0.00'];
            $v = $c['dc'] === 'D' ? $c['amount'] : Money::neg($c['amount']);
            $cash = Money::add($cash, $v);
            $accounts[] = ['id' => (int) $b->id, 'name' => $b->name, 'amount' => $v];
        }
        usort($accounts, fn ($a, $b) => Money::cmp($b['amount'], $a['amount']));

        // who owes you / what you owe
        $ar = $this->activity->openDocs('ar');
        $ap = $this->activity->openDocs('ap');
        $soon = '0.00';
        $soonN = 0;
        $week = now()->addDays(7)->toDateString();
        foreach ($ap['groups'] as $g) {
            foreach ($g['docs'] as $d) {
                $due = $d->due_date ? substr((string) $d->due_date, 0, 10) : null;
                if ($due && $due <= $week) {
                    $soon = Money::add($soon, $d->balance_due);
                    $soonN++;
                }
            }
        }
        $owing = collect($ar['groups'])->sortByDesc(fn ($g) => (float) $g['due'])->take(5)->map(fn ($g) => ['id' => $g['id'], 'name' => $g['name'], 'amount' => $g['due'], 'count' => count($g['docs'])])->values()->all();

        // old drafts waiting to be issued
        $cut = now()->subDays(14)->toDateString();
        $drafts = (int) $this->db()->table('invoices')->where('status', 'draft')->where('date', '<=', $cut)->count() + (int) $this->db()->table('bills')->where('status', 'draft')->where('date', '<=', $cut)->count();

        // income and expense, six months to the end of this one
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $d = now()->startOfMonth()->subMonths($i);
            $months[$d->format('Y-m')] = ['label' => $d->format('M'), 'income' => '0.00', 'expense' => '0.00'];
        }
        $from = array_key_first($months).'-01';
        foreach (['income' => 'income', 'expense' => 'expense'] as $kind => $key) {
            $root = $this->chart->topLevelGroupId($kind);
            $ids = $root ? $this->chart->descendantLedgerIds((int) $root) : [];
            if (! $ids) {
                continue;
            }
            $rows = $this->db()->table('entryitems')->join('entries', 'entries.id', '=', 'entryitems.entry_id')->whereIn('entryitems.ledger_id', $ids)->whereBetween('entries.date', [$from, $today > now()->endOfMonth()->toDateString() ? $today : now()->endOfMonth()->toDateString()])
                ->groupByRaw("DATE_FORMAT(entries.date, '%Y-%m')")->selectRaw("DATE_FORMAT(entries.date, '%Y-%m') ym, SUM(CASE WHEN entryitems.dc = 'D' THEN entryitems.amount ELSE -entryitems.amount END) net")->get();
            foreach ($rows as $r) {
                if (isset($months[$r->ym])) {
                    $months[$r->ym][$key] = $kind === 'income' ? Money::neg((string) $r->net) : (string) $r->net; // income is a credit balance
                }
            }
        }
        $months = array_values($months);
        $thisNet = Money::sub($months[5]['income'], $months[5]['expense']);
        $lastNet = Money::sub($months[4]['income'], $months[4]['expense']);

        // the latest things done in this company
        $recent = Tables::has('logs') ? $this->db()->table('logs')->orderByDesc('id')->limit(8)->get(['date', 'message', 'user_id'])->map(fn ($l) => ['when' => (string) $l->date, 'what' => (string) $l->message, 'user' => (int) $l->user_id])->all() : [];
        $names = \App\Models\User::whereIn('id', array_filter(array_column($recent, 'user')))->get()->mapWithKeys(fn ($u) => [$u->id => $u->displayName()])->all();
        foreach ($recent as &$r) {
            $r['who'] = $names[$r['user']] ?? '';
        }

        return ['today' => $today, 'cash' => $cash, 'accounts' => $accounts, 'ar' => $ar['grand'], 'ap' => $ap['grand'], 'soon' => $soon, 'soon_n' => $soonN, 'owing' => $owing, 'drafts' => $drafts,
            'months' => $months, 'this_net' => $thisNet, 'last_net' => $lastNet, 'recent' => $recent];
    }
}
