<?php

namespace App\Services\Billing;

use App\Support\Money;

/**
 * Splits tax the way an Indian invoice has to.
 *
 * Supply inside our own state is CGST plus SGST at half the rate each; supply
 * to another state is IGST at the full rate. Getting this wrong does not change
 * what the client pays, but it does make the invoice wrong, and the invoice is
 * the document they file.
 */
class GstBreakup
{
    public function __construct(protected string $homeState) {}

    /**
     * @return array{components: array<int, array{label: string, rate: float, amount: int}>, total: int, intraState: bool}
     */
    public function for(int $taxableAmount, float $rate, ?string $placeOfSupply): array
    {
        $intraState = $this->isIntraState($placeOfSupply);
        $total = Money::taxOn($taxableAmount, $rate);

        if (! $intraState) {
            return [
                'components' => [['label' => 'IGST', 'rate' => $rate, 'amount' => $total]],
                'total' => $total,
                'intraState' => false,
            ];
        }

        // Halve the money, not the rate, then give the odd paisa to the first
        // component, so the two halves always add back to the total.
        $half = intdiv($total, 2);

        return [
            'components' => [
                ['label' => 'CGST', 'rate' => $rate / 2, 'amount' => $total - $half],
                ['label' => 'SGST', 'rate' => $rate / 2, 'amount' => $half],
            ],
            'total' => $total,
            'intraState' => true,
        ];
    }

    public function isIntraState(?string $placeOfSupply): bool
    {
        if (blank($placeOfSupply) || blank($this->homeState)) {
            // With no state on either side, assume our own: an intra state
            // invoice charged as inter state is the more annoying mistake to
            // unpick, and the state can be corrected before issue.
            return true;
        }

        return $this->normalise($placeOfSupply) === $this->normalise($this->homeState);
    }

    protected function normalise(string $state): string
    {
        return str($state)->lower()->replace(['.', '-'], '')->squish()->toString();
    }
}
