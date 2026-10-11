<?php

namespace App\Support;

use App\Models\Tenant\Setting;

/** Exact decimal arithmetic (strings, never floats) and company-aware formatting. */
class Money
{
    const SCALE = 2;

    public static function add($a, $b): string
    {
        return bcadd((string) ($a ?? 0), (string) ($b ?? 0), self::SCALE);
    }

    public static function sub($a, $b): string
    {
        return bcsub((string) ($a ?? 0), (string) ($b ?? 0), self::SCALE);
    }

    public static function cmp($a, $b): int
    {
        return bccomp((string) ($a ?? 0), (string) ($b ?? 0), self::SCALE);
    }

    public static function isZero($a): bool
    {
        return self::cmp($a, 0) === 0;
    }

    public static function positive($a): bool
    {
        return self::cmp($a, 0) > 0;
    }

    public static function neg($a): string
    {
        return bcmul((string) $a, '-1', self::SCALE);
    }

    /** Round half away from zero to cents (bcmath alone truncates). Compute at extra precision first, then round. */
    public static function round($value): string
    {
        $v = (string) $value;
        $neg = str_starts_with($v, '-');
        $r = bcadd(ltrim($v, '-'), '0.005', self::SCALE);

        return $neg && bccomp($r, '0', self::SCALE) !== 0 ? '-'.$r : $r;
    }

    /** qty x price, rounded to cents. */
    public static function mul($a, $b): string
    {
        return self::round(bcmul((string) $a, (string) $b, 8));
    }

    /** $base x $percent / 100, rounded to cents. */
    public static function percentOf($base, $percent): string
    {
        return self::round(bcdiv(bcmul((string) $base, (string) $percent, 10), '100', 8));
    }

    /** Amount on a ledger's own normal side as a signed figure: owed to the business is positive. */
    public static function signed(array $balance, string $normalSide): string
    {
        return $balance['dc'] === $normalSide ? $balance['amount'] : self::neg($balance['amount']);
    }

    /** 1,234.50 with the company's currency symbol and decimals; negatives as -$1,234.50. */
    public static function format($amount): string
    {
        $s = self::settings();
        $places = (int) ($s['decimal_places'] ?? 2);
        $neg = self::cmp($amount, 0) < 0;
        $abs = ltrim(number_format(abs((float) $amount), $places, '.', ','), '-');

        return ($neg ? '-' : '').$s['symbol'].$abs;
    }

    /** Company date format (the legacy setting stores "d-M-Y|dd-M-yy|..." - the first part is PHP's). */
    public static function date($value): string
    {
        if (empty($value)) {
            return '';
        }

        return \Illuminate\Support\Carbon::parse($value)->format(self::settings()['date_format']);
    }

    protected static function settings(): array
    {
        static $cache = [];
        $key = config('database.connections.tenant.database');
        if (! isset($cache[$key])) {
            $row = Setting::query()->first();
            $cache[$key] = [
                'symbol' => trim((string) ($row->currency_symbol ?? '')) ?: '$',
                'decimal_places' => $row->decimal_places ?? 2,
                'date_format' => explode('|', (string) ($row->date_format ?? ''))[0] ?: 'd-M-Y',
                'vehicles' => $row === null || ! isset($row->vehicles_enabled) ? true : (bool) $row->vehicles_enabled,
                'billable' => (bool) ($row->billable_expenses_enabled ?? false),
            ];
        }

        return $cache[$key];
    }

    public static function vehiclesEnabled(): bool
    {
        return self::settings()['vehicles'];
    }

    public static function billableExpensesEnabled(): bool
    {
        return self::settings()['billable'];
    }
}
