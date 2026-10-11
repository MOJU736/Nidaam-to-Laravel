<?php

namespace App\Services\Accounting;

use App\Models\Tenant\Group;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\Setting;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Chart of accounts: groups and accounts (ledgers) in one tree, with types and balances.
 * Ported from the legacy Coa library, Groups and Ledgers controllers and FunctionsCore code helpers.
 *
 * An account's type (Asset, Liability, Equity, Income, Expense) comes from the top group it sits
 * under, found by the group's name; inside Liabilities, a group named "Equity" makes its accounts Equity.
 */
class ChartOfAccounts
{
    const TYPES = ['Asset', 'Liability', 'Equity', 'Income', 'Expense'];

    public function __construct(protected LedgerBalances $balances) {}

    public static function normalSide(string $type): string
    {
        return in_array($type, ['Liability', 'Equity', 'Income'], true) ? 'C' : 'D';
    }

    public static function plural(string $type): string
    {
        return ['Asset' => 'Assets', 'Liability' => 'Liabilities', 'Equity' => 'Equity', 'Income' => 'Income', 'Expense' => 'Expenses'][$type] ?? $type;
    }

    protected function rootType(?string $name, int $id): string
    {
        $n = strtolower((string) $name);
        foreach ([['asset', 'Asset'], ['incom', 'Income'], ['revenue', 'Income'], ['expense', 'Expense'], ['liabilit', 'Liability'], ['equity', 'Equity'], ['capital', 'Equity']] as [$needle, $type]) {
            if (str_contains($n, $needle)) {
                return $type;
            }
        }

        return [1 => 'Asset', 2 => 'Liability', 3 => 'Income', 4 => 'Expense'][$id] ?? 'Asset';
    }

    /** id of a top group: 'asset' | 'income' | 'expense' (by code, then name, then the legacy seed id). */
    public function topLevelGroupId(string $kind): ?int
    {
        [$codes, $like, $legacy] = match ($kind) {
            'asset' => [['01', 'ASSETS'], 'Asset', 1],
            'income' => [['03', 'INCOME'], 'Incom', 3],
            'expense' => [['04', 'EXPENSES'], 'Expense', 4],
            default => [[], '', 0],
        };
        $roots = Group::whereNull('parent_id');
        $g = (clone $roots)->whereIn('code', $codes)->first() ?? (clone $roots)->where('name', 'like', $like.'%')->first() ?? (clone $roots)->where('id', $legacy)->first();

        return $g?->id;
    }

    /** First day of the financial year that holds $date, from the company's fy_start. */
    public function fyStartFor(string $date): ?string
    {
        $fs = substr((string) Setting::first()?->fy_start, 0, 10);
        if (! $fs || ! $date) {
            return null;
        }
        $md = substr($fs, 5, 5);
        if ($md === '02-29') {
            $md = '03-01';
        }
        $y = (int) substr($date, 0, 4);
        $c = sprintf('%04d-%s', $y, $md);

        return $c > $date ? sprintf('%04d-%s', $y - 1, $md) : $c;
    }

    /** Customers' and vendors' own accounts: ledger id => 'customer'|'vendor' (an account shared by several stays normal). */
    protected function partyAccounts(): array
    {
        $out = [];
        foreach (['customers' => 'customer', 'vendors' => 'vendor'] as $table => $kind) {
            foreach (DB::connection('tenant')->table($table)->whereNotNull('ledger_id')->groupBy('ledger_id')->havingRaw('COUNT(*) = 1')->pluck('ledger_id') as $id) {
                $out[(int) $id] = $kind;
            }
        }

        return $out;
    }

