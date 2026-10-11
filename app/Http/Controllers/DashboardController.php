<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Bill;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Vendor;

/** Placeholder landing page; proves the tenant connection and models work. The real dashboard is ported later. */
class DashboardController extends Controller
{
    public function __invoke()
    {
        app(\App\Services\Sales\RecurringService::class)->runDueAndNotify();
        return view('dashboard', [
            'company' => Setting::first(),
            'counts' => [
                'Customers' => Customer::count(),
                'Vendors' => Vendor::count(),
                'Ledgers' => Ledger::count(),
                'Invoices' => Invoice::count(),
                'Bills' => Bill::count(),
            ],
        ]);
    }
}
