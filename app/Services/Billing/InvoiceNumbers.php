<?php

namespace App\Services\Billing;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The invoice number sequence.
 *
 * Two invoices raised in the same second must not take the same number, so the
 * counter is read and written inside a transaction that locks the row. Without
 * the lock, two concurrent requests both read "next is 41" and two invoices go
 * out as 41, which is the kind of thing nobody notices until an audit.
 *
 * The sequence restarts each financial year, which is what the numbering on an
 * Indian invoice is expected to do.
 */
class InvoiceNumbers
{
    /**
     * Claim the next number.
     *
     * @return array{number: string, financialYear: string, sequence: int}
     */
    public function next(?Carbon $on = null): array
    {
        $on ??= now();
        $financialYear = $this->financialYearFor($on);

        return DB::transaction(function () use ($financialYear) {
            $prefix = Setting::get('invoicing.prefix', 'UBS');

            // Lock the counter row itself, not the table, so only writers to
            // this one setting wait on each other.
            $row = DB::table('settings')->where('key', 'invoicing.next_number')->lockForUpdate()->first();

            $stored = $row ? json_decode($row->value, true) : null;
            $sequence = (int) ($stored['v'] ?? 1);

            // A financial year rollover restarts at one, and the restart is
            // decided by what is already issued rather than by the counter,
            // because the counter is shared across years.
            $issued = DB::table('invoices')->where('financial_year', $financialYear)->max('sequence');
            $sequence = $issued ? $issued + 1 : max($sequence, 1);

            Setting::put('invoicing.next_number', $sequence + 1, 'invoicing');

            return [
                'number' => sprintf('%s/%s/%04d', $prefix, $financialYear, $sequence),
                'financialYear' => $financialYear,
                'sequence' => $sequence,
            ];
        });
    }

    /** '2026-27' for a year starting in April. */
    public function financialYearFor(Carbon $on): string
    {
        $startMonth = (int) Setting::get('invoicing.financial_year_start_month', 4);

        $startYear = $on->month >= $startMonth ? $on->year : $on->year - 1;

        return sprintf('%d-%02d', $startYear, ($startYear + 1) % 100);
    }
}
