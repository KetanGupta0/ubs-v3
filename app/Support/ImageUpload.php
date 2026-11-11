<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Keep an uploaded picture, re-encoded.
 *
 * Drawing the pixels into a new image and writing that out is what drops
 * whatever came with the file: camera and location metadata, or anything
 * hidden after the image data. It also caps the width, so a forty megabyte
 * photo is not served to every visitor or embedded in every PDF.
 */
class ImageUpload
{
    /**
     * @param  string  $as  'jpg' for photographs, 'png' to keep transparency.
     */
    public static function store(UploadedFile $file, string $disk, string $directory, int $maxWidth, string $as = 'jpg', string $field = 'image'): string
    {
        $image = match ($file->getMimeType()) {
            'image/png' => @imagecreatefrompng($file->getRealPath()),
            'image/jpeg' => @imagecreatefromjpeg($file->getRealPath()),
            'image/webp' => @imagecreatefromwebp($file->getRealPath()),
            default => false,
        };

        if (! $image) {
            throw ValidationException::withMessages([$field => 'That picture could not be read. Use a JPG, PNG or WebP.']);
        }

        if (imagesx($image) > $maxWidth) {
            $image = imagescale($image, $maxWidth);
        }

        ob_start();
        if ($as === 'png') {
            imagesavealpha($image, true);
            imagepng($image, null, 7);
        } else {
            // A transparent PNG flattened onto white rather than black.
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            imagejpeg($flat, null, 84);
            imagedestroy($flat);
        }
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $path = trim($directory, '/').'/'.Str::uuid().'.'.$as;
        Storage::disk($disk)->put($path, $bytes);

        return $path;
    }
}
