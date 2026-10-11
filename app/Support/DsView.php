<?php

namespace App\Support;

use App\Services\DataSharing\DataSharingService;
use Illuminate\Support\HtmlString;

/** Small formatting helpers for the Data Sharing pages (amounts, dates, status pills, WhatsApp messages). Ported from the legacy views/datasharing/_helpers.php. */
class DsView
{
    public const STATUS = ['paid' => 'Paid', 'unpaid' => 'Unpaid', 'overdue' => 'Overdue'];

    /** 1,234.50 with the company's currency symbol; negatives as -$12.00. */
    public static function m($v): string
    {
        return Money::format(number_format(round((float) $v, 2), 2, '.', ''));
    }

    public static function d($date): string
    {
        return $date ? Money::date($date) : '';
    }

    public static function daysTo(string $date): int
    {
        return (int) round((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);
    }

    public static function gbf($v): string
    {
        $v = round((float) $v, 2);

        return floor($v) == $v ? number_format($v, 0) : rtrim(rtrim(number_format($v, 2), '0'), '.');
    }

    public static function ordinal(int $n): string
    {
        return $n.(in_array($n % 100, [11, 12, 13], true) ? 'th' : [1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th');
    }

    /** Status pill for a client row (cycleData()['all'] shape). */
    public static function pill(array $x): HtmlString
    {
        $n = self::daysTo($x['next']);
        $e = fn ($s) => e((string) $s);
        if ($x['status'] !== 'paid' && ! empty($x['credit']) && $x['credit'] > 0) {
            return new HtmlString('<span class="ds-pill '.($x['status'] === 'overdue' ? 'bad' : 'warn').'">Part paid &middot; owes '.$e(self::m($x['owed'])).($x['status'] === 'overdue' ? ' &middot; '.(-$n).'d late' : ' &middot; due '.$e(self::d($x['next']))).'</span>');
        }
        if ($x['status'] === 'overdue') {
            return new HtmlString('<span class="ds-pill bad">'.(-$n).' day'.($n === -1 ? '' : 's').' overdue</span>');
        }
        if ($x['status'] === 'paid') {
            return new HtmlString('<span class="ds-pill good">Paid &middot; next '.$e(self::d($x['next'])).'</span>');
        }
        if ($n === 0) {
            return new HtmlString('<span class="ds-pill warn">Due today</span>');
        }
        if ($n <= 3) {
            return new HtmlString('<span class="ds-pill warn">Due '.$e(self::d($x['next'])).'</span>');
        }

        return new HtmlString('<span class="ds-pill">Unpaid &middot; due '.$e(self::d($x['next'])).'</span>');
    }

    public static function rcMark(array $x): HtmlString
    {
        return new HtmlString($x['rech_n'] ? '<span class="ds-rc" title="Recharged '.(int) $x['rech_n'].' times this cycle">&#8635;'.(int) $x['rech_n'].'</span>' : '');
    }

    public static function star(array $x): HtmlString
    {
        return new HtmlString(! empty($x['main']) ? '<span class="ds-star" title="Main contact - responsible for this SIM">&#9733;</span>' : '');
    }

    public static function usageTxt(array $x): HtmlString
    {
        if (! isset($x['used']) || $x['used'] === null) {
            return new HtmlString('No reading yet');
        }

        return new HtmlString(self::gbf($x['used']).' GB used &middot; '.self::gbf($x['left']).' GB left');
    }

    public static function usagePct(array $x): ?float
    {
        return isset($x['used']) && $x['used'] !== null ? min(100, round($x['used'] / max(1, $x['used_of'] ?? $x['gb']) * 100)) : null;
    }

    /** The WhatsApp number to use: the WhatsApp one, else the data number. */
    public static function wa(array $x): string
    {
        return (string) (! empty($x['whatsapp']) ? $x['whatsapp'] : ($x['phone'] ?? ''));
    }

    public static function waMsg(array $x, string $company): string
    {
        $n = self::daysTo($x['next']);
        $first = explode(' ', trim($x['name']))[0];

        return 'Salaam '.$first.",\nYour ".$x['gb'].' GB data '.($n < 0 ? 'was due on '.self::d($x['next']) : ($n === 0 ? 'is due today' : 'is due on '.self::d($x['next']))).'. Amount: '.self::m($x['owed'] ?? $x['price'])
            .(! empty($x['credit']) && $x['credit'] > 0 ? ' ('.self::m($x['credit']).' already paid)' : '').".\nYou can pay by Bank, Shop2Shop, Kazang or Cash.\nThank you.\n".$company;
    }

    /** The clients on a shared SIM that are not paid yet. */
    public static function mcDue(array $s): array
    {
        return array_values(array_filter($s['clients'], fn ($x) => $x['status'] !== 'paid'));
    }

    /** One reminder for the SIM's main contact listing every unpaid slot, or null. */
    public static function mcMsg(array $s, string $company): ?string
    {
        $c = $s['contact'] ?? null;
        $due = self::mcDue($s);
        if (! $c || ! $due) {
            return null;
        }
        $first = explode(' ', trim($c['name']))[0];
        $lines = [];
        $tot = 0;
        foreach ($due as $x) {
            $ow = $x['owed'] ?? $x['price'];
            $n = self::daysTo($x['next']);
            $tot += (float) $ow;
            $lines[] = '- '.(! empty($x['main']) ? 'You ('.$x['name'].')' : $x['name']).': '.$x['gb'].' GB, '.self::m($ow).($n < 0 ? ', was due '.self::d($x['next']) : ($n === 0 ? ', due today' : ', due '.self::d($x['next'])));
        }

        return 'Salaam '.$first.",\nOn SIM ".$s['number'].' these slots are not paid yet:'."\n".implode("\n", $lines)."\nTotal: ".self::m($tot).".\nYou can pay by Bank, Shop2Shop, Kazang or Cash.\nThank you.\n".$company;
    }

    /** The smallest visible plan of the same type bigger than the client's. */
    public static function upgradeFor(array $x, string $kind): ?array
    {
        $best = null;
        foreach (app(DataSharingService::class)->plans(true) as $p) {
            if ((int) $p['full'] !== ($kind === 'full' ? 1 : 0) || (int) $p['gb'] <= (int) $x['gb']) {
                continue;
            }
            if (! $best || (int) $p['gb'] < (int) $best['gb']) {
                $best = $p;
            }
        }

        return $best;
    }

    public static function upMsg(array $x, array $p, string $company): string
    {
        $first = explode(' ', trim($x['name']))[0];

        return 'Salaam '.$first.",\nThis month you recharged ".(int) $x['rech_n'].' times and paid '.self::m($x['income']).' (plan and recharges). The '.app(DataSharingService::class)->planLabel($p).' plan is only '.self::m($p['price'])
            ." a month and gives you more data.\nShall we move you to it from your next payment?\nThank you.\n".$company;
    }

    /** A button that opens the WhatsApp window (message and number are checked by the person before anything goes). */
    public static function waButton(string $msg, string $name, string $phone, string $label = 'Reminder', string $title = 'WhatsApp reminder'): HtmlString
    {
        return new HtmlString('<button type="button" class="ds-btn ds-btn--sm ds-btn--good" data-ds-wa="'.e($msg).'" data-name="'.e($name).'" data-phone="'.e($phone).'" data-title="'.e($title).'">&#9742; '.e($label).'</button>');
    }

    public static function payButton(array $x, string $label = 'Record payment'): HtmlString
    {
        $due = ($x['owed'] ?? 0) > 0 ? $x['owed'] : $x['price'];

        return new HtmlString('<button type="button" class="ds-btn ds-btn--sm ds-btn--pri" data-ds-pay="'.(int) $x['id'].'" data-name="'.e($x['name']).'" data-info="'.e($x['gb'].' GB on '.$x['sim'].' - due '.self::d($x['next'])).'" data-price="'
            .number_format((float) $due, 2, '.', '').'" data-plan="'.number_format((float) $x['price'], 2, '.', '').'" data-credit="'.number_format((float) ($x['credit'] ?? 0), 2, '.', '').'">'.e($label).'</button>');
    }

    public static function methodOptions(string $sel = 'Cash'): HtmlString
    {
        $o = '';
        foreach (DataSharingService::METHODS as $mm) {
            $o .= '<option'.($mm === $sel ? ' selected' : '').'>'.$mm.'</option>';
        }

        return new HtmlString($o);
    }
}
