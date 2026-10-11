<?php

namespace App\Http\Controllers;

use App\Services\Accounting\ActivityLog;
use App\Services\Auth\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Admin → Groups: the roles people are put in, and what each role may do. Administrators only (checked by the page middleware). */
class AdminGroupsController extends Controller
{
    /** Groups whose rights are fixed by the system rather than by ticks. */
    protected const FIXED = ['admin' => 'Administrators can do everything.', 'restricted_viewer' => 'Can only look at the Dashboard and Reports (and view sales and purchases). Nothing can be changed. This is fixed.', 'customer_viewer' => 'Can only look at customers and their sales. Nothing can be changed. This is fixed.'];

    /** The ticks, grouped for the page: [section => [label, [column => label]]]. Columns the table lacks are left out. */
    protected function sections(array $columns): array
    {
        $all = [
            'Dashboard and search' => ['dashboard-index' => 'Dashboard', 'search-index' => 'Search', 'admin-log' => 'Activity log'],
            'Journal entries' => ['entries-index' => 'See the list', 'entries-view' => 'Open an entry', 'entries-add' => 'Add', 'entries-edit' => 'Change', 'entries-delete' => 'Delete'],
            'Accounts' => ['accounts-index' => 'See the chart', 'groups-add' => 'Add groups', 'groups-edit' => 'Change groups', 'groups-delete' => 'Delete groups', 'ledgers-add' => 'Add accounts', 'ledgers-edit' => 'Change accounts', 'ledgers-delete' => 'Delete accounts'],
            'Reports and banking' => ['reports-index' => 'Reports home and lists', 'reports-balancesheet' => 'Balance Sheet', 'reports-profitloss' => 'Profit & Loss', 'reports-trialbalance' => 'Trial Balance', 'reports-ledgerstatement' => 'Account statement', 'reports-ledgerentries' => 'Journal', 'reports-reconciliation' => 'Banking / reconcile'],
            'Settings' => ['account_settings-index' => 'Open Settings (needed for every settings page)', 'account_settings-main' => 'Company details, tax rates, payment methods', 'account_settings-cf' => 'Custom fields (not used yet)', 'account_settings-email' => 'Email', 'account_settings-printer' => 'Templates & logo', 'account_settings-tags' => 'Tags', 'account_settings-entrytypes' => 'Entry types', 'account_settings-lock' => 'Lock / close books'],
        ];
        foreach (['customers' => 'Customers', 'sales' => 'Sales', 'vendors' => 'Vendors', 'purchases' => 'Purchases', 'items' => 'Inventory', 'commissions' => 'Commissions'] as $m => $label) {
            $all[$label] = ["$m-access" => 'Open it', "$m-create" => 'Create', "$m-edit" => 'Change', "$m-void" => 'Void', "$m-delete" => 'Delete'];
        }
        $all['Payroll'] = ['payroll-access' => 'Open it'];

        $out = [];
        foreach ($all as $title => $cols) {
            $cols = array_intersect_key($cols, array_flip($columns));
            if ($cols) {
                $out[$title] = $cols;
            }
        }

        return $out;
    }

    protected function master()
    {
        return DB::connection('master');
    }

    protected function permissionColumns(): array
    {
        return array_values(array_diff(Schema::connection('master')->getColumnListing('permissions'), ['id', 'group_id']));
    }

    public function index()
    {
        $groups = $this->master()->table('groups')->orderBy('id')->get();
        $counts = $this->master()->table('users_groups')->selectRaw('group_id, COUNT(*) n')->groupBy('group_id')->pluck('n', 'group_id');
        $ticks = $this->master()->table('permissions')->get()->keyBy('group_id')->map(fn ($r) => count(array_filter((array) $r, fn ($v, $k) => ! in_array($k, ['id', 'group_id'], true) && (int) $v === 1, ARRAY_FILTER_USE_BOTH)));

        return view('admin.groups', ['groups' => $groups, 'counts' => $counts, 'ticks' => $ticks, 'fixed' => array_keys(self::FIXED)]);
    }

    public function create()
    {
        return view('admin.group-form', ['g' => null, 'sections' => [], 'row' => [], 'fixed' => null]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:25', 'regex:/^[A-Za-z0-9_ \-]+$/'], 'description' => ['nullable', 'string', 'max:100']]);
        if ($this->master()->table('groups')->whereRaw('LOWER(name) = ?', [strtolower($d['name'])])->exists()) {
            throw ValidationException::withMessages(['name' => 'A group with that name already exists.']);
        }
        $id = $this->master()->transaction(function () use ($d) {
            $id = $this->master()->table('groups')->insertGetId(['name' => $d['name'], 'description' => $d['description'] ?? '']);
            $this->master()->table('permissions')->insert(['group_id' => $id]); // every tick starts off
            return $id;
        });
        ActivityLog::add("Created group {$d['name']}");

        return redirect()->route('admin.groups.edit', $id)->with('message', 'Group created. Now choose what it may do.');
    }

    public function edit(int $id)
    {
        $g = $this->master()->table('groups')->where('id', $id)->first() ?? abort(404);
        $fixed = self::FIXED[strtolower($g->name)] ?? null;
        $row = (array) ($this->master()->table('permissions')->where('group_id', $id)->first() ?? []);

        return view('admin.group-form', ['g' => $g, 'sections' => $fixed ? [] : $this->sections($this->permissionColumns()), 'row' => $row, 'fixed' => $fixed]);
    }

    public function update(Request $request, int $id)
    {
        $g = $this->master()->table('groups')->where('id', $id)->first() ?? abort(404);
        $fixed = isset(self::FIXED[strtolower($g->name)]);
        $d = $request->validate(['description' => ['nullable', 'string', 'max:100']]);
        $this->master()->table('groups')->where('id', $id)->update(['description' => $d['description'] ?? '']);
        if (! $fixed) {
            $allowed = array_flip($this->permissionColumns());
            $set = [];
            foreach (array_keys($allowed) as $col) {
                $set[$col] = in_array($col, (array) $request->input('perm'), true) ? 1 : 0;
            }
            $exists = $this->master()->table('permissions')->where('group_id', $id)->exists();
            $exists ? $this->master()->table('permissions')->where('group_id', $id)->update($set) : $this->master()->table('permissions')->insert($set + ['group_id' => $id]);
        }
        ActivityLog::add("Updated group {$g->name}");

        return redirect()->route('admin.groups.edit', $id)->with('message', 'Group saved.');
    }

    public function destroy(int $id)
    {
        $g = $this->master()->table('groups')->where('id', $id)->first() ?? abort(404);
        if (isset(self::FIXED[strtolower($g->name)]) || in_array(strtolower($g->name), ['manager', 'accountant', 'data_entry_operator'], true)) {
            return back()->with('error', 'The standard groups cannot be deleted.');
        }
        if ($this->master()->table('users_groups')->where('group_id', $id)->exists()) {
            return back()->with('error', 'This group still has people in it. Move them to another group first.');
        }
        $this->master()->transaction(function () use ($id) {
            $this->master()->table('permissions')->where('group_id', $id)->delete();
            $this->master()->table('groups')->where('id', $id)->delete();
        });
        ActivityLog::add("Deleted group {$g->name}");

        return redirect()->route('admin.groups')->with('message', "Group {$g->name} was deleted.");
    }
}
