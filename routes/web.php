<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\ActivityReportController;
use App\Http\Controllers\AdvancedReportController;
use App\Http\Controllers\AdminGroupsController;
use App\Http\Controllers\AdminUsersController;
use App\Http\Controllers\BankingController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\ChartController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentTemplatesController;
use App\Http\Controllers\EmailSettingsController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ItemCategoryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\ItemSettingsController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\EstimateController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\WorkforceController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseSettingsController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\RecurringInvoiceController;
use App\Http\Controllers\RemindersController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalesSettingsController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\YearEndController;
use App\Http\Controllers\SettingsListsController;
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

// Forgot password and the link in an invite / reset email (no sign-in needed).
Route::middleware('guest')->group(function () {
    Route::get('/password/forgot', [PasswordController::class, 'forgot'])->name('password.forgot');
    Route::post('/password/forgot', [PasswordController::class, 'sendLink'])->middleware('throttle:20,1')->name('password.forgot.send');
});
Route::get('/password/set/{code}', [PasswordController::class, 'setForm'])->name('password.set');
Route::post('/password/set/{code}', [PasswordController::class, 'setSave'])->middleware('throttle:20,1')->name('password.set.save');

Route::middleware(['auth', 'fresh-password'])->group(function () {
    Route::get('/password/first', [PasswordController::class, 'firstForm'])->name('password.first');
    Route::post('/password/first', [PasswordController::class, 'firstSave'])->name('password.first.save');
    Route::get('/password/change', [PasswordController::class, 'changeForm'])->name('password.change');
    Route::post('/password/change', [PasswordController::class, 'changeSave'])->name('password.change.save');
});

