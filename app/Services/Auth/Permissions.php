<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What a signed-in person may open, from their group. Ported from the legacy MY_Controller::verify_auth().
 *
 *  - admin: everything.
 *  - restricted_viewer ("Reports only"): the Dashboard, the Reports, and a fixed list of look-but-don't-touch pages.
 *  - customer_viewer: customers, their balances and their sales documents - nothing else, nothing can be changed.
 *  - every other group: the group's row in the `permissions` table. A module (Customers, Sales, Vendors, Purchases, Items) has an
 *    "access" tick and finer create / edit / void / delete ticks; the older areas (entries, accounts, reports ...) have one tick per page.
 *    A page the table has no tick for is open (as it always was), but a group with no row at all can open nothing.
 */
class Permissions
{
    public const RESTRICTED = 'restricted_viewer';

    public const CUSTOMER_VIEWER = 'customer_viewer';

    /** Which module each route belongs to (the prefix of its name). */
    protected const MODULES = [
        'customers' => 'customers', 'vendors' => 'vendors', 'items' => 'items', 'stock' => 'items',
        'invoices' => 'sales', 'quotations' => 'sales', 'payments' => 'sales', 'credit-notes' => 'sales', 'recurring' => 'sales', 'reminders' => 'sales', 'sales' => 'sales',
        'bills' => 'purchases', 'purchase-orders' => 'purchases', 'vendor-payments' => 'purchases', 'expenses' => 'purchases', 'supplier-credits' => 'purchases', 'purchases' => 'purchases',
    ];

    /** The last part of a route name decides which finer right it needs (anything not listed only needs "access"). */
    protected const RIGHTS = [
        'create' => 'create', 'store' => 'create', 'convert' => 'create', 'run' => 'create', 'send' => 'create', 'one' => 'create', 'quick' => 'create',
        'edit' => 'edit', 'update' => 'edit', 'issue' => 'edit', 'revert' => 'edit', 'apply' => 'edit', 'apply-credit' => 'edit', 'toggle' => 'edit', 'refund' => 'edit', 'write-off' => 'edit', 'message' => 'edit', 'remove' => 'edit',
        'void' => 'void', 'destroy' => 'delete', 'delete' => 'delete',
    ];

    /** Look-but-don't-touch pages for the restricted viewer: route names (exact). */
    protected const RESTRICTED_VIEW = [
        'invoices.index', 'invoices.show', 'quotations.index', 'quotations.show', 'payments.index', 'payments.show', 'credit-notes.index', 'credit-notes.show', 'customers.index', 'customers.show',
        'bills.index', 'bills.show', 'purchase-orders.index', 'purchase-orders.show', 'vendor-payments.index', 'vendor-payments.show', 'supplier-credits.index', 'supplier-credits.show',
        'expenses.index', 'expenses.show', 'vendors.index', 'vendors.show', 'documents.pdf',
    ];

    protected const CUSTOMER_VIEW = ['customers.index', 'customers.show', 'invoices.show', 'payments.show', 'credit-notes.show'];

    protected const CUSTOMER_DOCS = ['invoice', 'receipt', 'credit-note', 'customer-statement'];

    /** Which account_settings-* tick each Settings page needs (the second part of the route name). Tax rates and payment methods belong to the company details. */
    protected const SETTINGS_TICKS = [
        'company' => 'main', 'tax-rates' => 'main', 'payment-methods' => 'main', 'email' => 'email', 'templates' => 'printer',
        'entry-types' => 'entrytypes', 'tags' => 'tags', 'close-books' => 'lock', 'lock' => 'lock', 'year-end' => 'lock',
    ];

    protected const ALWAYS = ['companies.index', 'companies.activate', 'logout'];

    protected array $groupCache = [];

    protected array $rowCache = [];

    protected function db()
    {
        return DB::connection('master');
    }

    /** The user's group name, lower case ('' if none). */
    public function groupName(User $user): string
    {
        return $this->groupCache[$user->id] ??= strtolower((string) $this->db()->table('users_groups as ug')->join('groups as g', 'g.id', '=', 'ug.group_id')->where('ug.user_id', $user->id)->value('g.name'));
    }

    public function groupId(User $user): ?int
    {
        $id = $this->db()->table('users_groups')->where('user_id', $user->id)->value('group_id');

        return $id ? (int) $id : null;
    }

    /** The group's permission ticks as ['sales-access' => 1, ...], or null when the group has no row. */
    public function row(?int $groupId): ?array
    {
        if (! $groupId) {
            return null;
        }
        if (! array_key_exists($groupId, $this->rowCache)) {
            $r = $this->db()->table('permissions')->where('group_id', $groupId)->first();
            $this->rowCache[$groupId] = $r ? (array) $r : null;
        }

        return $this->rowCache[$groupId];
    }

    /** The module a route name belongs to, or null. */
    protected function module(string $name): ?string
    {
        return self::MODULES[explode('.', $name)[0]] ?? null;
    }

    /** Which module a document PDF / email belongs to (by the document's type). */
    protected function documentModule(?string $type): string
    {
        return in_array($type, ['bill', 'purchase-order', 'remittance', 'supplier-credit', 'vendor-statement'], true) ? 'purchases' : 'sales';
    }

