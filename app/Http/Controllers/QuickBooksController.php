<?php

namespace App\Http\Controllers;

use App\Services\Settings\QuickBooksImport;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * Settings → Move from QuickBooks: upload an opening balances workbook, read the preview with every check, press Import.
 * Administrators only. The uploaded workbook waits in storage/app/qb_import between the preview and the Import press and is
 * deleted after the import or on Cancel; Import reads it again and re-runs every check - nothing from the preview page is trusted.
 */
class QuickBooksController extends Controller
{
    public function __construct(protected QuickBooksImport $qb) {}

    protected function dir(): string
    {
        $d = storage_path('app/qb_import');
        if (! is_dir($d)) {
            mkdir($d, 0775, true);
        }

        return $d;
    }

    protected function path(string $token): string
    {
        return $this->dir().DIRECTORY_SEPARATOR.preg_replace('/[^a-f0-9]/', '', $token).'.xlsx';
    }

    protected function forget(Request $request): void
    {
        $held = $request->session()->pull('qb_import');
        if (! empty($held['token']) && is_file($p = $this->path($held['token']))) {
            @unlink($p);
        }
    }

    public function index(Request $request)
    {
        $done = $this->qb->done();
        $plan = null;
        $fileName = null;

        if ($request->isMethod('post') && ! $done) {
            $request->validate(['workbook' => ['required', 'file', 'max:20480']], ['workbook.required' => 'Choose the workbook (.xlsx) first.']);
            $file = $request->file('workbook');
            if (strtolower((string) $file->getClientOriginalExtension()) !== 'xlsx') {
                return back()->with('error', 'The file must be an Excel workbook ending in .xlsx.');
            }
            $this->forget($request);
            $token = bin2hex(random_bytes(16));
            File::copy($file->getRealPath(), $this->path($token));
            $request->session()->put('qb_import', ['token' => $token, 'name' => mb_substr($file->getClientOriginalName(), 0, 120), 'account' => app(TenantManager::class)->current()->id]);
            $read = $this->qb->read($this->path($token));
            if ($read !== true) {
                $this->forget($request);

                return back()->with('error', $read);
            }
            $plan = $this->qb->plan();
            $fileName = $request->session()->get('qb_import.name');
        }

        return view('settings.quickbooks', ['done' => $done, 'existing' => $done ? [] : $this->qb->existingCounts(), 'plan' => $plan, 'fileName' => $fileName]);
    }

    public function import(Request $request)
    {
        $held = $request->session()->get('qb_import');
        if (empty($held['token']) || (int) $held['account'] !== (int) app(TenantManager::class)->current()->id || ! is_file($this->path($held['token']))) {
            return redirect()->route('settings.quickbooks')->with('error', 'The workbook is no longer waiting - upload it again.');
        }
        $read = $this->qb->read($this->path($held['token']));
        $plan = $read === true ? $this->qb->plan() : ['errors' => [$read]];
        if (! empty($plan['errors'])) {
            $this->forget($request);

            return redirect()->route('settings.quickbooks')->with('error', 'Nothing was brought in: '.$plan['errors'][0]);
        }
        @set_time_limit(300);
        $res = $this->qb->import($plan);
        $this->forget($request);
        if (empty($res['ok'])) {
            return redirect()->route('settings.quickbooks')->with('error', $res['error']);
        }

        return redirect()->route('settings.quickbooks')->with('message', 'The QuickBooks balances are in.');
    }

    public function cancel(Request $request)
    {
        $this->forget($request);

        return redirect()->route('settings.quickbooks');
    }
}
