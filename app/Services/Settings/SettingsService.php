<?php

namespace App\Services\Settings;

use App\Models\Tenant\Setting;
use App\Services\Accounting\ActivityLog;
use App\Support\Money;
use App\Support\Tables;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The company's own settings (legacy Account_settings controller): company details, financial year, closing / locking the books,
 * tax rates, payment methods, entry types and tags. Everything is read and written on the company (tenant) database.
 */
class SettingsService
{
    public const NUMBER_FORMATS = ['##,###.##' => '1,234,567.89', '##,##.##' => '12,34,567.89 (Indian grouping)', '###,###.##' => '1,234,567.89 (groups of three)'];

    public const DATE_FORMATS = ['d-M-Y|dd-M-yy|dd-MMM-yyyy' => 'day first', 'M-d-Y|M-dd-yy|MMM-dd-yyyy' => 'month first', 'Y-M-d|yy-M-dd|yyyy-MMM-dd' => 'year first'];

    /** Entry types the app posts to by name: they cannot be renamed or deleted. */
    public const SYSTEM_TYPES = ['receipt', 'payment', 'contra', 'journal', 'sales_invoice', 'purchase_bill'];

    public const NUMBERING = [1 => 'Auto', 2 => 'Manual (required)', 3 => 'Manual (optional)'];

    public const RESTRICTIONS = [
        1 => 'Unrestricted', 2 => 'At least one Bank or Cash account must be on the Debit side', 3 => 'At least one Bank or Cash account must be on the Credit side',
        4 => 'Only Bank or Cash accounts on both the Debit and Credit side', 5 => 'Only NON Bank or Cash accounts on both the Debit and Credit side',
    ];

    /** Tag colours: background => text colour. */
    public const TAG_COLOURS = ['E8E6F8' => '3C3489', 'E5F6EE' => '146C43', 'FDEAEA' => 'A12C2C', 'FBF1DE' => '8A5A0B', 'E3F1FB' => '1B5E8C', 'F3E8FB' => '6B2F8C', 'EDEFF2' => '3D4752', 'FFE9DC' => '9A3F0E'];