    /**
     * Every group and account in chart order, with depth, type, and a balance signed so that a normal balance is positive.
     * Income and expense show this financial year only; earlier years' profit sits in Retained Earnings.
     *
     * @return array{rows: array, fy_from: ?string, prior_profit: string}
     */
    public function rows(): array
    {
        $groups = Group::orderBy('code')->orderBy('id')->get();
        $ledgers = Ledger::orderBy('code')->orderBy('id')->get();
        $kids = $groups->groupBy(fn ($g) => (int) $g->parent_id);
        $accts = $ledgers->groupBy('group_id');
        $parties = $this->partyAccounts();
        $lines = DB::connection('tenant')->table('entryitems')->groupBy('ledger_id')->selectRaw('ledger_id, COUNT(*) n')->pluck('n', 'ledger_id');

        // This financial year's balances for the income and expense trees.
        $fyFrom = $this->fyStartFor(now()->toDateString());
        $ytdRoots = [];
        if ($fyFrom) {
            foreach (['income', 'expense'] as $k) {
                if ($id = $this->topLevelGroupId($k)) {
                    $ytdRoots[$id] = true;
                }
            }
        }

        // net balance (debit positive) per ledger: full history, or this year only under the income/expense roots
        $ytdLedgers = [];
        $collect = function (int $gid) use (&$collect, $kids, $accts, &$ytdLedgers) {
            foreach ($accts->get($gid, []) as $l) {
                $ytdLedgers[] = $l->id;
            }
            foreach ($kids->get($gid, []) as $g) {
                $collect($g->id);
            }
        };
        foreach (array_keys($ytdRoots) as $r) {
            $collect($r);
        }
        $full = $this->balances->closingBalances($ledgers->pluck('id')->all());
        $ytd = $ytdLedgers ? $this->balances->closingBalances($ytdLedgers, null, $fyFrom) : [];
        $net = fn (array $b) => $b['dc'] === 'D' ? $b['amount'] : Money::neg($b['amount']);

        $rows = [];
        $walk = function ($node, int $depth, ?string $type, array $path, bool $inYtd) use (&$walk, &$rows, $kids, $accts, $parties, $lines, $full, $ytd, $net, $ytdRoots) {
            $isRoot = $path === [];
            $type = $isRoot ? $this->rootType($node->name, $node->id)
                : (($type === 'Liability' && stripos($node->name, 'equity') !== false) ? 'Equity' : $type);
            $inYtd = $inYtd || isset($ytdRoots[$node->id]);
            $normal = self::normalSide($type);
            $sign = $normal === 'D' ? 1 : -1;
            $childPath = [...$path, (int) $node->id];

            $gi = count($rows);
            $rows[] = [
                'kind' => 'group', 'id' => (int) $node->id, 'name' => $node->name, 'code' => $node->code, 'depth' => $depth, 'type' => $type,
                'parent' => $isRoot ? 0 : end($path), 'path' => $path, 'balance' => '0.00', 'root' => $isRoot,
                'child_groups' => $kids->get($node->id, collect())->count(), 'child_accounts' => $accts->get($node->id, collect())->count(),
                'customers' => 0, 'vendors' => 0,
            ];

            $total = '0.00';
            foreach ($accts->get($node->id, []) as $l) {
                $b = $inYtd ? $ytd[$l->id] : $full[$l->id];
                $signed = $sign === 1 ? $net($b) : Money::neg($net($b));
                $total = Money::add($total, $signed);
                if (isset($parties[$l->id])) {
                    $rows[$gi][$parties[$l->id].'s']++;

                    continue;
                }
                $opening = $inYtd ? '0.00' : ($l->op_balance_dc === $normal ? Money::add($l->op_balance, 0) : Money::neg(Money::add($l->op_balance, 0)));
                $rows[] = [
                    'kind' => 'account', 'id' => (int) $l->id, 'name' => $l->name, 'code' => $l->code, 'depth' => $depth + 1, 'type' => $type,
                    'parent' => (int) $node->id, 'path' => $childPath, 'balance' => $signed, 'opening' => $opening,
                    'bank' => (int) $l->type === 1, 'contra' => (bool) $l->is_contra, 'reconcile' => (int) $l->reconciliation === 1,
                    'notes' => (string) $l->notes, 'lines' => (int) ($lines[$l->id] ?? 0),
                ];
            }
            foreach ($kids->get($node->id, []) as $g) {
                $before = count($rows);
                $walk($g, $depth + 1, $type, $childPath, $inYtd);
                $total = Money::add($total, $rows[$before]['balance']);
            }
            $rows[$gi]['balance'] = $total;
        };

        foreach ($kids->get(0, []) as $root) {
            $walk($root, 0, null, [], false);
        }

        $prior = '0.00';
        if ($fyFrom) {
            $prior = $this->profitBefore($fyFrom);
        }

        return ['rows' => $rows, 'fy_from' => $fyFrom, 'prior_profit' => $prior];
    }

    /** Profit (income - expenses, positive = profit) of everything before $date, opening balances included. */
    public function profitBefore(string $date): string
    {
        $end = date('Y-m-d', strtotime($date.' -1 day'));
        $sum = function (?int $root) use ($end) {
            if (! $root) {
                return '0.00';
            }
            $ids = $this->descendantLedgerIds($root);
            $total = '0.00';
            foreach ($this->balances->closingBalances($ids, $end) as $b) {
                $total = $b['dc'] === 'D' ? Money::add($total, $b['amount']) : Money::sub($total, $b['amount']);
            }

            return $total; // debit positive
        };

        // income is credit-normal: profit = income(credit) - expense(debit) = -(income net) - expense net
        return Money::sub(Money::neg($sum($this->topLevelGroupId('income'))), $sum($this->topLevelGroupId('expense')));
    }