Route::middleware(['auth', 'fresh-password'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::post('/companies/{id}', [CompanyController::class, 'activate'])->whereNumber('id')->name('companies.activate');

    // Everything inside a company runs on that company's database.
    Route::middleware(['tenant', 'page'])->group(function () {
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

        // Admin: users, groups and what each group may do (administrators only).
        Route::prefix('admin')->group(function () {
            Route::get('/users', [AdminUsersController::class, 'index'])->name('admin.users');
            Route::get('/users/create', [AdminUsersController::class, 'create'])->name('admin.users.create');
            Route::post('/users', [AdminUsersController::class, 'store'])->name('admin.users.store');
            Route::get('/users/{id}/edit', [AdminUsersController::class, 'edit'])->whereNumber('id')->name('admin.users.edit');
            Route::put('/users/{id}', [AdminUsersController::class, 'update'])->whereNumber('id')->name('admin.users.update');
            Route::delete('/users/{id}', [AdminUsersController::class, 'destroy'])->whereNumber('id')->name('admin.users.destroy');
        Route::post('/users/{id}/reset-link', [AdminUsersController::class, 'resetLink'])->whereNumber('id')->name('admin.users.reset-link');
            Route::get('/groups', [AdminGroupsController::class, 'index'])->name('admin.groups');
            Route::get('/groups/create', [AdminGroupsController::class, 'create'])->name('admin.groups.create');
            Route::post('/groups', [AdminGroupsController::class, 'store'])->name('admin.groups.store');
            Route::get('/groups/{id}/edit', [AdminGroupsController::class, 'edit'])->whereNumber('id')->name('admin.groups.edit');
            Route::put('/groups/{id}', [AdminGroupsController::class, 'update'])->whereNumber('id')->name('admin.groups.update');
            Route::delete('/groups/{id}', [AdminGroupsController::class, 'destroy'])->whereNumber('id')->name('admin.groups.destroy');
        });

        // Payment reminders (emails with the customer's statement attached; never sent on their own).
        Route::get('/reminders', [RemindersController::class, 'index'])->name('reminders.index');
        Route::post('/reminders/send', [RemindersController::class, 'send'])->name('reminders.send');
        Route::post('/reminders/message', [RemindersController::class, 'message'])->name('reminders.message');
        Route::post('/reminders/customer/{id}', [RemindersController::class, 'one'])->whereNumber('id')->name('reminders.one');

        // PDFs and email of documents; the company's email (SMTP) settings.
        Route::get('/documents/{type}/{id}/pdf', [DocumentController::class, 'pdf'])->whereNumber('id')->name('documents.pdf');
        Route::post('/documents/{type}/{id}/email', [DocumentController::class, 'email'])->whereNumber('id')->name('documents.email');
        Route::get('/settings/templates', [DocumentTemplatesController::class, 'edit'])->name('settings.templates');
        Route::post('/settings/templates', [DocumentTemplatesController::class, 'update'])->name('settings.templates.update');
        Route::post('/settings/templates/preview', [DocumentTemplatesController::class, 'preview'])->name('settings.templates.preview');
        Route::get('/settings/email', [EmailSettingsController::class, 'edit'])->name('settings.email');
        Route::put('/settings/email', [EmailSettingsController::class, 'update'])->name('settings.email.update');

        // Data Sharing (a company with it switched on): SIM board, clients, money in and out, profit, reports and the monthly presentation.
        Route::middleware('datasharing')->prefix('datasharing')->name('datasharing.')->group(function () {
            Route::get('/', [\App\Http\Controllers\DataSharingController::class, 'index'])->name('index');
            Route::get('/sims', [\App\Http\Controllers\DataSharingController::class, 'sims'])->name('sims');
            Route::get('/clients', [\App\Http\Controllers\DataSharingController::class, 'clients'])->name('clients');
            Route::get('/client/{id}', [\App\Http\Controllers\DataSharingController::class, 'client'])->whereNumber('id')->name('client');
            Route::get('/money', [\App\Http\Controllers\DataSharingController::class, 'money'])->name('money');
            Route::get('/money-in', [\App\Http\Controllers\DataSharingController::class, 'moneyIn'])->name('money-in');
            Route::get('/profit', [\App\Http\Controllers\DataSharingController::class, 'profit'])->name('profit');
            Route::get('/reports', [\App\Http\Controllers\DataSharingController::class, 'reports'])->name('reports');
            Route::get('/deck', [\App\Http\Controllers\DataSharingController::class, 'deck'])->name('deck');
            Route::get('/plans', [\App\Http\Controllers\DataSharingController::class, 'plans'])->name('plans');
            foreach (['sim-add' => 'simAdd', 'client-add' => 'clientAdd', 'move' => 'move', 'expense-add' => 'expenseAdd', 'income-add' => 'incomeAdd', 'plan-add' => 'planAdd', 'plan-move' => 'planMove', 'cycle-start' => 'cycleStart', 'close' => 'close'] as $uri => $method) {
                Route::post('/'.$uri, [\App\Http\Controllers\DataSharingController::class, $method])->name($uri);
            }
            foreach (['sim-save' => 'simSave', 'sim-renew' => 'simRenew', 'sim-retire' => 'simRetire', 'client-save' => 'clientSave', 'client-remove' => 'clientRemove', 'pay' => 'pay', 'recharge' => 'recharge', 'payment-void' => 'paymentVoid',
                'expense-void' => 'expenseVoid', 'income-void' => 'incomeVoid', 'usage-add' => 'usageAdd', 'usage-sim' => 'usageSim', 'usage-delete' => 'usageDelete', 'note-done' => 'noteDone', 'plan-save' => 'planSave', 'plan-toggle' => 'planToggle', 'plan-delete' => 'planDelete'] as $uri => $method) {
                Route::post('/'.$uri.'/{id}', [\App\Http\Controllers\DataSharingController::class, $method])->whereNumber('id')->name($uri);
            }
        });
        // Construction (a company with it switched on): projects, claims, workers and wage sheets.
        Route::middleware('construction')->group(function () {
            Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
            Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
            Route::get('/projects/{id}', [ProjectController::class, 'show'])->whereNumber('id')->name('projects.show');
            Route::get('/projects/{id}/edit', [ProjectController::class, 'edit'])->whereNumber('id')->name('projects.edit');
            Route::put('/projects/{id}', [ProjectController::class, 'update'])->whereNumber('id')->name('projects.update');
            Route::delete('/projects/{id}', [ProjectController::class, 'destroy'])->whereNumber('id')->name('projects.destroy');
            Route::post('/projects/{id}/variations', [ProjectController::class, 'variationStore'])->whereNumber('id')->name('projects.variations.store');
            Route::delete('/projects/variations/{variationId}', [ProjectController::class, 'variationDestroy'])->whereNumber('variationId')->name('projects.variations.destroy');
            Route::post('/projects/{id}/claims', [ProjectController::class, 'claimStore'])->whereNumber('id')->name('projects.claims.store');
            Route::post('/projects/{id}/retention', [ProjectController::class, 'retentionRelease'])->whereNumber('id')->name('projects.retention');
            Route::post('/projects/{id}/advances', [ProjectController::class, 'advanceStore'])->whereNumber('id')->name('projects.advances.store');
            // estimate (bill of quantities), rate list and units
            Route::get('/projects/{id}/estimate', [EstimateController::class, 'show'])->whereNumber('id')->name('projects.estimate');
            Route::post('/projects/{id}/estimates', [EstimateController::class, 'create'])->whereNumber('id')->name('projects.estimate.create');
            Route::post('/estimates/{eid}/revise', [EstimateController::class, 'revise'])->whereNumber('eid')->name('projects.estimate.revise');
            Route::put('/estimates/{eid}', [EstimateController::class, 'save'])->whereNumber('eid')->name('projects.estimate.save');
            Route::post('/estimates/{eid}/approve', [EstimateController::class, 'approve'])->whereNumber('eid')->name('projects.estimate.approve');
            Route::delete('/estimates/{eid}', [EstimateController::class, 'destroy'])->whereNumber('eid')->name('projects.estimate.destroy');
            Route::get('/estimates/{eid}/pdf', [EstimateController::class, 'pdf'])->whereNumber('eid')->name('projects.estimate.pdf');
            Route::post('/estimates/{eid}/email', [EstimateController::class, 'email'])->whereNumber('eid')->name('projects.estimate.email');
            Route::get('/construction/rates', [EstimateController::class, 'rates'])->name('construction.rates');
            Route::post('/construction/rates', [EstimateController::class, 'rateSave'])->name('construction.rates.store');
            Route::put('/construction/rates/{id}', [EstimateController::class, 'rateSave'])->whereNumber('id')->name('construction.rates.update');
            Route::delete('/construction/rates/{id}', [EstimateController::class, 'rateDelete'])->whereNumber('id')->name('construction.rates.destroy');
            Route::post('/construction/units', [EstimateController::class, 'unitAdd'])->name('construction.units.store');
            Route::delete('/construction/units', [EstimateController::class, 'unitDelete'])->name('construction.units.destroy');
            // schedule, weekly site reports, claims by measurement, site stock
            Route::get('/projects/{id}/schedule', [SiteController::class, 'schedule'])->whereNumber('id')->name('projects.schedule');
            Route::put('/projects/{id}/schedule', [SiteController::class, 'scheduleSave'])->whereNumber('id')->name('projects.schedule.save');
            Route::post('/projects/{id}/schedule/standard', [SiteController::class, 'scheduleStandard'])->whereNumber('id')->name('projects.schedule.standard');
            Route::get('/projects/{id}/reports', [SiteController::class, 'reports'])->whereNumber('id')->name('projects.reports');
            Route::post('/projects/{id}/reports', [SiteController::class, 'reportAdd'])->whereNumber('id')->name('projects.reports.store');
            Route::get('/site-reports/{rid}', [SiteController::class, 'report'])->whereNumber('rid')->name('projects.report');
            Route::put('/site-reports/{rid}', [SiteController::class, 'reportSave'])->whereNumber('rid')->name('projects.report.save');
            Route::post('/site-reports/{rid}/status', [SiteController::class, 'reportStatus'])->whereNumber('rid')->name('projects.report.status');
            Route::delete('/site-reports/{rid}', [SiteController::class, 'reportDelete'])->whereNumber('rid')->name('projects.report.destroy');
            Route::post('/site-reports/{rid}/photos', [SiteController::class, 'photoAdd'])->whereNumber('rid')->name('projects.report.photos');
            Route::delete('/site-reports/photos/{photoId}', [SiteController::class, 'photoDelete'])->whereNumber('photoId')->name('projects.report.photos.destroy');
            Route::get('/site-reports/{rid}/pdf', [SiteController::class, 'reportPdf'])->whereNumber('rid')->name('projects.report.pdf');
            Route::post('/site-reports/{rid}/email', [SiteController::class, 'reportEmail'])->whereNumber('rid')->name('projects.report.email');
            Route::post('/projects/{id}/claim-method', [SiteController::class, 'claimMethod'])->whereNumber('id')->name('projects.claim-method');
            Route::get('/claims/{cid}/sheet', [SiteController::class, 'claimSheet'])->whereNumber('cid')->name('projects.claim-sheet');
            Route::get('/construction/stock', [SiteController::class, 'stock'])->name('construction.stock');
            Route::post('/construction/stores', [SiteController::class, 'storeAdd'])->name('construction.stock.stores');
            Route::post('/construction/stock/move', [SiteController::class, 'stockMove'])->name('construction.stock.move');
            Route::post('/construction/stock/{id}/void', [SiteController::class, 'stockVoid'])->whereNumber('id')->name('construction.stock.void');
            Route::get('/construction/workers', [WorkforceController::class, 'workers'])->name('construction.workers');
            Route::post('/construction/workers', [WorkforceController::class, 'workerSave'])->name('construction.workers.store');
            Route::put('/construction/workers/{id}', [WorkforceController::class, 'workerSave'])->whereNumber('id')->name('construction.workers.update');
            Route::get('/construction/wages', [WorkforceController::class, 'sheets'])->name('construction.wages');
            Route::get('/construction/wages/create', [WorkforceController::class, 'sheetCreate'])->name('construction.wages.create');
            Route::post('/construction/wages', [WorkforceController::class, 'sheetStore'])->name('construction.wages.store');
            Route::get('/construction/wages/{id}', [WorkforceController::class, 'sheetShow'])->whereNumber('id')->name('construction.wages.show');
            Route::post('/construction/wages/{id}/pay', [WorkforceController::class, 'sheetPay'])->whereNumber('id')->name('construction.wages.pay');
            Route::post('/construction/wages/{id}/void', [WorkforceController::class, 'sheetVoid'])->whereNumber('id')->name('construction.wages.void');
        });

        // Company settings: home, details and financial year, close / lock the books, and the small lists.
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::get('/settings/company', [SettingsController::class, 'company'])->name('settings.company');
        Route::put('/settings/company', [SettingsController::class, 'updateCompany'])->name('settings.company.update');
        Route::get('/settings/close-books', [SettingsController::class, 'closeBooks'])->name('settings.close-books');
        Route::put('/settings/close-books', [SettingsController::class, 'updateCloseBooks'])->name('settings.close-books.update');
        Route::get('/settings/year-end', [YearEndController::class, 'index'])->name('settings.year-end');
        Route::post('/settings/year-end/close', [YearEndController::class, 'close'])->name('settings.year-end.close');
        Route::post('/settings/year-end/reopen', [YearEndController::class, 'reopen'])->name('settings.year-end.reopen');
        Route::get('/settings/backup', [BackupController::class, 'index'])->name('settings.backup');
        Route::post('/settings/backup', [BackupController::class, 'download'])->name('settings.backup.download');
        Route::get('/settings/backup/auto/{date}/{file}', [BackupController::class, 'auto'])->name('settings.backup.auto');
        Route::match(['get', 'post'], '/settings/quickbooks', [\App\Http\Controllers\QuickBooksController::class, 'index'])->name('settings.quickbooks');
        Route::post('/settings/quickbooks/import', [\App\Http\Controllers\QuickBooksController::class, 'import'])->name('settings.quickbooks.import');
        Route::post('/settings/quickbooks/cancel', [\App\Http\Controllers\QuickBooksController::class, 'cancel'])->name('settings.quickbooks.cancel');
        Route::get('/settings/lock', [SettingsController::class, 'lock'])->name('settings.lock');
        Route::put('/settings/lock', [SettingsController::class, 'updateLock'])->name('settings.lock.update');
        Route::get('/settings/tax-rates', [SettingsListsController::class, 'taxRates'])->name('settings.tax-rates');
        Route::post('/settings/tax-rates', [SettingsListsController::class, 'saveTaxRate'])->name('settings.tax-rates.store');
        Route::put('/settings/tax-rates/{id}', [SettingsListsController::class, 'saveTaxRate'])->whereNumber('id')->name('settings.tax-rates.update');
        Route::delete('/settings/tax-rates/{id}', [SettingsListsController::class, 'deleteTaxRate'])->whereNumber('id')->name('settings.tax-rates.destroy');
        Route::get('/settings/payment-methods', [SettingsListsController::class, 'paymentMethods'])->name('settings.payment-methods');
        Route::post('/settings/payment-methods', [SettingsListsController::class, 'savePaymentMethod'])->name('settings.payment-methods.store');
        Route::put('/settings/payment-methods/{id}', [SettingsListsController::class, 'savePaymentMethod'])->whereNumber('id')->name('settings.payment-methods.update');
        Route::delete('/settings/payment-methods/{id}', [SettingsListsController::class, 'deletePaymentMethod'])->whereNumber('id')->name('settings.payment-methods.destroy');
        Route::get('/settings/entry-types', [SettingsListsController::class, 'entryTypes'])->name('settings.entry-types');
        Route::post('/settings/entry-types', [SettingsListsController::class, 'saveEntryType'])->name('settings.entry-types.store');
        Route::put('/settings/entry-types/{id}', [SettingsListsController::class, 'saveEntryType'])->whereNumber('id')->name('settings.entry-types.update');
        Route::delete('/settings/entry-types/{id}', [SettingsListsController::class, 'deleteEntryType'])->whereNumber('id')->name('settings.entry-types.destroy');
        Route::get('/settings/tags', [SettingsListsController::class, 'tags'])->name('settings.tags');
        Route::post('/settings/tags', [SettingsListsController::class, 'saveTag'])->name('settings.tags.store');
        Route::put('/settings/tags/{id}', [SettingsListsController::class, 'saveTag'])->whereNumber('id')->name('settings.tags.update');
        Route::delete('/settings/tags/{id}', [SettingsListsController::class, 'deleteTag'])->whereNumber('id')->name('settings.tags.destroy');

        // Recurring invoices (created as drafts).
        Route::get('/recurring', [RecurringInvoiceController::class, 'index'])->name('recurring.index');
        Route::get('/recurring/create', [RecurringInvoiceController::class, 'create'])->name('recurring.create');
        Route::post('/recurring', [RecurringInvoiceController::class, 'store'])->name('recurring.store');
        Route::get('/recurring/{id}/edit', [RecurringInvoiceController::class, 'edit'])->whereNumber('id')->name('recurring.edit');
        Route::put('/recurring/{id}', [RecurringInvoiceController::class, 'update'])->whereNumber('id')->name('recurring.update');
        Route::post('/recurring/{id}/toggle', [RecurringInvoiceController::class, 'toggle'])->whereNumber('id')->name('recurring.toggle');
        Route::post('/recurring/{id}/run', [RecurringInvoiceController::class, 'runNow'])->whereNumber('id')->name('recurring.run');
        Route::delete('/recurring/{id}', [RecurringInvoiceController::class, 'destroy'])->whereNumber('id')->name('recurring.destroy');

        // Banking: reconcile an account against a bank statement.
        Route::get('/banking', [BankingController::class, 'index'])->name('banking.index');
        Route::match(['get', 'post'], '/banking/reconcile/{id}', [BankingController::class, 'reconcile'])->whereNumber('id')->name('banking.reconcile');
        Route::match(['get', 'post'], '/banking/rec/{id}', [BankingController::class, 'rec'])->whereNumber('id')->name('banking.rec');
        Route::get('/banking/history/{id}', [BankingController::class, 'history'])->whereNumber('id')->name('banking.history');
        Route::get('/banking/report/{id}', [BankingController::class, 'report'])->whereNumber('id')->name('banking.report');
        Route::post('/banking/undo/{id}', [BankingController::class, 'undo'])->whereNumber('id')->name('banking.undo');

        // Payroll (confidential: administrators and groups ticked "Payroll" under Groups & permissions).
        Route::prefix('payroll')->group(function () {
            Route::get('/', [PayrollController::class, 'index'])->name('payroll.index');
            Route::get('/employees', [PayrollController::class, 'employees'])->name('payroll.employees');
            Route::post('/employees', [PayrollController::class, 'employeeStore'])->name('payroll.employees.store');
            Route::get('/employees/{id}', [PayrollController::class, 'employeeShow'])->whereNumber('id')->name('payroll.employees.show');
            Route::put('/employees/{id}', [PayrollController::class, 'employeeUpdate'])->whereNumber('id')->name('payroll.employees.update');
            Route::get('/advances', [PayrollController::class, 'advances'])->name('payroll.advances');
            Route::post('/advances', [PayrollController::class, 'advanceStore'])->name('payroll.advances.store');
            Route::post('/advances/{id}/void', [PayrollController::class, 'advanceVoid'])->whereNumber('id')->name('payroll.advances.void');
            Route::post('/runs', [PayrollController::class, 'runStore'])->name('payroll.runs.store');
            Route::get('/runs/{id}', [PayrollController::class, 'runShow'])->whereNumber('id')->name('payroll.runs.show');
            Route::put('/runs/{id}', [PayrollController::class, 'runUpdate'])->whereNumber('id')->name('payroll.runs.update');
            Route::delete('/runs/{id}', [PayrollController::class, 'runDestroy'])->whereNumber('id')->name('payroll.runs.destroy');
            Route::post('/runs/{id}/approve', [PayrollController::class, 'runApprove'])->whereNumber('id')->name('payroll.runs.approve');
            Route::post('/runs/{id}/pay', [PayrollController::class, 'runPay'])->whereNumber('id')->name('payroll.runs.pay');
            Route::post('/runs/{id}/void', [PayrollController::class, 'runVoid'])->whereNumber('id')->name('payroll.runs.void');
            Route::post('/payments/{id}/void', [PayrollController::class, 'paymentVoid'])->whereNumber('id')->name('payroll.payments.void');
            Route::get('/payslips/{lineId}', [PayrollController::class, 'payslip'])->whereNumber('lineId')->name('payroll.payslip');
            Route::get('/payslips/{lineId}/pdf', [PayrollController::class, 'payslipPdf'])->whereNumber('lineId')->name('payroll.payslip.pdf');
            Route::post('/payslips/{lineId}/email', [PayrollController::class, 'payslipEmail'])->whereNumber('lineId')->name('payroll.payslip.email');
            Route::get('/reports', [PayrollController::class, 'reports'])->name('payroll.reports');
        });

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
            Route::get('/cash-flow', [AdvancedReportController::class, 'cashFlow'])->name('reports.cash-flow');
            Route::get('/general-ledger', [AdvancedReportController::class, 'generalLedger'])->name('reports.general-ledger');
            Route::get('/profit-loss-compare', [AdvancedReportController::class, 'plCompare'])->name('reports.pl-compare');
            Route::get('/profit-loss-by-project', [AdvancedReportController::class, 'plByProject'])->name('reports.pl-by-project');
            Route::get('/balance-sheet-compare', [AdvancedReportController::class, 'bsCompare'])->name('reports.bs-compare');
            Route::get('/profit-by-item', [AdvancedReportController::class, 'profitByItem'])->name('reports.profit-by-item');
            Route::get('/payments-received', [AdvancedReportController::class, 'paymentsReceived'])->name('reports.payments-received');
            Route::get('/customer-items', [AdvancedReportController::class, 'customerItems'])->name('reports.customer-items');
            Route::post('/customer-items/email', [AdvancedReportController::class, 'customerItemsEmail'])->name('reports.customer-items.email');
            Route::get('/stock-expiry', [AdvancedReportController::class, 'stockExpiry'])->name('reports.stock-expiry');
            Route::get('/health-check', [AdvancedReportController::class, 'health'])->name('reports.health');
            Route::post('/health-check/repair', [AdvancedReportController::class, 'healthRepair'])->name('reports.health-repair');
        Route::get('/cash-flow', [AdvancedReportController::class, 'cashFlow'])->name('reports.cash-flow');
        Route::get('/general-ledger', [AdvancedReportController::class, 'generalLedger'])->name('reports.general-ledger');
        Route::get('/profit-loss-compare', [AdvancedReportController::class, 'plCompare'])->name('reports.pl-compare');
        Route::get('/profit-loss-by-project', [AdvancedReportController::class, 'plByProject'])->name('reports.pl-by-project');
        Route::get('/balance-sheet-compare', [AdvancedReportController::class, 'bsCompare'])->name('reports.bs-compare');
        Route::get('/profit-by-item', [AdvancedReportController::class, 'profitByItem'])->name('reports.profit-by-item');
        Route::get('/payments-received', [AdvancedReportController::class, 'paymentsReceived'])->name('reports.payments-received');
        });
    });
});
