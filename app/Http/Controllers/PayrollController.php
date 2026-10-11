<?php

namespace App\Http\Controllers;

use App\Services\Documents\DocumentService;
use App\Services\Documents\EmailService;
use App\Services\Documents\PdfService;
use App\Services\Payroll\PayrollService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Payroll: employees, monthly pay runs, paying out, salary advances, payslips and reports. The rules live in PayrollService. */
class PayrollController extends Controller
{
    public function __construct(protected PayrollService $pay)
    {
        $this->pay->ensureTables();
    }

    protected function runOrFail(int $id): object
    {
        return $this->pay->run($id) ?? abort(redirect()->route('payroll.index')->with('error', 'That pay run no longer exists.'));
    }

    protected function employeeOrFail(int $id): object
    {
        return $this->pay->employee($id) ?? abort(redirect()->route('payroll.employees')->with('error', 'That employee no longer exists.'));
    }

    // ---- home ---------------------------------------------------------------------------

    public function index()
    {
        return view('payroll.index', ['f' => $this->pay->homeFigures(), 'pay' => $this->pay]);
    }

    // ---- employees ----------------------------------------------------------------------

    public function employees(Request $request)
    {
        $employees = $this->pay->employees();
        $edit = $request->query('edit') ? $this->pay->employee((int) $request->query('edit')) : null;
        $owes = [];
        foreach ($employees as $e) {
            $owes[$e->id] = $this->pay->openBalance($e->id);
        }

        return view('payroll.employees', ['employees' => $employees, 'edit' => $edit, 'owes' => $owes, 'departments' => $this->pay->departments(), 'projects' => $this->pay->projectOptions(), 'methods' => PayrollService::METHODS]);
    }

    public function employeeStore(Request $request)
    {
        $this->pay->saveEmployee($request->all());

        return redirect()->route('payroll.employees')->with('message', 'Employee added.');
    }

    public function employeeUpdate(Request $request, int $id)
    {
        $this->employeeOrFail($id);
        $this->pay->saveEmployee($request->all(), $id);

        return redirect()->route('payroll.employees.show', $id)->with('message', 'Employee saved.');
    }

    public function employeeShow(int $id)
    {
        $e = $this->employeeOrFail($id);

        return view('payroll.employee', ['e' => $e, 'history' => $this->pay->history($id), 'advances' => $this->pay->advances($id), 'owes' => $this->pay->openBalance($id), 'pay' => $this->pay,
            'projects' => $this->pay->projectOptions()]);
    }

    // ---- advances and loans ---------------------------------------------------------------

    public function advances(Request $request)
    {
        return view('payroll.advances', ['advances' => $this->pay->advances(), 'employees' => $this->pay->employees(true)->pluck('name', 'id')->all(), 'banks' => $this->pay->bankOptions(),
            'pick' => (int) $request->query('employee'), 'pay' => $this->pay]);
    }

    public function advanceStore(Request $request)
    {
        $this->pay->addAdvance($request->all());

        return redirect()->route('payroll.advances')->with('message', 'Saved. It will be taken back in the next pay runs.');
    }

    public function advanceVoid(Request $request, int $id)
    {
        $this->pay->voidAdvance($id, $request->input('void_reason'));

        return redirect()->route('payroll.advances')->with('message', 'Voided - its entry was removed.');
    }

    // ---- pay runs -------------------------------------------------------------------------

    public function runStore(Request $request)
    {
        $id = $this->pay->newRun((string) $request->input('period'), (string) $request->input('date'));

        return redirect()->route('payroll.runs.show', $id)->with('message', 'Draft made with every active employee. Fill in allowances, overtime, bonus and absent days, then Approve.');
    }

    public function runShow(int $id)
    {
        $run = $this->runOrFail($id);
        $banks = $this->pay->bankOptions();
        $suggest = [];
        $owes = [];
        foreach ($run->lines as $l) {
            $suggest[$l->id] = $this->pay->accountFor($l->method, $banks);
            if ($run->status === 'draft') {
                $owes[$l->id] = $this->pay->openBalance((int) $l->employee_id);
            }
        }

        return view('payroll.run', ['run' => $run, 'month' => $this->pay->monthLabel($run->period), 'banks' => $banks, 'suggest' => $suggest, 'owes' => $owes, 'methods' => PayrollService::METHODS]);
    }

    /** Save the draft; with the Approve button also post it. */
    public function runUpdate(Request $request, int $id)
    {
        $this->runOrFail($id);
        $this->pay->saveDraft($id, (array) $request->input('line'), $request->input('date') ?: null);
        if ($request->input('approve')) {
            $this->pay->approve($id);

            return redirect()->route('payroll.runs.show', $id)->with('message', 'Approved and posted: Salaries expense and Salaries payable. Now pay everyone, or one by one.');
        }

        return redirect()->route('payroll.runs.show', $id)->with('message', 'Draft saved.');
    }

    public function runApprove(int $id)
    {
        $this->runOrFail($id);
        $this->pay->approve($id);

        return redirect()->route('payroll.runs.show', $id)->with('message', 'Approved and posted.');
    }

    public function runPay(Request $request, int $id)
    {
        $this->runOrFail($id);
        $line = (int) $request->input('line_id');
        $this->pay->pay($id, $line ?: null, (string) $request->input('date'), $request->input('ledger_id'));

        return redirect()->route('payroll.runs.show', $id)->with('message', $line ? 'Paid.' : 'Everyone still unpaid was paid.');
    }

    public function paymentVoid(Request $request, int $id)
    {
        $runId = $this->pay->voidPayment($id, $request->input('void_reason'));

        return redirect()->route('payroll.runs.show', $runId)->with('message', 'Payment undone - its entry was removed.');
    }

