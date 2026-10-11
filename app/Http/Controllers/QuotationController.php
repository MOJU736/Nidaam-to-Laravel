<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesSalesDocuments;
use App\Services\Accounting\ActivityLog;
use App\Services\Sales\SalesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuotationController extends Controller
{
    use HandlesSalesDocuments;

    public function __construct(protected SalesService $sales) {}

    public function index(Request $request)
    {
        $rows = DB::connection('tenant')->table('quotations')->join('customers', 'customers.id', '=', 'quotations.customer_id')
            ->when($request->query('status') === 'draft', fn ($q) => $q->where('quotations.status', 'draft'))
            ->orderByDesc('quotations.date')->orderByDesc('quotations.id')->get(['quotations.*', 'customers.name as customer_name']);

        return view('sales.quotations', ['rows' => $rows, 'sales' => $this->sales, 'draftsOnly' => $request->query('status') === 'draft']);
    }

    public function create(Request $request, ?int $customer = null)
    {
        return view('sales.doc-form', $this->formData($this->sales, 'quotation', null, $customer ?? (int) $request->query('customer') ?: null));
    }

    public function store(Request $request)
    {
        $d = $this->validateDoc($request, 'quotations', null, $this->sales);
        $id = $this->sales->saveQuotation($d, $this->collectLines($request, $this->sales));
        ActivityLog::add("Created Quotation #$id");

        return redirect()->route('quotations.show', $id)->with('message', 'Quotation saved.');
    }

    public function show(int $id)
    {
        $q = $this->sales->quotation($id);
        abort_unless($q, 404);

        return view('sales.quotation-show', ['q' => $q, 'sales' => $this->sales, 'vehicles' => $this->vehicleLabels($q->vehicle_ids)]);
    }

    public function edit(int $id)
    {
        $q = $this->sales->quotation($id);
        abort_unless($q, 404);
        if ($q->status === 'converted') {
            return redirect()->route('quotations.show', $id)->with('error', 'This quotation has been converted to an invoice and can no longer be edited.');
        }

        return view('sales.doc-form', $this->formData($this->sales, 'quotation', $q, null));
    }

    public function update(Request $request, int $id)
    {
        $q = $this->sales->quotation($id);
        abort_unless($q, 404);
        if ($q->status === 'converted') {
            return redirect()->route('quotations.show', $id)->with('error', 'This quotation has been converted to an invoice and can no longer be edited.');
        }
        $d = $this->validateDoc($request, 'quotations', $id, $this->sales);
        $this->sales->saveQuotation($d, $this->collectLines($request, $this->sales), $id);
        ActivityLog::add("Updated Quotation #$id");

        return redirect()->route('quotations.show', $id)->with('message', 'Quotation updated.');
    }

    public function destroy(int $id)
    {
        $this->sales->deleteQuotation($id);
        ActivityLog::add("Deleted Quotation #$id");

        return redirect()->route('quotations.index')->with('message', 'Quotation deleted.');
    }

    public function convert(int $id)
    {
        $invoiceId = $this->sales->convertQuotation($id);
        ActivityLog::add("Converted Quotation #$id to Invoice #$invoiceId");

        return redirect()->route('invoices.show', $invoiceId)->with('message', 'Quotation converted to a draft invoice.');
    }

    protected function vehicleLabels(array $ids): array
    {
        return $ids ? \App\Models\Tenant\CustomerVehicle::whereIn('id', $ids)->get()->map(fn ($v) => trim(($v->model ?? '').' '.($v->plate_number ? '- '.$v->plate_number : '')))->all() : [];
    }
}
