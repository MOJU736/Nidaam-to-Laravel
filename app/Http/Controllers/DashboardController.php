<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Setting;
use App\Services\Reports\DashboardService;

/** The landing page of a company: cash, who owes you, what to pay soon, a six-month chart and what was done lately. */
class DashboardController extends Controller
{
    public function __invoke(DashboardService $dashboard)
    {
        app(\App\Services\Sales\RecurringService::class)->runDueAndNotify();

        return view('dashboard', ['company' => Setting::first(), 'd' => $dashboard->data()]);
    }
}
