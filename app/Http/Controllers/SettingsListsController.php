<?php

namespace App\Http\Controllers;

use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;

/** The small lists under Settings: tax rates, payment methods, entry types and tags. */
class SettingsListsController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    public function taxRates()
    {
        return view('settings.tax-rates', ['rates' => $this->settings->taxRates(), 'locked' => $this->settings->isLocked()]);
    }

    public function saveTaxRate(Request $request, ?int $id = null)
    {
        $this->settings->saveTaxRate($id, ['name' => $request->input('name'), 'rate' => $request->input('rate'), 'on_sales' => $request->boolean('on_sales'),
            'on_purchases' => $request->boolean('on_purchases'), 'is_default' => $request->boolean('is_default')]);

        return redirect()->route('settings.tax-rates')->with('message', $id ? 'Tax rate saved.' : 'Tax rate added.');
    }

    public function deleteTaxRate(int $id)
    {
        $this->settings->deleteTaxRate($id);

        return redirect()->route('settings.tax-rates')->with('message', 'Tax rate deleted. Documents that used it keep their tax.');
    }

    public function paymentMethods()
    {
        return view('settings.payment-methods', ['methods' => $this->settings->paymentMethods(), 'locked' => $this->settings->isLocked()]);
    }

    public function savePaymentMethod(Request $request, ?int $id = null)
    {
        $this->settings->savePaymentMethod($id, (string) $request->input('name'));

        return redirect()->route('settings.payment-methods')->with('message', $id ? 'Payment method renamed.' : 'Payment method added.');
    }

    public function deletePaymentMethod(int $id)
    {
        $this->settings->deletePaymentMethod($id);

        return redirect()->route('settings.payment-methods')->with('message', 'Payment method deleted. Payments already recorded keep the name they were saved with.');
    }

    public function entryTypes()
    {
        return view('settings.entry-types', ['types' => $this->settings->entryTypes(), 'locked' => $this->settings->isLocked(), 'system' => SettingsService::SYSTEM_TYPES,
            'numbering' => SettingsService::NUMBERING, 'restrictions' => SettingsService::RESTRICTIONS]);
    }

    public function saveEntryType(Request $request, ?int $id = null)
    {
        $this->settings->saveEntryType($id, $request->only(['label', 'name', 'description', 'numbering', 'prefix', 'suffix', 'zero_padding', 'restriction_bankcash']));

        return redirect()->route('settings.entry-types')->with('message', $id ? 'Entry type saved.' : 'Entry type added.');
    }

    public function deleteEntryType(int $id)
    {
        $this->settings->deleteEntryType($id);

        return redirect()->route('settings.entry-types')->with('message', 'Entry type deleted.');
    }

    public function tags()
    {
        return view('settings.tags', ['tags' => $this->settings->tags(), 'locked' => $this->settings->isLocked(), 'colours' => SettingsService::TAG_COLOURS]);
    }

    public function saveTag(Request $request, ?int $id = null)
    {
        $this->settings->saveTag($id, (string) $request->input('title'), (string) $request->input('colour'));

        return redirect()->route('settings.tags')->with('message', $id ? 'Tag saved.' : 'Tag added.');
    }

    public function deleteTag(int $id)
    {
        $this->settings->deleteTag($id);

        return redirect()->route('settings.tags')->with('message', 'Tag deleted.');
    }
}
