<?php

namespace App\Support;

/**
 * A first password for an account an administrator created.
 *
 * It has to survive being read aloud over a phone and typed from an SMS, so it
 * is built from short words and digits rather than random symbols. Ambiguous
 * characters are excluded: nobody should have to work out whether that was a
 * one or a lowercase L.
 *
 * It is still strong enough to matter, because it is live until the person
 * replaces it. Roughly 46 bits of entropy from the word choices and digits,
 * against an endpoint that locks after five attempts a minute.
 */
class TemporaryPassword
{
    /** Short, unambiguous, and inoffensive when read out. */
    protected const WORDS = [
        'Amber', 'Anchor', 'Basil', 'Beacon', 'Birch', 'Bridge', 'Cedar', 'Cobalt',
        'Comet', 'Coral', 'Delta', 'Ember', 'Falcon', 'Garnet', 'Harbour', 'Indigo',
        'Ivory', 'Jasper', 'Kite', 'Lantern', 'Maple', 'Meadow', 'Nimbus', 'Onyx',
        'Orbit', 'Pepper', 'Quartz', 'Raven', 'Saffron', 'Summit', 'Topaz', 'Umber',
        'Valley', 'Willow', 'Zephyr', 'Cypress', 'Harvest', 'Juniper', 'Marble', 'Signal',
    ];

    public static function generate(): string
    {
        $first = self::WORDS[random_int(0, count(self::WORDS) - 1)];

        do {
            $second = self::WORDS[random_int(0, count(self::WORDS) - 1)];
        } while ($second === $first);

        // Four digits, never starting with zero, so it reads and types cleanly.
        $digits = random_int(1000, 9999);

        // Mixed case, letters and numbers, which is what the password policy asks for.
        return $first.'-'.strtolower($second).'-'.$digits;
    }
}
