<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Group;
use App\Models\Tenant\Ledger;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * New / edit / delete a group. A group can't move inside itself or a group under it, and the top
 * groups (Assets, Liabilities, Income, Expenses) can't be changed because an account's type comes from them.
 */
class GroupController extends Controller
{
    public function __construct(protected ChartOfAccounts $chart) {}

    public function create(Request $request)
    {
        $choices = $this->chart->groupOptions();
        $pick = (int) $request->query('parent');

        return view('chart.group-form', ['group' => null, 'groups' => $choices, 'picked' => isset($choices[$pick]) ? $pick : 0, 'childGroups' => 0, 'childAccounts' => 0]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(null));
        $group = $this->chart->saveGroup(null, $data);
        ActivityLog::add("Added group: {$group->name}");

        return redirect()->route('chart', ['show' => 'g'.$group->id])->with('message', "Group \"{$group->name}\" was created.");
    }

    public function edit(int $id)
    {
        $group = $this->editable($id);

        return view('chart.group-form', [
            'group' => $group, 'groups' => $this->chart->groupOptions($group->id), 'picked' => (int) $group->parent_id,
            'childGroups' => Group::where('parent_id', $group->id)->count(), 'childAccounts' => Ledger::where('group_id', $group->id)->count(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $group = $this->editable($id);
        abort_if($this->chart->companyLocked(), 403, 'This company account is locked.');

        $group = $this->chart->saveGroup($group, $request->validate($this->rules($group)));
        ActivityLog::add("Updated group: {$group->name}");

        return redirect()->route('chart', ['show' => 'g'.$group->id])->with('message', "Group \"{$group->name}\" was updated.");
    }

    public function destroy(int $id)
    {
        $group = Group::findOrFail($id);
        $this->chart->deleteGroup($group);
        ActivityLog::add("Deleted group: {$group->name}");

        return redirect()->route('chart')->with('message', "Group \"{$group->name}\" was deleted.");
    }

    protected function editable(int $id): Group
    {
        $group = Group::findOrFail($id);
        abort_if(empty($group->parent_id), 403, "The top groups ({$group->name} and the others like it) can't be changed - every account's type comes from them.");

        return $group;
    }

    protected function rules(?Group $group): array
    {
        $allowed = array_keys($this->chart->groupOptions($group?->id));

        return [
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['required', 'integer', Rule::in($allowed)],
        ];
    }
}
