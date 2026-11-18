<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Licence;
use App\Models\ProductVersion;
use App\Models\User;
use App\Services\Shop\Licences;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A source code download, through a signed link that expires (X16).
 *
 * The link was made for one licence, one version and one person, minutes ago,
 * by somebody allowed to download; the signature is what proves it. It is
 * checked again here, because a licence can be withdrawn in between, and every
 * download is counted against the licence.
 */
class DownloadController extends Controller
{
    public function __invoke(Request $request, int $licence, int $version, Licences $licences): StreamedResponse
    {
        $record = Licence::query()->findOrFail($licence);
        $file = ProductVersion::query()->where('solution_id', $record->solution_id)->whereKey($version)->firstOrFail();

        if ($refusal = $licences->downloadRefusal($record, $file)) {
            abort(403, $refusal);
        }

        abort_unless(Storage::disk('private')->exists($file->path), 404);

        $licences->recordDownload($record, $file, User::query()->find($request->integer('by')), $request->ip());

        return Storage::disk('private')->download($file->path, $file->downloadName());
    }
}