    /** Per-type totals. A group whose type differs from its parent's (a top group, or Equity inside Liabilities) brings its whole balance and is taken back out of the parent's type. */
    public function totals(array $rows): array
    {
        $t = [];
        foreach (self::TYPES as $type) {
            $t[$type] = ['total' => '0.00', 'count' => 0];
        }
        $groups = [];
        foreach ($rows as $r) {
            if ($r['kind'] === 'group') {
                $groups[$r['id']] = $r;
                $t[$r['type']]['count'] += $r['customers'] + $r['vendors'];
            } else {
                $t[$r['type']]['count']++;
            }
        }
        foreach ($groups as $g) {
            $parent = $g['parent'] && isset($groups[$g['parent']]) ? $groups[$g['parent']] : null;
            if ($parent && $parent['type'] === $g['type']) {
                continue;
            }
            $t[$g['type']]['total'] = Money::add($t[$g['type']]['total'], $g['balance']);
            if ($parent) {
                $same = self::normalSide($parent['type']) === self::normalSide($g['type']);
                $t[$parent['type']]['total'] = $same ? Money::sub($t[$parent['type']]['total'], $g['balance']) : Money::add($t[$parent['type']]['total'], $g['balance']);
            }
        }

        return $t;
    }

    /** By how much do the opening balances fail to balance? ['dc' => side needed to balance, 'amount' => string]. */
    public function openingDifference(): array
    {
        $net = '0.00';
        foreach (Ledger::get(['op_balance', 'op_balance_dc']) as $l) {
            $net = $l->op_balance_dc === 'D' ? Money::add($net, $l->op_balance) : Money::sub($net, $l->op_balance);
        }

        return Money::cmp($net, 0) >= 0 ? ['dc' => 'C', 'amount' => $net] : ['dc' => 'D', 'amount' => ltrim($net, '-')];
    }

    /**
     * Groups for a picker: id => [label, depth, type, root, code, gross] in chart order.
     * $skipId leaves out a group and everything under it (a group can't move inside itself).
     */
    public function groupOptions(?int $skipId = null): array
    {
        $kids = Group::orderBy('code')->orderBy('name')->get()->groupBy(fn ($g) => (int) $g->parent_id);
        $out = [];
        $walk = function (int $parent, int $depth, ?string $type) use (&$walk, &$out, $kids, $skipId) {
            foreach ($kids->get($parent, []) as $g) {
                if ($skipId !== null && (int) $g->id === $skipId) {
                    continue;
                }
                $t = $depth === 0 ? $this->rootType($g->name, $g->id) : (($type === 'Liability' && stripos($g->name, 'equity') !== false) ? 'Equity' : $type);
                $out[(int) $g->id] = ['label' => $g->name, 'depth' => $depth, 'type' => $t, 'root' => $depth === 0, 'code' => $g->code, 'gross' => (int) $g->affects_gross];
                $walk((int) $g->id, $depth + 1, $t);
            }
        };
        $walk(0, 0, null);

        return $out;
    }

    /** Type of the group an account sits in (for its normal side). */
    public function typeOfGroup(int $groupId): ?string
    {
        return $this->groupOptions()[$groupId]['type'] ?? null;
    }

    /** Ledger ids anywhere under a group. */
    public function descendantLedgerIds(int $groupId): array
    {
        $kids = Group::get(['id', 'parent_id'])->groupBy(fn ($g) => (int) $g->parent_id);
        $groupIds = [$groupId];
        $stack = [$groupId];
        while ($stack) {
            foreach ($kids->get(array_pop($stack), []) as $g) {
                $groupIds[] = (int) $g->id;
                $stack[] = (int) $g->id;
            }
        }

        return Ledger::whereIn('group_id', $groupIds)->pluck('id')->all();
    }

    /** "<group code>-0007": the next free 4-digit number among that group's account codes. */
    public function nextLedgerCode(int $groupId): ?string
    {
        $code = Group::where('id', $groupId)->value('code');
        if ($code === null || $code === '') {
            return null;
        }
        $prefix = $code.'-';
        $n = 0;
        foreach (Ledger::where('group_id', $groupId)->pluck('code') as $c) {
            if ($c !== null && str_starts_with($c, $prefix) && ctype_digit($tail = substr($c, strlen($prefix)))) {
                $n = max($n, (int) $tail);
            }
        }
        do {
            $next = $prefix.sprintf('%04d', ++$n);
        } while (Ledger::where('code', $next)->exists() && $n < 99999);

        return $next;
    }

