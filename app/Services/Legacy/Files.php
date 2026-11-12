<?php

namespace App\Services\Legacy;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Files v2 kept on disk, copied into v3's own layout.
 *
 * v2's storage directory is given to the importer with --storage. A file is
 * copied under a new name, checked byte for byte with sha256 after the copy,
 * and its type is read from its contents, never from its name. On a dry run
 * nothing is written; the file is only looked for. A file v2 points at but
 * which is not on disk is reported, never silently dropped.
 */
class Files
{
    /**
     * @param  string  $sourceDisk  'public' or 'local', as v2 stored it, or 'any' to look in both
     * @return array{path: string, mime: string, size: int, sha256: string}|null
     */
    public static function copy(Context $context, string $step, string $source, string $sourceDisk, ?string $path, string $targetDisk, string $targetDirectory): ?array
    {
        $file = self::locate($context, $sourceDisk, $path);

        if ($file === null) {
            $context->report->warn($step, 'file_missing', $source, 'The file it points at is not in the storage copy; listed for follow-up.');

            return null;
        }

        $bytes = (string) file_get_contents($file);
        $sha256 = hash('sha256', $bytes);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION)) ?: 'bin';
        $target = trim($targetDirectory, '/').'/'.Str::uuid().'.'.preg_replace('/[^a-z0-9]/', '', $extension);

        if (! $context->report->dryRun) {
            Storage::disk($targetDisk)->put($target, $bytes);

            if (hash('sha256', (string) Storage::disk($targetDisk)->get($target)) !== $sha256) {
                Storage::disk($targetDisk)->delete($target);
                $context->report->warn($step, 'file_copy_mismatch', $source, 'The copy did not match the original byte for byte; not imported.');

                return null;
            }
        }

        $context->report->count($step, 'files');

        return ['path' => $target, 'mime' => $mime, 'size' => strlen($bytes), 'sha256' => $sha256];
    }

    /** Where a v2 path lives inside the storage copy, if anywhere. */
    public static function locate(Context $context, string $disk, ?string $path): ?string
    {
        if (blank($path) || $context->storagePath === null || str_contains($path, '..')) {
            return null;
        }

        $roots = match ($disk) {
            'public' => ['app/public'],
            'local' => ['app/private', 'app'],
            // Where v2 was not consistent about which disk it used.
            default => ['app/public', 'app/private', 'app'],
        };

        foreach ($roots as $root) {
            $candidate = rtrim($context->storagePath, '/')."/{$root}/".ltrim($path, '/');

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
