<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesPurchaseDocuments;
use App\Services\Accounting\ActivityLog;
use App\Services\Purchases\PurchasesService;
use App\Services\Sales\SalesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A purchase order never posts to the books; it converts into a draft bill. */
class PurchaseOrderController extends Controller
{
    use HandlesPurchaseDocuments;

    public function __construct(protected PurchasesService $purchases, protected SalesService $sales) {}

    public function index()
    {
        $rows = DB::connection('tenant')->table('purchase_orders')->join('vendors', 'vendors.id', '=', 'purchase_orders.vendor_id')
            ->orderByDesc('purchase_orders.date')->orderByDesc('purchase_orders.id')->get(['purchase_orders.*', 'vendors.name as vendor_name']);

        return view('purchases.orders', ['rows' => $rows, 'purchases' => $this->purchases]);
    }

    public function create(Request $request, ?int $vendor = null)
    {
        return view('purchases.doc-form', $this->billFormData($this->purchases, $this->sales, 'po', null, $vendor ?? ((int) $request->query('vendor') ?: null)));
    }

    public function store(Request $request)
    {
        $d = $this->validateBillDoc($request, 'purchase_orders', null, $this->sales);
        $id = $this->purchases->saveBill($d, $this->collectBillLines($request, $this->sales, false), null, 'purchase_orders');
        ActivityLog::add("Created Purchase Order #$id");

        return redirect()->route('purchase-orders.show', $id)->with('message', 'Purchase order saved.');
    }

    public function show(int $id)
    {
        $po = $this->purchases->purchaseOrder($id);
        abort_unless($po, 404);

        return view('purchases.order-show', ['po' => $po, 'purchases' => $this->purchases]);
    }

    public function edit(int $id)
    {
        $po = $this->purchases->purchaseOrder($id);
        abort_unless($po, 404);
        if ($po->status === 'converted') {
            return redirect()->route('purchase-orders.show', $id)->with('error', 'This purchase order has been converted to a bill and can no longer be edited.');
        }

        return view('purchases.doc-form', $this->billFormData($this->purchases, $this->sales, 'po', $po, null));
    }

    public function update(Request $request, int $id)
    {
        $po = $this->purchases->purchaseOrder($id);
        abort_unless($po && $po->status !== 'converted', 404);
        $d = $this->validateBillDoc($request, 'purchase_orders', $id, $this->sales);
        $this->purchases->saveBill($d, $this->collectBillLines($request, $this->sales, false), $id, 'purchase_orders');
        ActivityLog::add("Updated Purchase Order #$id");

        return redirect()->route('purchase-orders.show', $id)->with('message', 'Purchase order updated.');
    }

    public function destroy(int $id)
    {
        $this->purchases->deletePurchaseOrder($id);
        ActivityLog::add("Deleted Purchase Order #$id");

        return redirect()->route('purchase-orders.index')->with('message', 'Purchase order deleted.');
    }

    public function convert(int $id)
    {
        $billId = $this->purchases->convertPurchaseOrder($id);

        return redirect()->route('bills.show', $billId)->with('message', 'Purchase order converted to a draft bill.');
    }
}
