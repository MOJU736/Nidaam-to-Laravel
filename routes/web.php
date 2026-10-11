<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ActivityReportController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\ChartController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ItemCategoryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\ItemSettingsController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseSettingsController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalesSettingsController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\VehicleModelController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\VendorPaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('companies.index'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:30,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::post('/companies/{id}', [CompanyController::class, 'activate'])->whereNumber('id')->name('companies.activate');

    // Everything inside a company runs on that company's database.
    Route::middleware('tenant')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        Route::get('/accounts', ChartController::class)->name('chart');
        Route::resource('ledgers', LedgerController::class)->except(['index', 'show'])->parameters(['ledgers' => 'id'])->whereNumber('id');
        Route::resource('groups', GroupController::class)->except(['index', 'show'])->parameters(['groups' => 'id'])->whereNumber('id');

        // Journal entries. Fixed paths first so they are not read as an entry-type label.
        Route::get('/entries', [EntryController::class, 'index'])->name('entries.index');
        Route::get('/entries/transfer', [EntryController::class, 'transferForm'])->name('entries.transfer');
        Route::post('/entries/transfer', [EntryController::class, 'transfer']);
        Route::get('/entries/money-{direction}', [EntryController::class, 'simpleForm'])->whereIn('direction', ['in', 'out'])->name('entries.simple');
        Route::post('/entries/money-{direction}', [EntryController::class, 'simple'])->whereIn('direction', ['in', 'out']);
        Route::get('/entries/{label}/create', [EntryController::class, 'create'])->name('entries.create');
        Route::post('/entries/{label}', [EntryController::class, 'store'])->name('entries.store');
        Route::get('/entries/{label}/{id}', [EntryController::class, 'show'])->whereNumber('id')->name('entries.show');
        Route::get('/entries/{label}/{id}/edit', [EntryController::class, 'edit'])->whereNumber('id')->name('entries.edit');
        Route::put('/entries/{label}/{id}', [EntryController::class, 'update'])->whereNumber('id')->name('entries.update');
        Route::delete('/entries/{label}/{id}', [EntryController::class, 'destroy'])->whereNumber('id')->name('entries.destroy');

        // Items. Fixed paths first so they are not read as an item id.
        Route::get('/items', [ItemController::class, 'index'])->name('items.index');
        Route::get('/items/create', [ItemController::class, 'create'])->name('items.create');
        Route::post('/items', [ItemController::class, 'store'])->name('items.store');
        Route::post('/items/quick', [ItemController::class, 'quick'])->name('items.quick');
        Route::get('/items/settings', [ItemSettingsController::class, 'edit'])->name('items.settings');
        Route::put('/items/settings', [ItemSettingsController::class, 'update']);
        Route::get('/items/categories', [ItemCategoryController::class, 'index'])->name('items.categories');
        Route::post('/items/categories', [ItemCategoryController::class, 'store']);
        Route::put('/items/categories/{id}', [ItemCategoryController::class, 'update'])->whereNumber('id')->name('items.categories.update');
        Route::delete('/items/categories/{id}', [ItemCategoryController::class, 'destroy'])->whereNumber('id')->name('items.categories.destroy');
        Route::get('/items/vehicle-models', [VehicleModelController::class, 'index'])->name('items.vehicles');
        Route::post('/items/vehicle-models', [VehicleModelController::class, 'store']);
        Route::put('/items/vehicle-models/{id}', [VehicleModelController::class, 'update'])->whereNumber('id')->name('items.vehicles.update');
        Route::delete('/items/vehicle-models/{id}', [VehicleModelController::class, 'destroy'])->whereNumber('id')->name('items.vehicles.destroy');
        Route::get('/items/vehicle-models/{id}/prices', [VehicleModelController::class, 'prices'])->whereNumber('id')->name('items.vehicles.prices');
        Route::put('/items/vehicle-models/{id}/prices', [VehicleModelController::class, 'savePrices'])->whereNumber('id');
        Route::get('/items/adjustments', [StockController::class, 'adjustments'])->name('items.adjustments');
        Route::get('/items/adjustments/create', [StockController::class, 'adjustmentCreate'])->name('items.adjustments.create');
        Route::post('/items/adjustments', [StockController::class, 'adjustmentStore'])->name('items.adjustments.store');
        Route::get('/items/adjustments/{id}', [StockController::class, 'adjustmentShow'])->whereNumber('id')->name('items.adjustments.show');
        Route::post('/items/adjustments/{id}/void', [StockController::class, 'adjustmentVoid'])->whereNumber('id')->name('items.adjustments.void');
        Route::put('/items/batches/{batchId}', [StockController::class, 'batchUpdate'])->whereNumber('batchId')->name('items.batches.update');
        Route::get('/items/{id}/edit', [ItemController::class, 'edit'])->whereNumber('id')->name('items.edit');
        Route::put('/items/{id}', [ItemController::class, 'update'])->whereNumber('id')->name('items.update');
        Route::delete('/items/{id}', [ItemController::class, 'destroy'])->whereNumber('id')->name('items.destroy');
        Route::get('/items/{id}/batches', [StockController::class, 'batches'])->whereNumber('id')->name('items.batches');
        Route::post('/items/{id}/batches', [StockController::class, 'batchStore'])->whereNumber('id');

        // Sales. Fixed paths first so they are not read as an id.
        Route::get('/sales', fn () => redirect()->route('invoices.index'))->name('sales');
        Route::get('/sales/settings', [SalesSettingsController::class, 'edit'])->name('sales.settings');
        Route::put('/sales/settings', [SalesSettingsController::class, 'update']);

        Route::get('/quotations', [QuotationController::class, 'index'])->name('quotations.index');
        Route::get('/quotations/create/{customer?}', [QuotationController::class, 'create'])->whereNumber('customer')->name('quotations.create');
        Route::post('/quotations', [QuotationController::class, 'store'])->name('quotations.store');
        Route::get('/quotations/{id}', [QuotationController::class, 'show'])->whereNumber('id')->name('quotations.show');
        Route::get('/quotations/{id}/edit', [QuotationController::class, 'edit'])->whereNumber('id')->name('quotations.edit');
        Route::put('/quotations/{id}', [QuotationController::class, 'update'])->whereNumber('id')->name('quotations.update');
        Route::delete('/quotations/{id}', [QuotationController::class, 'destroy'])->whereNumber('id')->name('quotations.destroy');
        Route::post('/quotations/{id}/convert', [QuotationController::class, 'convert'])->whereNumber('id')->name('quotations.convert');

        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/create/{customer?}', [InvoiceController::class, 'create'])->whereNumber('customer')->name('invoices.create');
        Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/invoices/{id}', [InvoiceController::class, 'show'])->whereNumber('id')->name('invoices.show');
        Route::get('/invoices/{id}/edit', [InvoiceController::class, 'edit'])->whereNumber('id')->name('invoices.edit');
        Route::put('/invoices/{id}', [InvoiceController::class, 'update'])->whereNumber('id')->name('invoices.update');
        Route::delete('/invoices/{id}', [InvoiceController::class, 'destroy'])->whereNumber('id')->name('invoices.destroy');
        Route::post('/invoices/{id}/issue', [InvoiceController::class, 'issue'])->whereNumber('id')->name('invoices.issue');
        Route::post('/invoices/{id}/void', [InvoiceController::class, 'void'])->whereNumber('id')->name('invoices.void');
        Route::post('/invoices/{id}/revert', [InvoiceController::class, 'revert'])->whereNumber('id')->name('invoices.revert');
        Route::post('/invoices/{id}/apply-credit', [InvoiceController::class, 'applyCredit'])->whereNumber('id')->name('invoices.apply-credit');

        Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('/payments/create/{customer?}', [PaymentController::class, 'create'])->whereNumber('customer')->name('payments.create');
        Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::get('/payments/open-invoices/{customer}', [PaymentController::class, 'openInvoices'])->whereNumber('customer')->name('payments.open-invoices');
        Route::post('/payments/allocations/{allocationId}/remove', [PaymentController::class, 'removeAllocation'])->whereNumber('allocationId')->name('payments.allocation.remove');
        Route::get('/payments/{id}', [PaymentController::class, 'show'])->whereNumber('id')->name('payments.show');
        Route::get('/payments/{id}/edit', [PaymentController::class, 'edit'])->whereNumber('id')->name('payments.edit');
        Route::put('/payments/{id}', [PaymentController::class, 'update'])->whereNumber('id')->name('payments.update');
        Route::post('/payments/{id}/apply', [PaymentController::class, 'apply'])->whereNumber('id')->name('payments.apply');
        Route::post('/payments/{id}/void', [PaymentController::class, 'void'])->whereNumber('id')->name('payments.void');
        Route::delete('/payments/{id}', [PaymentController::class, 'destroy'])->whereNumber('id')->name('payments.destroy');

        // Purchases. Fixed paths first so they are not read as an id.
        Route::get('/purchases', fn () => redirect()->route('bills.index'))->name('purchases');
        Route::get('/purchases/settings', [PurchaseSettingsController::class, 'edit'])->name('purchases.settings');
        Route::put('/purchases/settings', [PurchaseSettingsController::class, 'update']);

        Route::get('/bills', [BillController::class, 'index'])->name('bills.index');
        Route::get('/bills/create/{vendor?}', [BillController::class, 'create'])->whereNumber('vendor')->name('bills.create');
        Route::post('/bills', [BillController::class, 'store'])->name('bills.store');
        Route::get('/bills/{id}', [BillController::class, 'show'])->whereNumber('id')->name('bills.show');
        Route::get('/bills/{id}/edit', [BillController::class, 'edit'])->whereNumber('id')->name('bills.edit');
        Route::put('/bills/{id}', [BillController::class, 'update'])->whereNumber('id')->name('bills.update');
        Route::delete('/bills/{id}', [BillController::class, 'destroy'])->whereNumber('id')->name('bills.destroy');
        Route::post('/bills/{id}/issue', [BillController::class, 'issue'])->whereNumber('id')->name('bills.issue');
        Route::post('/bills/{id}/void', [BillController::class, 'void'])->whereNumber('id')->name('bills.void');
        Route::post('/bills/{id}/revert', [BillController::class, 'revert'])->whereNumber('id')->name('bills.revert');
        Route::post('/bills/{id}/apply-credit', [BillController::class, 'applyCredit'])->whereNumber('id')->name('bills.apply-credit');

        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
        Route::get('/purchase-orders/create/{vendor?}', [PurchaseOrderController::class, 'create'])->whereNumber('vendor')->name('purchase-orders.create');
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
        Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show'])->whereNumber('id')->name('purchase-orders.show');
        Route::get('/purchase-orders/{id}/edit', [PurchaseOrderController::class, 'edit'])->whereNumber('id')->name('purchase-orders.edit');
        Route::put('/purchase-orders/{id}', [PurchaseOrderController::class, 'update'])->whereNumber('id')->name('purchase-orders.update');
        Route::delete('/purchase-orders/{id}', [PurchaseOrderController::class, 'destroy'])->whereNumber('id')->name('purchase-orders.destroy');
        Route::post('/purchase-orders/{id}/convert', [PurchaseOrderController::class, 'convert'])->whereNumber('id')->name('purchase-orders.convert');

        Route::get('/vendor-payments', [VendorPaymentController::class, 'index'])->name('vendor-payments.index');
        Route::get('/vendor-payments/create/{vendor?}', [VendorPaymentController::class, 'create'])->whereNumber('vendor')->name('vendor-payments.create');
        Route::post('/vendor-payments', [VendorPaymentController::class, 'store'])->name('vendor-payments.store');
        Route::get('/vendor-payments/open-bills/{vendor}', [VendorPaymentController::class, 'openBills'])->whereNumber('vendor')->name('vendor-payments.open-bills');
        Route::post('/vendor-payments/allocations/{allocationId}/remove', [VendorPaymentController::class, 'removeAllocation'])->whereNumber('allocationId')->name('vendor-payments.allocation.remove');
        Route::get('/vendor-payments/{id}', [VendorPaymentController::class, 'show'])->whereNumber('id')->name('vendor-payments.show');
        Route::get('/vendor-payments/{id}/edit', [VendorPaymentController::class, 'edit'])->whereNumber('id')->name('vendor-payments.edit');
        Route::put('/vendor-payments/{id}', [VendorPaymentController::class, 'update'])->whereNumber('id')->name('vendor-payments.update');
        Route::post('/vendor-payments/{id}/apply', [VendorPaymentController::class, 'apply'])->whereNumber('id')->name('vendor-payments.apply');
        Route::post('/vendor-payments/{id}/void', [VendorPaymentController::class, 'void'])->whereNumber('id')->name('vendor-payments.void');
        Route::delete('/vendor-payments/{id}', [VendorPaymentController::class, 'destroy'])->whereNumber('id')->name('vendor-payments.destroy');

        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::get('/expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::get('/expenses/{id}', [ExpenseController::class, 'show'])->whereNumber('id')->name('expenses.show');
        Route::post('/expenses/{id}/void', [ExpenseController::class, 'void'])->whereNumber('id')->name('expenses.void');
        Route::delete('/expenses/{id}', [ExpenseController::class, 'destroy'])->whereNumber('id')->name('expenses.destroy');

        Route::resource('customers', CustomerController::class)->parameters(['customers' => 'id'])->whereNumber('id');
        Route::resource('vendors', VendorController::class)->parameters(['vendors' => 'id'])->whereNumber('id');

        // Credit notes (customers) and supplier credits (vendors): one controller, the route's `side` says which. Plus refunds and write-offs.
        foreach (['ar' => 'credit-notes', 'ap' => 'supplier-credits'] as $side => $name) {
            $r = fn (string $verb, string $uri, string $action, string $as) => Route::$verb("/$name$uri", [CreditNoteController::class, $action])->defaults('side', $side)->name("$name.$as");
            $r('get', '', 'index', 'index');
            $r('get', '/create', 'create', 'create');
            $r('post', '', 'store', 'store');
            $r('get', '/{id}', 'show', 'show')->whereNumber('id');
            $r('get', '/{id}/edit', 'edit', 'edit')->whereNumber('id');
            $r('put', '/{id}', 'update', 'update')->whereNumber('id');
            $r('post', '/{id}/issue', 'issue', 'issue')->whereNumber('id');
            $r('post', '/{id}/void', 'void', 'void')->whereNumber('id');
            $r('delete', '/{id}', 'destroy', 'destroy')->whereNumber('id');
            $r('post', '/{id}/apply', 'apply', 'apply')->whereNumber('id');
            $r('post', '/{id}/refund', 'refund', 'refund')->whereNumber('id');
            $r('post', '/allocations/{allocationId}/remove', 'removeAllocation', 'allocation.remove')->whereNumber('allocationId');
            $r('post', '/refunds/{refundId}/delete', 'refundDelete', 'refund.delete')->whereNumber('refundId');
        }
        Route::post('/payments/{id}/refund', [CreditNoteController::class, 'refundPayment'])->defaults('side', 'ar')->whereNumber('id')->name('payments.refund');
        Route::post('/vendor-payments/{id}/refund', [CreditNoteController::class, 'refundPayment'])->defaults('side', 'ap')->whereNumber('id')->name('vendor-payments.refund');
        Route::get('/invoices/{id}/write-off', [CreditNoteController::class, 'writeOffForm'])->whereNumber('id')->name('invoices.write-off');
        Route::post('/invoices/{id}/write-off', [CreditNoteController::class, 'writeOff'])->whereNumber('id');

        // Reports (read-only; dates come from the address, ?download=csv repeats the page as a file)
        Route::prefix('reports')->group(function () {
            Route::get('/', [ReportController::class, 'index'])->name('reports.index');
            Route::get('/balance-sheet', [ReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
            Route::get('/profit-loss', [ReportController::class, 'profitLoss'])->name('reports.profit-loss');
            Route::get('/trial-balance', [ReportController::class, 'trialBalance'])->name('reports.trial-balance');
            Route::get('/account-statement', [ReportController::class, 'statement'])->name('reports.statement');
            Route::get('/ar-aging', [ReportController::class, 'aging'])->defaults('side', 'ar')->name('reports.ar-aging');
            Route::get('/ap-aging', [ReportController::class, 'aging'])->defaults('side', 'ap')->name('reports.ap-aging');
            Route::get('/customer-balance', [ReportController::class, 'partyBalance'])->defaults('side', 'ar')->name('reports.customer-balance');
            Route::get('/vendor-balance', [ReportController::class, 'partyBalance'])->defaults('side', 'ap')->name('reports.vendor-balance');
            Route::get('/journal', [ReportController::class, 'journal'])->name('reports.journal');
            Route::get('/open-invoices', [ActivityReportController::class, 'openDocs'])->defaults('side', 'ar')->name('reports.open-invoices');
            Route::get('/unpaid-bills', [ActivityReportController::class, 'openDocs'])->defaults('side', 'ap')->name('reports.unpaid-bills');
            Route::get('/sales-by-customer', [ActivityReportController::class, 'salesByCustomer'])->name('reports.sales-by-customer');
            Route::get('/sales-by-item', [ActivityReportController::class, 'byItem'])->defaults('side', 'ar')->name('reports.sales-by-item');
            Route::get('/purchases-by-vendor', [ActivityReportController::class, 'purchasesByVendor'])->name('reports.purchases-by-vendor');
            Route::get('/purchases-by-item', [ActivityReportController::class, 'byItem'])->defaults('side', 'ap')->name('reports.purchases-by-item');
            Route::get('/tax-summary', [ActivityReportController::class, 'tax'])->name('reports.tax-summary');
            Route::get('/inventory-valuation', [ActivityReportController::class, 'inventory'])->name('reports.inventory-valuation');
            Route::get('/reorder', [ActivityReportController::class, 'reorder'])->name('reports.reorder');
            Route::get('/profit-loss-by-month', [ActivityReportController::class, 'plByMonth'])->name('reports.pl-by-month');
        });
    });
});
