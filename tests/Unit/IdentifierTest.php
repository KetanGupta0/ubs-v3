<?php

use App\Support\Identifier;

it('recognises an email address', function () {
    expect(Identifier::isEmail('aarti@example.com'))->toBeTrue()
        ->and(Identifier::isEmail('9876543210'))->toBeFalse();
});

it('lowercases an email address', function () {
    expect(Identifier::normalise('  Aarti@Example.COM '))->toBe('aarti@example.com');
});

it('normalises every way an Indian mobile number gets typed', function () {
    $expected = '+919876543210';

    foreach ([
        '9876543210',
        '98765 43210',
        '098765 43210',
        '919876543210',
        '+91 98765-43210',
    ] as $input) {
        expect(Identifier::normaliseMobile($input))->toBe($expected);
    }
});

it('keeps a country code that was given explicitly', function () {
    expect(Identifier::normaliseMobile('+14155552671'))->toBe('+14155552671');
});

it('masks an identifier for display', function () {
    expect(Identifier::mask('aarti@example.com'))->toStartWith('aa')
        ->and(Identifier::mask('aarti@example.com'))->toEndWith('@example.com')
        ->and(Identifier::mask('aarti@example.com'))->not->toContain('rti')
        ->and(Identifier::mask('+919876543210'))->toEndWith('3210')
        ->and(Identifier::mask('+919876543210'))->not->toContain('9876');
});