    /** Only a draft can be deleted. */
    public function runDestroy(int $id)
    {
        $run = $this->runOrFail($id);
        if ($run->status !== 'draft') {
            return redirect()->route('payroll.runs.show', $id)->with('error', 'Only a draft can be deleted.');
        }
        $this->pay->voidRun($id);

        return redirect()->route('payroll.index')->with('message', 'Draft deleted.');
    }

    public function runVoid(Request $request, int $id)
    {
        $this->runOrFail($id);
        $was = $this->pay->voidRun($id, $request->input('void_reason'));
        if ($was === 'draft') {
            return redirect()->route('payroll.index')->with('message', 'Draft deleted.');
        }

        return redirect()->route('payroll.runs.show', $id)->with('message', 'Pay run voided - its entry was removed and repayments given back to the advances.');
    }

    // ---- payslips -------------------------------------------------------------------------

    protected function slipOrFail(int $lineId): array
    {
        return $this->pay->slip($lineId) ?? abort(redirect()->route('payroll.index')->with('error', 'That payslip no longer exists.'));
    }

    protected function slipPdf(array $d): string
    {
        $company = \App\Models\Tenant\Setting::first();

        return app(PdfService::class)->fromHtml(view('payroll.payslip-pdf', $d + ['pay' => $this->pay, 'company' => $company, 'methods' => PayrollService::METHODS])->render());
    }

    public function payslip(int $lineId)
    {
        $d = $this->slipOrFail($lineId);
        $company = \App\Models\Tenant\Setting::first();
        $l = $d['line'];
        $msg = "Hello {$l->name}, your payslip for {$d['month']} from ".($company->name ?? '').': gross pay '.Money::format($l->gross)
            .(Money::positive($l->repay) ? ', advance/loan taken back '.Money::format($l->repay) : '').', net pay '.Money::format($l->net).'.';
        $phone = preg_replace('/\D+/', '', (string) $l->phone);

        return view('payroll.payslip', $d + ['pay' => $this->pay, 'methods' => PayrollService::METHODS, 'whatsapp' => $phone !== '' ? 'https://wa.me/'.$phone.'?text='.rawurlencode($msg) : null,
            'company' => $company]);
    }

    public function payslipPdf(Request $request, int $lineId)
    {
        $d = $this->slipOrFail($lineId);

        return app(PdfService::class)->response($this->slipPdf($d), $this->pay->slipFile($d), (bool) $request->query('download'));
    }

    /** Emails the payslip PDF. Salaries are confidential, so the company's "always CC" address is never added. */
    public function payslipEmail(Request $request, int $lineId)
    {
        $d = $this->slipOrFail($lineId);
        $mail = app(EmailService::class);
        $to = trim((string) $d['line']->email);
        if ($to === '') {
            $to = trim((string) $request->input('email'));
            if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['payroll' => 'Enter a valid email address to send to.']);
            }
            if ($request->boolean('save_email')) {
                DB::connection('tenant')->table('pay_employees')->where('id', $d['line']->employee_id)->update(['email' => $to]);
            }
        }
        $company = (string) (\App\Models\Tenant\Setting::first()->name ?? '');
        $subject = "Payslip {$d['month']} - $company";
        $body = '<p>Hello '.e($d['line']->name).',</p><p>Please find attached your payslip for '.e($d['month']).' from '.e($company).'.</p><p>Thank you.</p>';
        $who = $mail->send([$to], [], [], $subject, $body, $this->slipPdf($d), $this->pay->slipFile($d));

        return redirect()->route('payroll.payslip', $lineId)->with('message', "Payslip emailed to $who.");
    }

    // ---- reports --------------------------------------------------------------------------

    public function reports(Request $request)
    {
        $isMonth = fn ($v) => is_string($v) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v);
        $from = $isMonth($request->query('from')) ? $request->query('from') : date('Y').'-01';
        $to = $isMonth($request->query('to')) ? $request->query('to') : date('Y-m');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $view = array_key_exists((string) $request->query('view'), PayrollService::VIEWS) ? $request->query('view') : 'summary';
        $r = $this->pay->report($view, $from, $to);

        $download = $request->query('download');
        if (in_array($download, ['csv', 'pdf'], true)) {
            $company = (string) (\App\Models\Tenant\Setting::first()->name ?? '');
            $sub = $view === 'advances' ? 'As of '.Money::date(now()) : $this->pay->monthLabel($from).' to '.$this->pay->monthLabel($to);
            $rows = [[$company], [$r['title']], [$sub], [], $r['head']];
            foreach ($r['rows'] as $row) {
                $rows[] = array_map(fn ($c, $i) => in_array($i, $r['money'], true) ? number_format((float) $c, 2, '.', '') : $c, $row['cells'], array_keys($row['cells']));
            }
            if ($r['rows']) {
                $tot = ['TOTAL'];
                for ($i = 1; $i < count($r['head']); $i++) {
                    $tot[] = isset($r['total'][$i]) ? number_format((float) $r['total'][$i], 2, '.', '') : '';
                }
                $rows[] = $tot;
            }
            $name = trim(preg_replace('/[^A-Za-z0-9 _-]/', '', $r['title'])).' '.$from.' to '.$to;
            if ($download === 'pdf') {
                $wide = count($r['head']) > 6;

                return app(PdfService::class)->response(app(DocumentService::class)->tablePdf($rows, $wide ? 'landscape' : 'portrait'), "$name.pdf", true);
            }

            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                foreach ($rows as $row) {
                    fputcsv($out, $row);
                }
                fclose($out);
            }, "$name.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('payroll.reports', ['r' => $r, 'view' => $view, 'from' => $from, 'to' => $to, 'views' => PayrollService::VIEWS]);
    }
}
