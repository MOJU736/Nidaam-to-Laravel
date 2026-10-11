<?php

namespace App\Support;

/** An amount written out, like a cheque: 1205.50 -> "One thousand two hundred five and 50/100". */
class NumberWords
{
    protected const ONES = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];

    protected const TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    protected static function below1000(int $n): string
    {
        $out = [];
        if ($n >= 100) {
            $out[] = self::ONES[intdiv($n, 100)].' hundred';
            $n %= 100;
        }
        if ($n >= 20) {
            $out[] = self::TENS[intdiv($n, 10)].($n % 10 ? '-'.self::ONES[$n % 10] : '');
        } elseif ($n > 0) {
            $out[] = self::ONES[$n];
        }

        return implode(' ', $out);
    }

    public static function whole(int $n): string
    {
        if ($n === 0) {
            return 'zero';
        }
        $parts = [];
        foreach ([1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand'] as $size => $word) {
            if ($n >= $size) {
                $parts[] = self::below1000(intdiv($n, $size)).' '.$word;
                $n %= $size;
            }
        }
        if ($n > 0) {
            $parts[] = self::below1000($n);
        }

        return implode(' ', $parts);
    }

    /** $amount is a decimal string such as "1205.50". */
    public static function money($amount): string
    {
        $amount = ltrim((string) $amount, '-');
        [$w, $c] = array_pad(explode('.', number_format((float) $amount, 2, '.', '')), 2, '00');

        return ucfirst(self::whole((int) $w)).' and '.$c.'/100';
    }
}