    protected function db()
    {
        return DB::connection('tenant');
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['settings' => $message]);
    }

    public function settings(): Setting
    {
        return Setting::first() ?? $this->fail('This company has no settings row.');
    }

    public function isLocked(): bool
    {
        return (bool) Setting::first()?->account_locked;
    }

    /** Edits to the company's details and lists are refused while the whole company is locked (locking and closing books stay possible). */
    protected function guardUnlocked(): void
    {
        if ($this->isLocked()) {
            $this->fail('This company is locked, so nothing can be changed. Unlock it under Settings → Lock company first.');
        }
    }

    /** True for a real calendar date written Y-m-d (2027-02-30 and 2026-13-45 are not). */
    public static function isDate(?string $v): bool
    {
        $d = $v ? \DateTime::createFromFormat('!Y-m-d', $v) : false;

        return $d !== false && $d->format('Y-m-d') === $v;
    }

    /** The last day of a financial year that starts on $start: twelve months on, less a day. */
    public static function yearEnd(string $start): string
    {
        return Carbon::parse($start)->addYear()->subDay()->toDateString();
    }

    // ---- company details --------------------------------------------------

    /** @param array $d name, address, email, currency_symbol, currency_format, decimal_places, date_format, fy_start, vehicles_enabled, commissions_enabled, billable_expenses_enabled */
    public function saveCompany(array $d): void
    {
        $this->guardUnlocked();
        $s = $this->settings();
        $start = (string) $d['fy_start'];
        if (! self::isDate($start)) {
            $this->fail('Enter a real date for the start of the financial year.');
        }
        if (! isset(self::NUMBER_FORMATS[$d['currency_format']])) {
            $this->fail('Choose a number format from the list.');
        }
        if (! isset(self::DATE_FORMATS[$d['date_format']])) {
            $this->fail('Choose a date format from the list.');
        }
        $places = (int) $d['decimal_places'];
        if ($places < 0 || $places > 2) {
            $this->fail('Decimal places must be 0, 1 or 2.');
        }
        $end = self::yearEnd($start);
        $oldYear = substr((string) $s->getRawOriginal('fy_start'), 0, 10).' - '.substr((string) $s->getRawOriginal('fy_end'), 0, 10);

        $this->db()->table('settings')->update([
            'name' => trim($d['name']), 'address' => trim((string) ($d['address'] ?? '')), 'email' => trim($d['email']),
            'currency_symbol' => trim($d['currency_symbol']), 'currency_format' => $d['currency_format'], 'decimal_places' => $places, 'date_format' => $d['date_format'],
            'fy_start' => $start, 'fy_end' => $end,
            'vehicles_enabled' => ! empty($d['vehicles_enabled']) ? 1 : 0, 'commissions_enabled' => ! empty($d['commissions_enabled']) ? 1 : 0,
            'billable_expenses_enabled' => ! empty($d['billable_expenses_enabled']) ? 1 : 0,
        ] + (Tables::has('settings', 'construction_enabled') && array_key_exists('construction_enabled', $d) ? ['construction_enabled' => ! empty($d['construction_enabled']) ? 1 : 0] : []));
        // the "Choose company" page reads the year from the master list
        if ($account = app(TenantManager::class)->current()) {
            DB::connection('master')->table('accounts')->where('id', $account->id)->update(['fy_start' => $start, 'fy_end' => $end]);
        }
        Money::forget(); // amounts and dates are formatted from these settings
        if ($oldYear !== "$start - $end") {
            ActivityLog::add("Changed the financial year from $oldYear to $start - $end");
        }
        ActivityLog::add('Updated company settings');
    }

    // ---- close / lock the books -------------------------------------------

    /** Nothing dated on or before $date can be added, changed, voided or deleted. Null reopens the books. */
    public function closeBooks(?string $date): void
    {
        if ($date !== null) {
            if (! self::isDate($date)) {
                $this->fail('Enter a valid date.');
            }
            if ($date > now()->toDateString()) {
                $this->fail('The books can only be closed up to today, not a future date.');
            }
        }
        $previous = $this->settings()->getRawOriginal('lock_date');
        $previous = $previous ? substr((string) $previous, 0, 10) : null;
        $this->db()->table('settings')->update(['lock_date' => $date]);
        Money::forget();
        ActivityLog::add($date ? "Closed the books through $date".($previous ? " (was $previous)" : '') : 'Reopened the books (was closed through '.($previous ?? 'nothing').')');
    }

    public function lockCompany(bool $locked): void
    {
        $this->db()->table('settings')->update(['account_locked' => $locked ? 1 : 0]);
        ActivityLog::add($locked ? 'Locked the company: no changes can be made until it is unlocked' : 'Unlocked the company');
    }

    // ---- tax rates --------------------------------------------------------

    public function taxRates()
    {
        return $this->db()->table('tax_rates')->orderBy('rate')->orderBy('name')->get();
    }

    /** "5", "7.5", "0": how a rate is written. */
    public static function pct($v): string
    {
        $s = rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');

        return $s === '' ? '0' : $s;
    }

    public function saveTaxRate(?int $id, array $d): void
    {
        $this->guardUnlocked();
        $name = trim((string) ($d['name'] ?? ''));
        $rate = str_replace(['%', ' ', ','], ['', '', '.'], (string) ($d['rate'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $this->fail('Give the rate a name, e.g. "VAT".');
        }
        if ($rate === '' || ! is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
            $this->fail('The rate must be a % between 0 and 100.');
        }
        if (empty($d['on_sales']) && empty($d['on_purchases'])) {
            $this->fail('Choose where the rate is used: sales, purchases or both.');
        }
        if ($id !== null && ! $this->db()->table('tax_rates')->where('id', $id)->exists()) {
            $this->fail('That tax rate no longer exists.');
        }
        $same = $this->db()->table('tax_rates')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->where('rate', (float) $rate)->when($id, fn ($q) => $q->where('id', '!=', $id));
        if ($same->exists()) {
            $this->fail('There is already a rate "'.$name.'" at '.self::pct($rate).'%.');
        }
        $row = ['name' => $name, 'rate' => (float) $rate, 'on_sales' => empty($d['on_sales']) ? 0 : 1, 'on_purchases' => empty($d['on_purchases']) ? 0 : 1, 'is_default' => empty($d['is_default']) ? 0 : 1];
        $this->db()->transaction(function () use ($row, $id) {
            if ($row['is_default']) {
                $this->db()->table('tax_rates')->update(['is_default' => 0]);
            }
            $id ? $this->db()->table('tax_rates')->where('id', $id)->update($row) : $this->db()->table('tax_rates')->insert($row);
        });
        ActivityLog::add(($id ? 'Changed' : 'Added').' tax rate '.$name.' '.self::pct($rate).'%');
    }

    public function deleteTaxRate(int $id): void
    {
        $this->guardUnlocked();
        $r = $this->db()->table('tax_rates')->where('id', $id)->first();
        if (! $r) {
            return;
        }
        $this->db()->table('tax_rates')->where('id', $id)->delete();
        ActivityLog::add('Deleted tax rate '.$r->name.' '.self::pct($r->rate).'%');
    }

    // ---- payment methods --------------------------------------------------

    public function paymentMethods()
    {
        return $this->db()->table('payment_methods')->orderBy('name')->get();
    }

    public function savePaymentMethod(?int $id, string $name): void
    {
        $this->guardUnlocked();
        $name = trim($name);
        if ($name === '') {
            $this->fail('Enter a method name.');
        }
        if (mb_strlen($name) > 50) {
            $this->fail('A method name can be at most 50 characters.');
        }
        if ($id !== null && ! $this->db()->table('payment_methods')->where('id', $id)->exists()) {
            $this->fail('That payment method no longer exists.');
        }
        if ($this->db()->table('payment_methods')->where('name', $name)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            $this->fail('A method named "'.$name.'" already exists.');
        }
        if ($id) {
            $this->db()->table('payment_methods')->where('id', $id)->update(['name' => $name]);
        } else {
            $this->db()->table('payment_methods')->insert(['name' => $name, 'created_at' => now()]);
        }
        ActivityLog::add(($id ? 'Renamed' : 'Added').' payment method '.$name);
    }

    public function deletePaymentMethod(int $id): void
    {
        $this->guardUnlocked();
        $m = $this->db()->table('payment_methods')->where('id', $id)->first();
        if (! $m) {
            return;
        }
        $this->db()->table('payment_methods')->where('id', $id)->delete();
        ActivityLog::add('Deleted payment method '.$m->name);
    }

    // ---- entry types ------------------------------------------------------

    public function entryTypes()
    {
        return $this->db()->table('entrytypes as t')->select('t.*')->selectSub(fn ($q) => $q->from('entries')->selectRaw('COUNT(*)')->whereColumn('entries.entrytype_id', 't.id'), 'uses')->orderBy('t.id')->get();
    }

    public function saveEntryType(?int $id, array $d): void
    {
        $this->guardUnlocked();
        $current = $id ? $this->db()->table('entrytypes')->where('id', $id)->first() : null;
        if ($id && ! $current) {
            $this->fail('That entry type no longer exists.');
        }
        $system = $current && in_array($current->label, self::SYSTEM_TYPES, true);
        $label = $system ? $current->label : strtolower(trim((string) ($d['label'] ?? '')));
        if (! preg_match('/^[a-z][a-z0-9_]{1,49}$/', $label)) {
            $this->fail('The label is a short code of lower-case letters, digits and underscores, starting with a letter (e.g. petty_cash).');
        }
        if ($this->db()->table('entrytypes')->where('label', $label)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            $this->fail('There is already an entry type with the label "'.$label.'".');
        }
        $name = trim((string) ($d['name'] ?? ''));
        $desc = trim((string) ($d['description'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255 || $desc === '' || mb_strlen($desc) > 255) {
            $this->fail('Give the entry type a name and a description (up to 255 characters each).');
        }
        $prefix = trim((string) ($d['prefix'] ?? ''));
        $suffix = trim((string) ($d['suffix'] ?? ''));
        if (mb_strlen($prefix) > 20 || mb_strlen($suffix) > 20) {
            $this->fail('The prefix and suffix can be at most 20 characters.');
        }
        $pad = (int) ($d['zero_padding'] ?? 0);
        if ($pad < 0 || $pad > 12) {
            $this->fail('Zero padding is the number of digits to pad the number to: 0 to 12.');
        }
        $numbering = (int) ($d['numbering'] ?? 1);
        $restriction = (int) ($d['restriction_bankcash'] ?? 1);
        if (! isset(self::NUMBERING[$numbering]) || ! isset(self::RESTRICTIONS[$restriction])) {
            $this->fail('Choose the numbering and the account restriction from the lists.');
        }
        $row = ['label' => $label, 'name' => $name, 'description' => $desc, 'numbering' => $numbering, 'prefix' => $prefix, 'suffix' => $suffix, 'zero_padding' => $pad, 'restriction_bankcash' => $restriction];
        if ($id) {
            $this->db()->table('entrytypes')->where('id', $id)->update($row);
        } else {
            $this->db()->table('entrytypes')->insert($row + ['base_type' => 1]);
        }
        ActivityLog::add(($id ? 'Changed' : 'Added').' entry type '.$name);
    }

    public function deleteEntryType(int $id): void
    {
        $this->guardUnlocked();
        $t = $this->db()->table('entrytypes')->where('id', $id)->first();
        if (! $t) {
            return;
        }
        if (in_array($t->label, self::SYSTEM_TYPES, true)) {
            $this->fail('"'.$t->name.'" is used by the app itself and cannot be deleted.');
        }
        if ($this->db()->table('entries')->where('entrytype_id', $id)->exists()) {
            $this->fail('"'.$t->name.'" has journal entries, so it cannot be deleted.');
        }
        $this->db()->table('entrytypes')->where('id', $id)->delete();
        ActivityLog::add('Deleted entry type '.$t->name);
    }

    // ---- tags ---------------------------------------------------------------

    public function tags()
    {
        return $this->db()->table('tags as t')->select('t.*')->selectSub(fn ($q) => $q->from('entries')->selectRaw('COUNT(*)')->whereColumn('entries.tag_id', 't.id'), 'uses')->orderBy('t.title')->get();
    }

    public function saveTag(?int $id, string $title, string $colour): void
    {
        $this->guardUnlocked();
        $title = trim($title);
        $bg = strtoupper($colour);
        if (! isset(self::TAG_COLOURS[$bg])) {
            $bg = 'E8E6F8';
        }
        if ($title === '' || mb_strlen($title) > 60) {
            $this->fail('Give the tag a name of up to 60 letters.');
        }
        if ($id !== null && ! $this->db()->table('tags')->where('id', $id)->exists()) {
            $this->fail('That tag no longer exists.');
        }
        if ($this->db()->table('tags')->whereRaw('LOWER(title) = ?', [mb_strtolower($title)])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            $this->fail('There is already a tag called "'.$title.'".');
        }
        $row = ['title' => $title, 'background' => $bg, 'color' => self::TAG_COLOURS[$bg]];
        $id ? $this->db()->table('tags')->where('id', $id)->update($row) : $this->db()->table('tags')->insert($row);
        ActivityLog::add(($id ? 'Changed' : 'Added').' tag '.$title);
    }

    public function deleteTag(int $id): void
    {
        $this->guardUnlocked();
        $t = $this->db()->table('tags')->where('id', $id)->first();
        if (! $t) {
            return;
        }
        $used = $this->db()->table('entries')->where('tag_id', $id)->count();
        if ($used) {
            $this->fail('This tag is on '.$used.' journal entr'.($used === 1 ? 'y' : 'ies').', so it can\'t be deleted. Rename it instead, or take it off those entries first.');
        }
        $this->db()->table('tags')->where('id', $id)->delete();
        ActivityLog::add('Deleted tag '.$t->title);
    }
}