    /** "<parent code>-03": the next free 2-digit number among the parent's sub-group codes. */
    public function nextGroupCode(int $parentId, ?int $excludeId = null): ?string
    {
        $code = Group::where('id', $parentId)->value('code');
        if ($code === null || $code === '') {
            return null;
        }
        $prefix = $code.'-';
        $n = 0;
        foreach (Group::where('parent_id', $parentId)->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))->pluck('code') as $c) {
            if ($c !== null && str_starts_with($c, $prefix) && ctype_digit($tail = substr($c, strlen($prefix)))) {
                $n = max($n, (int) $tail);
            }
        }
        do {
            $next = $prefix.sprintf('%02d', ++$n);
        } while (Group::where('code', $next)->exists() && $n < 9999);

        return $next;
    }

    /** A group that moved gets a new code; its sub-groups and accounts follow so the prefix still says where they belong. */
    public function recodeTree(?string $old, ?string $new): void
    {
        if (! $old || ! $new || $old === $new) {
            return;
        }
        $like = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $old.'-').'%';
        foreach (['groups', 'ledgers'] as $table) {
            DB::connection('tenant')->update("UPDATE `$table` SET `code` = CONCAT(?, SUBSTRING(`code`, ?)) WHERE `code` LIKE ? ESCAPE '!'", [$new, strlen($old) + 1, $like]);
        }
    }

    // ---- accounts -------------------------------------------------------

    public function booksClosed(): bool
    {
        $d = Setting::first()?->lock_date;

        return ! empty($d) && (string) $d !== '0000-00-00';
    }

    public function companyLocked(): bool
    {
        return (bool) Setting::first()?->account_locked;
    }

    public function saveAccount(?Ledger $ledger, array $d): Ledger
    {
        $closed = $this->booksClosed();

        return DB::connection('tenant')->transaction(function () use ($ledger, $d, $closed) {
            $attrs = [
                'name' => trim($d['name']), 'group_id' => (int) $d['group_id'],
                'op_balance' => ($d['op_balance'] ?? '') === '' ? 0 : $d['op_balance'], 'op_balance_dc' => $d['op_balance_dc'] === 'C' ? 'C' : 'D',
                'notes' => (string) ($d['notes'] ?? ''), 'type' => ! empty($d['type']) ? 1 : 0,
                'reconciliation' => ! empty($d['reconciliation']) ? 1 : 0, 'is_contra' => ! empty($d['is_contra']) ? 1 : 0,
            ];

            if (! $ledger) {
                return Ledger::create($attrs + ['code' => $this->nextLedgerCode($attrs['group_id'])]);
            }

            // once any period is closed the opening balance can't change - it counts from the very start
            if ($closed) {
                $attrs['op_balance'] = $ledger->op_balance;
                $attrs['op_balance_dc'] = $ledger->op_balance_dc;
            }
            // keep the code, or give a new free one when the group changed
            if ((int) $attrs['group_id'] !== (int) $ledger->group_id || $ledger->code === null || $ledger->code === '') {
                $attrs['code'] = $this->nextLedgerCode($attrs['group_id']);
            }
            $ledger->update($attrs);

            return $ledger;
        });
    }

    public function deleteAccount(Ledger $ledger): void
    {
        $db = DB::connection('tenant');
        if ($db->table('entryitems')->where('ledger_id', $ledger->id)->exists()) {
            $this->fail('ledger', 'This account has transactions and cannot be deleted.');
        }
        if ($db->table('customers')->where('ledger_id', $ledger->id)->exists() || $db->table('vendors')->where('ledger_id', $ledger->id)->exists()) {
            $this->fail('ledger', 'This is a customer\'s or vendor\'s own account. Delete the customer or vendor instead.');
        }
        $ledger->delete();
    }

    // ---- groups ---------------------------------------------------------

    public function saveGroup(?Group $group, array $d): Group
    {
        $parentId = (int) $d['parent_id'];

        return DB::connection('tenant')->transaction(function () use ($group, $d, $parentId) {
            if (! $group) {
                $gross = (int) (Group::find($parentId)?->affects_gross ?? 0);

                return Group::create(['parent_id' => $parentId, 'name' => trim($d['name']), 'affects_gross' => $gross, 'code' => $this->nextGroupCode($parentId)]);
            }

            $attrs = ['parent_id' => $parentId, 'name' => trim($d['name'])];
            $newCode = null;
            if ($parentId !== (int) $group->parent_id || $group->code === null || $group->code === '') {
                $newCode = $this->nextGroupCode($parentId, $group->id);
                $attrs['code'] = $newCode;
            }
            $old = $group->code;
            $group->update($attrs);
            if ($newCode !== null && $old) {
                $this->recodeTree($old, $newCode);
            }

            return $group->refresh();
        });
    }

    public function deleteGroup(Group $group): void
    {
        if (empty($group->parent_id)) {
            $this->fail('group', 'The top groups (Assets, Liabilities and the others) cannot be deleted.');
        }
        if (Group::where('parent_id', $group->id)->exists()) {
            $this->fail('group', 'This group has groups inside it. Move or delete them first.');
        }
        if (Ledger::where('group_id', $group->id)->exists()) {
            $this->fail('group', 'This group has accounts in it. Move or delete them first.');
        }
        $group->delete();
    }

    protected function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
