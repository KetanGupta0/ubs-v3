<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Validate, and get back every key that was asked for.
     *
     * Laravel's own validate() leaves out any optional field the request did
     * not send, so `$validated['project_id']` is a fatal error rather than
     * null exactly when the field was left blank — which is the common case,
     * not the edge case. This has caused three separate 500s in this codebase,
     * so every rule key comes back, absent ones as null.
     *
     * A field declared `boolean` comes back false rather than null, because an
     * unticked checkbox is not sent at all and null in a NOT NULL column is a
     * 500 rather than an unticked box.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @return array<string, mixed>
     */
    protected function validatedInput(Request $request, array $rules, array $messages = []): array
    {
        $validated = $request->validate($rules, $messages);

        foreach (array_keys($rules) as $field) {
            // Nested rules like 'items.*' describe the elements of a key that
            // is declared separately, so they are not fields of their own.
            if (str_contains($field, '.')) {
                continue;
            }

            $validated[$field] ??= $this->declaresBoolean($rules[$field]) ? false : null;
        }

        return $validated;
    }

    /**
     * A boolean the request may simply not have sent, with a stated default.
     *
     * Absent is not the same as false everywhere: a document uploaded by a
     * form with no visibility switch on it is meant for the client, and a
     * payment request raised without a `notify` field is meant to be sent.
     * Those defaults are said out loud here rather than left to a null.
     */
    protected function boolInput(Request $request, string $field, bool $default = false): bool
    {
        return $request->has($field) ? $request->boolean($field) : $default;
    }

    /** @param  mixed  $rule  A rule set, as a string, an array or an object. */
    protected function declaresBoolean(mixed $rule): bool
    {
        if (is_string($rule)) {
            $rule = explode('|', $rule);
        }

        return is_array($rule) && in_array('boolean', $rule, true);
    }
}
