<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Rupees, held as paise.
 *
 * Every money column in the database is an integer count of paise. A float
 * cannot hold 0.1 exactly, so a total assembled from floats drifts, and the
 * drift lands on an invoice that has to be defended to somebody. Integers
 * cannot drift, so the conversion happens once at each edge: here.
 */
final class Money
{
    private function __construct(public readonly int $paise) {}

    public static function paise(int $paise): self
    {
        return new self($paise);
    }

    /** Accepts what a form sends: '1,20,000', '1200.50', 1200, '' */
    public static function rupees(string|int|float|null $rupees): self
    {
        if ($rupees === null || $rupees === '') {
            return new self(0);
        }

        $cleaned = is_string($rupees) ? preg_replace('/[^0-9.\-]/', '', $rupees) : (string) $rupees;

        if ($cleaned === '' || ! is_numeric($cleaned)) {
            throw new InvalidArgumentException("Not an amount: {$rupees}");
        }

        // round() before casting, because (int) 1200.5 * 100 truncates and a
        // rupee and a half becomes a rupee.
        return new self((int) round((float) $cleaned * 100));
    }

    public static function toPaise(string|int|float|null $rupees): int
    {
        return self::rupees($rupees)->paise;
    }

    public function rupeeValue(): float
    {
        return $this->paise / 100;
    }

    /** '12,00,000.00' — the Indian grouping, which is not what number_format does. */
    public function amount(): string
    {
        $negative = $this->paise < 0;
        $absolute = abs($this->paise);

        $whole = intdiv($absolute, 100);
        $fraction = str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        $digits = (string) $whole;

        if (mb_strlen($digits) > 3) {
            $last = mb_substr($digits, -3);
            $rest = mb_substr($digits, 0, -3);
            $grouped = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $digits = "{$grouped},{$last}";
        }

        return ($negative ? '-' : '')."{$digits}.{$fraction}";
    }

    public function format(): string
    {
        return '₹'.$this->amount();
    }

    /** Whole rupees, for a card or a chart where the paise are noise. */
    public function short(): string
    {
        $rupees = abs($this->paise) / 100;

        return match (true) {
            $rupees >= 10000000 => '₹'.rtrim(rtrim(number_format($rupees / 10000000, 2, '.', ''), '0'), '.').' Cr',
            $rupees >= 100000 => '₹'.rtrim(rtrim(number_format($rupees / 100000, 2, '.', ''), '0'), '.').' L',
            $rupees >= 1000 => '₹'.rtrim(rtrim(number_format($rupees / 1000, 1, '.', ''), '0'), '.').'K',
            default => '₹'.number_format($rupees, 0),
        };
    }

    public static function display(?int $paise): string
    {
        return self::paise($paise ?? 0)->format();
    }

    /** Tax on an amount, rounded to the nearest paisa once. */
    public static function taxOn(int $paise, float $rate): int
    {
        return (int) round($paise * $rate / 100);
    }

    /**
     * The taxable amount hidden inside a tax inclusive figure.
     *
     * A course fee is advertised as one number, so the tax is worked back out
     * of it rather than added on top of a figure somebody has already read as
     * the total.
     *
     * Not every total is reachable: with tax rounded to the paisa, a subtotal
     * one paisa larger can move the total by two, so ₹15,000 at eighteen
     * percent lands on either ₹14,999.99 or ₹15,000.01. The nearest is taken,
     * and a tie goes to the lower one, because being asked for a paisa more
     * than the page said is the version somebody complains about.
     */
    public static function taxableWithin(int $inclusive, float $rate): int
    {
        if ($rate <= 0) {
            return $inclusive;
        }

        $start = (int) round($inclusive / (1 + $rate / 100));
        $best = $start;
        $bestGap = null;

        foreach ([0, -1, 1, -2, 2] as $nudge) {
            $candidate = $start + $nudge;
            $gap = abs($candidate + self::taxOn($candidate, $rate) - $inclusive);

            if ($bestGap === null || $gap < $bestGap) {
                $best = $candidate;
                $bestGap = $gap;
            }
        }

        return $best;
    }

    /** In words, for the line every Indian invoice carries. */
    public static function words(int $paise): string
    {
        $rupees = intdiv(abs($paise), 100);
        $fraction = abs($paise) % 100;

        $words = ucfirst(self::spell($rupees)).' rupees';

        if ($fraction > 0) {
            $words .= ' and '.self::spell($fraction).' paise';
        }

        return $words.' only';
    }

    private static function spell(int $number): string
    {
        if ($number === 0) {
            return 'zero';
        }

        $units = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
            'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

        $spell = function (int $n) use (&$spell, $units, $tens): string {
            if ($n < 20) {
                return $units[$n];
            }

            if ($n < 100) {
                return trim($tens[intdiv($n, 10)].' '.$units[$n % 10]);
            }

            return trim($units[intdiv($n, 100)].' hundred '.($n % 100 ? $spell($n % 100) : ''));
        };

        // Crore, lakh, thousand: the groupings the reader expects.
        $parts = [];

        foreach ([10000000 => 'crore', 100000 => 'lakh', 1000 => 'thousand'] as $divisor => $name) {
            if ($number >= $divisor) {
                $parts[] = $spell(intdiv($number, $divisor)).' '.$name;
                $number %= $divisor;
            }
        }

        if ($number > 0) {
            $parts[] = $spell($number);
        }

        return implode(' ', $parts);
    }
}
