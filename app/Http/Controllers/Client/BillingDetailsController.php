<?php

namespace App\Http\Controllers\Client;

use App\Services\Admin\Auditor;
use App\Support\IndianStates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who we bill (CL14): the organisation's legal name, GSTIN and address, as
 * finance or an owner keeps them. Every invoice copies them when it is
 * issued, so a change applies to invoices from now on, never to one
 * already issued.
 */
class BillingDetailsController extends ClientController
{
    public function edit(Request $request): Response
    {
        $profile = $this->client($request)->profile;

        return Inertia::render('client/BillingDetails', [
            'details' => [
                'company' => $profile?->company ?? '',
                'gstin' => $profile?->gstin ?? '',
                'address_line_1' => $profile?->address_line_1 ?? '',
                'city' => $profile?->city ?? '',
                'state' => $profile?->state ?? '',
                'postal_code' => $profile?->postal_code ?? '',
            ],
            'states' => collect(IndianStates::CODES)->map(fn (string $name) => ['value' => $name, 'label' => $name])->values(),
            'canEdit' => $request->user()->canAsClient('pay'),
        ]);
    }

    public function update(Request $request, Auditor $auditor): RedirectResponse
    {
        $this->mayAsClient($request, 'pay');

        $request->merge(['gstin' => strtoupper(str_replace(' ', '', (string) $request->input('gstin')))]);

        $validated = $this->validatedInput($request, [
            'company' => ['required', 'string', 'max:160'],
            'gstin' => ['nullable', 'string', 'regex:'.IndianStates::GSTIN_PATTERN],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['required', Rule::in(array_values(IndianStates::CODES))],
            'postal_code' => ['nullable', 'regex:/^[1-9][0-9]{5}$/'],
        ], [
            'company.required' => 'The legal name to put on invoices.',
            'gstin.regex' => 'That is not the shape of a GSTIN: 15 characters, such as 23ABCDE1234F1Z5.',
            'state.required' => 'Choose the state you are registered in. It decides how GST is charged.',
            'postal_code.regex' => 'A PIN code is six digits.',
        ]);

        if ($validated['gstin'] && substr($validated['gstin'], 0, 2) !== IndianStates::codeOf($validated['state'])) {
            $registered = IndianStates::CODES[substr($validated['gstin'], 0, 2)] ?? null;

            throw ValidationException::withMessages(['gstin' => $registered
                ? "This GSTIN is registered in {$registered}. Choose that state, or check the number."
                : 'The first two digits of a GSTIN are a state code, and these are not one.']);
        }

        $account = $this->client($request);
        $profile = $account->profile()->firstOrNew();
        $profile->fill($validated);
        $auditor->actingAs($request->user())->updated($profile, label: $account->organisationName());
        $account->profile()->save($profile);

        return back()->with('success', 'Saved. Invoices issued from now on carry these details.');
    }
}
