<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A person's name: letters in any script, with spaces, dots, apostrophes and
 * hyphens between them. Nothing else.
 *
 * A name is repeated back in the mail an account receives ("Hello …", a
 * welcome, credentials), so a form that takes anything lets a bot put a link
 * and an advert there and have our domain mail it to whatever address it typed.
 * Until November 2026 that is how three accounts named "🔥 70.000 TL …
 * https://bit.ly/…" reached the client list (brought from v2, which took any
 * name). A link, an address, a number or an emoji is refused here, on every
 * form that names a person.
 */
class PersonName implements ValidationRule
{
    public const MESSAGE = 'Write a name in letters only: no links, numbers or symbols, and a space after a dot.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::looksLikeAName($value)) {
            $fail(self::MESSAGE);
        }
    }

    public static function looksLikeAName(string $value): bool
    {
        $value = trim($value);

        return mb_strlen($value) >= 2
            && preg_match("/^\p{L}[\p{L}\p{M}]*(?:[ .'\x{2019}-]+[\p{L}\p{M}]+)*\.?$/u", $value) === 1
            // Two words joined by a dot with no space is an address (www.example,
            // bit.ly); initials (A.K. Singh) are single letters and still pass.
            && preg_match('/\p{L}{2,}\.\p{L}{2,}/u', $value) === 0;
    }
}
