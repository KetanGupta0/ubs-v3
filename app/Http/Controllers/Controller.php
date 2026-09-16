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

            $validated[$field] ??= null;
        }

        return $validated;
    }
}
