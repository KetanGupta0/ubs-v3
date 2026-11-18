<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Shop\Licences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where software we sold checks its licence key (X18).
 *
 * No account and no token: the key is the credential. It is answered the same
 * for a key that never existed and one for another product. Each answer says
 * when to ask again, so a site checks about once a day rather than on every
 * page view, which matters on shared hosting.
 */
class LicenceCheckController extends Controller
{
    public function __invoke(Request $request, Licences $licences): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'site' => ['required', 'string', 'max:255'],
            'product' => ['nullable', 'string', 'max:180'],
            'version' => ['nullable', 'string', 'max:30'],
        ]);

        $host = Licences::hostOf($validated['site']);

        if ($host === null) {
            return response()->json(['message' => 'Send the site\'s address, such as clinic.example.com.', 'errors' => ['site' => ['Send the site\'s address, such as clinic.example.com.']]], 422);
        }

        return response()->json($licences->check($validated['key'], $host, $validated['product'] ?? null, $validated['version'] ?? null, $request->ip()));
    }
}