    /** The single tick an older-area route needs (e.g. 'entries-add'), or null when it is not one of them. */
    protected function pageKey(string $name): ?string
    {
        $parts = explode('.', $name);
        $area = $parts[0];
        $last = end($parts);
        $action = match (true) {
            in_array($last, ['create', 'store', 'simple', 'transfer'], true) => 'add', in_array($last, ['edit', 'update'], true) => 'edit', $last === 'destroy' => 'delete', default => 'index',
        };

        return match ($area) {
            'dashboard' => 'dashboard-index',
            'entries' => $last === 'show' ? 'entries-view' : "entries-$action",
            'chart' => 'accounts-index',
            'ledgers' => "ledgers-$action",
            'groups' => "groups-$action",
            'banking' => 'reports-reconciliation',
            'reports' => match ($last) { 'balance-sheet' => 'reports-balancesheet', 'profit-loss', 'pl-by-month' => 'reports-profitloss', 'trial-balance' => 'reports-trialbalance', 'statement' => 'reports-ledgerstatement', 'journal' => 'reports-ledgerentries', default => 'reports-index' },
            default => null,
        };
    }

    /** Whether this user may open the route (by name). $type = a document's type for documents.*. */
    public function allows(User $user, ?string $routeName, ?string $type = null): bool
    {
        if ($routeName === null || in_array($routeName, self::ALWAYS, true) || str_starts_with($routeName, 'companies.')) {
            return true;
        }
        $group = $this->groupName($user);
        if ($group === 'admin') {
            return true;
        }
        $area = explode('.', $routeName)[0];

        // administration and company settings: administrators only (or whoever the group's own tick allows, for settings pages)
        if ($area === 'admin' || str_starts_with($routeName, 'settings.backup') || $routeName === 'sales.settings' || $routeName === 'purchases.settings' || $routeName === 'items.settings') {
            return false;
        }

        if ($group === self::CUSTOMER_VIEWER) {
            return in_array($routeName, self::CUSTOMER_VIEW, true) || ($routeName === 'documents.pdf' && in_array($type, self::CUSTOMER_DOCS, true));
        }
        if ($group === self::RESTRICTED) {
            return ($area === 'reports' || $area === 'dashboard') || in_array($routeName, self::RESTRICTED_VIEW, true);
        }

        $row = $this->row($this->groupId($user));
        if ($row === null) {
            return false; // no permissions row: nothing, rather than everything
        }
        $has = fn (string $key) => array_key_exists($key, $row);
        $on = fn (string $key) => (int) ($row[$key] ?? 0) === 1;

        if ($area === 'settings') {
            // "Open Settings" is needed for every settings page; each page then has its own tick (a page the table has no tick for needs only the first)
            if (! $on('account_settings-index')) {
                return false;
            }
            $tick = self::SETTINGS_TICKS[explode('.', $routeName)[1] ?? ''] ?? null;

            return $tick === null || ! $has("account_settings-$tick") || $on("account_settings-$tick");
        }
        if ($area === 'payroll') {
            return $on('payroll-access'); // confidential: only a group an administrator ticked "Payroll" for
        }
        if ($area === 'documents') {
            return $this->modulePasses($row, $this->documentModule($type), 'view');
        }
        if ($module = $this->module($routeName)) {
            $parts = explode('.', $routeName);
            $right = self::RIGHTS[end($parts)] ?? null;

            return $this->modulePasses($row, $module, $right ?? 'view');
        }
        if ($key = $this->pageKey($routeName)) {
            return ! $has($key) || $on($key); // a page the table has no tick for stays open, as before
        }

        return true;
    }

    /** A module's "access" tick, and (for create/edit/void/delete) its finer tick. A module with no ticks in the table stays open. */
    protected function modulePasses(array $row, string $module, string $right): bool
    {
        if (! array_key_exists("$module-access", $row)) {
            return true;
        }
        if ((int) $row["$module-access"] !== 1) {
            return false;
        }
        if ($right === 'view') {
            return true;
        }

        return ! array_key_exists("$module-$right", $row) || (int) $row["$module-$right"] === 1;
    }

    /** Why a page was refused, in plain words. */
    public function denial(User $user, ?string $routeName): string
    {
        $group = $this->groupName($user);
        if ($group === self::CUSTOMER_VIEWER) {
            return 'Your account can only view customers and their sales.';
        }
        if ($group === self::RESTRICTED) {
            return 'Your account can only view the Dashboard and Reports.';
        }
        if ($routeName && explode('.', $routeName)[0] === 'payroll') {
            return "Payroll is confidential - your role can't open it. An administrator can give a role Payroll under Groups & permissions.";
        }
        $module = $routeName ? $this->module($routeName) : null;
        $names = ['sales' => 'Sales', 'purchases' => 'Purchases', 'customers' => 'Customers', 'vendors' => 'Vendors', 'items' => 'Inventory'];
        $parts = explode('.', (string) $routeName);
        $right = self::RIGHTS[end($parts)] ?? null;
        if ($module && $right) {
            $words = ['create' => 'create new records', 'edit' => 'change records', 'void' => 'void records', 'delete' => 'delete records'];

            return "Your role can't {$words[$right]} in {$names[$module]}. Ask an administrator if you need to.";
        }

        return 'Your role does not have access to that page. Ask an administrator if you need it.';
    }
}
