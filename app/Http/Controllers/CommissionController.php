<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Ledger;
use App\Services\Auth\Permissions;
use App\Services\Commissions\CommissionService;
use Illuminate\Http\Request;

/** Commissions: money received on a client's behalf, settled later less the company's commission. Switched on under Settings → Company. */
class CommissionController extends Controller
{
    public function __construct(protected CommissionService $commissions) {}

    protected function canManage(Request $request): bool
    {
        return $request->user()->isAdmin() || str_contains(app(Permissions::class)->groupName($request->user()), 'manager');
    }

    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['pending', 'settled', 'void'], true) ? (string) $request->query('status') : '';
        $q = trim((string) $request->query('q'));

        return view('commissions.index', ['rows' => $this->commissions->list($status ?: null, $q ?: null), 'status' => $status, 'q' => $q, 'totals' => $this->commissions->totals(), 'canManage' => $this->canManage($request)]);
    }

    public function create()
    {
        return view('commissions.receive', ['banks' => $this->commissions->bankOptions(), 'agents' => $this->agents()]);
    }

    /** Names already used, for the "who is it for" suggestions. */
    protected function agents(): array
    {
        return collect($this->commissions->summaryByAgent())->pluck('party_name')->all();
    }

    public function store(Request $request)
    {
        $id = $this->commissions->receive($request->only(['party_name', 'date', 'received_ledger_id', 'received_amount', 'notes']));

        return redirect()->route('commissions.index')->with('message', 'Recorded '.$this->commissions->label($this->commissions->find($id)['number']).'. The money is held as due to the client until you settle it.');
    }

    public function settleForm(int $id)
    {
        $t = $this->commissions->find($id);
        if (! $t || $t['status'] !== 'pending') {
            return redirect()->route('commissions.index')->with('error', 'That transaction is already settled or was voided.');
        }

        return view('commissions.settle', ['t' => $t, 'banks' => $this->commissions->bankOptions(), 's' => $this->commissions->settings()]);
    }

    public function settle(Request $request, int $id)
    {
        $this->commissions->settle($id, $request->only(['settle_date', 'commission_amount', 'commission_percent', 'remit_ledger_id']));

        return redirect()->route('commissions.index')->with('message', 'Settled '.$this->commissions->find($id)['label'].'.');
    }

    public function unsettle(Request $request, int $id)
    {
        if (! $this->canManage($request)) {
            return back()->with('error', 'Only an administrator or a manager can take a settlement back.');
        }
        $this->commissions->unsettle($id);

        return back()->with('message', 'The settlement was taken back; the money is due to the client again.');
    }

    public function void(Request $request, int $id)
    {
        if (! $this->canManage($request)) {
            return back()->with('error', 'Only an administrator or a manager can void a commission transaction.');
        }
        $this->commissions->void($id);

        return redirect()->route('commissions.index')->with('message', 'Voided. Its entries were removed from the books.');
    }

    public function settings(Request $request)
    {
        if (! $this->canManage($request)) {
            return redirect()->route('commissions.index')->with('error', 'Only an administrator or a manager can change the commission settings.');
        }
        $s = $this->commissions->settings();

        return view('commissions.settings', ['s' => $s, 'ledgers' => Ledger::orderBy('name')->get(['id', 'name'])]);
    }

    public function saveSettings(Request $request)
    {
        if (! $this->canManage($request)) {
            return redirect()->route('commissions.index')->with('error', 'Only an administrator or a manager can change the commission settings.');
        }
        $this->commissions->saveSettings($request->all());

        return redirect()->route('commissions.settings')->with('message', 'Commission settings saved.');
    }
}
