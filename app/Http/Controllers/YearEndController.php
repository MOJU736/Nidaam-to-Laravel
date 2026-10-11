<?php

namespace App\Http\Controllers;

use App\Services\Settings\YearEndService;

/** Settings → Year-end close: checklist, close the financial year, reopen the last closed one. Needs the "Lock / close books" tick. */
class YearEndController extends Controller
{
    public function __construct(protected YearEndService $yearEnd)
    {
        $this->yearEnd->ensureTable();
    }

    public function index()
    {
        return view('settings.year-end', ['ye' => $this->yearEnd->status(), 'history' => $this->yearEnd->history(), 'last' => $this->yearEnd->lastClosed()]);
    }

    public function close()
    {
        return redirect()->route('settings.year-end')->with('message', $this->yearEnd->close());
    }

    public function reopen()
    {
        return redirect()->route('settings.year-end')->with('message', $this->yearEnd->reopen());
    }
}
