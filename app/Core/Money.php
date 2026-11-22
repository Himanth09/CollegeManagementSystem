<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Exact money arithmetic using integer paise, so totals never suffer from
 * floating-point rounding. Values are exchanged as "1234.50" strings.
 */
final class Money
{
    /** "1,234.5" | 1234.5 | "1234" -> integer paise */
    public static function toPaise(string|int|float|null $v): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        $s = str_replace([',', ' '], '', (string) $v);
        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '-+');
        if (!preg_match('/^\d*(\.\d*)?$/', $s)) {
            return 0;
        }
        [$int, $frac] = array_pad(explode('.', $s, 2), 2, '');
        $frac = substr(str_pad($frac, 3, '0'), 0, 3);
        $paise = ((int) ($int === '' ? '0' : $int)) * 100 + intdiv((int) $frac, 10);
        if ((int) $frac % 10 >= 5) {
            $paise++; // round half up on the 3rd decimal
        }
        return $neg ? -$paise : $paise;
    }

    public static function fromPaise(int $paise): string
    {
        $sign = $paise < 0 ? '-' : '';
        $paise = abs($paise);
        return $sign . intdiv($paise, 100) . '.' . str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function normalize(string|int|float|null $v): string
    {
        return self::fromPaise(self::toPaise($v));
    }

    public static function sum(string|int|float|null ...$values): string
    {
        $t = 0;
        foreach ($values as $v) {
            $t += self::toPaise($v);
        }
        return self::fromPaise($t);
    }

    public static function sub(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::fromPaise(self::toPaise($a) - self::toPaise($b));
    }

    public static function cmp(string|int|float|null $a, string|int|float|null $b): int
    {
        return self::toPaise($a) <=> self::toPaise($b);
    }

    /** Indian-style grouping: 12,34,567.00 */
    public static function format(string|int|float|null $v, bool $symbol = true): string
    {
        $p = self::toPaise($v);
        $neg = $p < 0;
        $p = abs($p);
        $int = (string) intdiv($p, 100);
        $dec = str_pad((string) ($p % 100), 2, '0', STR_PAD_LEFT);
        if (strlen($int) > 3) {
            $last3 = substr($int, -3);
            $rest = substr($int, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $int = $rest . ',' . $last3;
        }
        $sym = $symbol ? (string) Config::get('app.currency_symbol', '₹') : '';
        return ($neg ? '-' : '') . $sym . $int . '.' . $dec;
    }

    /** Amount in words (Indian numbering) for receipts and salary slips. */
    public static function inWords(string|int|float|null $v): string
    {
        $p = abs(self::toPaise($v));
        $rupees = intdiv($p, 100);
        $paise = $p % 100;
        $words = $rupees === 0 ? 'Zero' : self::numberToWords($rupees);
        $out = 'Rupees ' . $words;
        if ($paise > 0) {
            $out .= ' and ' . self::numberToWords($paise) . ' Paise';
        }
        return $out . ' Only';
    }

    private static function numberToWords(int $n): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
            'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $two = static function (int $x) use ($ones, $tens): string {
            if ($x < 20) {
                return $ones[$x];
            }
            return trim($tens[intdiv($x, 10)] . ' ' . $ones[$x % 10]);
        };
        $three = static function (int $x) use ($ones, $two): string {
            $h = intdiv($x, 100);
            $r = $x % 100;
            return trim(($h ? $ones[$h] . ' Hundred' : '') . ($r ? ' ' . $two($r) : ''));
        };
        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$div, $name]) {
            if ($n >= $div) {
                $q = intdiv($n, $div);
                $parts[] = ($q >= 100 ? self::numberToWords($q) : $two($q)) . ' ' . $name;
                $n %= $div;
            }
        }
        if ($n > 0) {
            $parts[] = $three($n);
        }
        return implode(' ', $parts);
    }
}
