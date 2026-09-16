<?php

use App\Support\Money;

it('reads what a form actually sends', function () {
    expect(Money::toPaise('1,20,000'))->toBe(12000000)
        ->and(Money::toPaise('₹ 1200.50'))->toBe(120050)
        ->and(Money::toPaise(''))->toBe(0)
        ->and(Money::toPaise(null))->toBe(0)
        ->and(Money::toPaise(1200))->toBe(120000);
});

it('does not lose half a rupee to a cast', function () {
    // (int) (1200.5 * 100) is 120049 on some builds. Rounding first is the fix.
    expect(Money::toPaise('1200.505'))->toBe(120051)
        ->and(Money::toPaise('0.1'))->toBe(10);
});

it('groups the way an Indian invoice groups', function () {
    expect(Money::display(12000000))->toBe('₹1,20,000.00')
        ->and(Money::display(100000000))->toBe('₹10,00,000.00')
        ->and(Money::display(99900))->toBe('₹999.00')
        ->and(Money::display(0))->toBe('₹0.00');
});

it('shortens large amounts for a card', function () {
    expect(Money::paise(250000000)->short())->toBe('₹25 L')
        ->and(Money::paise(1500000000)->short())->toBe('₹1.5 Cr')
        ->and(Money::paise(100000000000)->short())->toBe('₹100 Cr')
        ->and(Money::paise(450000)->short())->toBe('₹4.5K')
        ->and(Money::paise(99900)->short())->toBe('₹999');
});

it('writes the amount in words, as the invoice has to', function () {
    expect(Money::words(12000000))->toBe('One lakh twenty thousand rupees only')
        ->and(Money::words(120050))->toBe('One thousand two hundred rupees and fifty paise only')
        ->and(Money::words(0))->toBe('Zero rupees only');
});

it('rounds tax once, not per component', function () {
    expect(Money::taxOn(100033, 18))->toBe(18006);
});
