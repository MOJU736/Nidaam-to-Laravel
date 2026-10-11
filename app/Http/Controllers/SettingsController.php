<?php

namespace App\Http\Controllers;

use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;

/** Settings home, Company Settings, Close Books and Lock Company. Who may open each page is decided by the group's account_settings ticks (see Permissions). */
class SettingsController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    public function index()
    {
        return view('settings.index', ['s' => $this->settings->settings(), 'taxRates' => $this->settings->taxRates()->count()]);
    }

    public function company()
    {
        $s = $this->settings->settings();

        return view('settings.company', ['s' => $s, 'locked' => (bool) $s->account_locked, 'end' => SettingsService::yearEnd(substr((string) $s->getRawOriginal('fy_start'), 0, 10)),
            'numberFormats' => SettingsService::NUMBER_FORMATS, 'dateFormats' => SettingsService::DATE_FORMATS]);
    }

    public function updateCompany(Request $request)
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255'],
            'currency_symbol' => ['required', 'string', 'max:10'], 'currency_format' => ['required', 'string'], 'decimal_places' => ['required', 'integer', 'min:0', 'max:2'],
            'date_format' => ['required', 'string'], 'fy_start' => ['required', 'date_format:Y-m-d'],
        ]);
        $this->settings->saveCompany($d + ['vehicles_enabled' => $request->boolean('vehicles_enabled'), 'commissions_enabled' => $request->boolean('commissions_enabled'),
            'billable_expenses_enabled' => $request->boolean('billable_expenses_enabled'), 'construction_enabled' => $request->boolean('construction_enabled')]);

        return redirect()->route('settings.company')->with('message', 'Company settings saved.');
    }

    public function closeBooks()
    {
        $s = $this->settings->settings();

        return view('settings.close-books', ['lock' => $s->lock_date?->toDateString()]);
    }

    public function updateCloseBooks(Request $request)
    {
        if ($request->boolean('reopen')) {
            $this->settings->closeBooks(null);

            return redirect()->route('settings.close-books')->with('message', 'Books reopened - every date can be changed again.');
        }
        $d = $request->validate(['lock_date' => ['required', 'date_format:Y-m-d']]);
        $this->settings->closeBooks($d['lock_date']);

        return redirect()->route('settings.close-books')->with('message', 'Books closed through '.\App\Support\Money::date($d['lock_date']).'.');
    }

    public function lock()
    {
        return view('settings.lock', ['locked' => $this->settings->isLocked()]);
    }

    public function updateLock(Request $request)
    {
        $lock = $request->boolean('locked');
        $this->settings->lockCompany($lock);

        return redirect()->route('settings.lock')->with('message', $lock ? 'The company is locked. Nothing can be changed until it is unlocked.' : 'The company is unlocked.');
    }
}
