<?php

namespace App\Services\Chat;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Taking in a photo or a voice note.
 *
 * Text, image and audio are the only three kinds a message can be, which was
 * an explicit requirement and is a smaller attack surface as well as a smaller
 * feature. This class is where that is actually enforced on a file: the type
 * is read from the bytes rather than taken from the name, and anything that is
 * not a picture or a sound is refused before it is written anywhere.
 *
 * An image is re-encoded rather than stored as it arrived. That drops the EXIF
 * block, which routinely carries the GPS coordinates of somebody's house, and
 * it means a file that merely claims to be a JPEG never survives the trip.
 */
class MediaStore
{
    /** What a browser may actually send. */
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public const AUDIO_TYPES = [
        'audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac',
        'audio/wav', 'audio/x-wav', 'audio/x-m4a', 'video/webm',
    ];

    /** Megabytes. A photo from a phone is well under this; a film is not. */
    public const MAX_IMAGE_MB = 8;

    public const MAX_AUDIO_MB = 12;

    /** The longest edge we keep. Anything larger is a wall poster, not a chat. */
    public const MAX_EDGE = 1600;

    /** Seconds. Long enough to explain something, short enough to listen to. */
    public const MAX_AUDIO_SECONDS = 300;

    /**
     * @return array{path: string, mime: string, size: int, meta: array<string, mixed>}
     */
    public function storeImage(UploadedFile $file, int $conversationId): array
    {
        $mime = $this->sniff($file);

        if (! in_array($mime, self::IMAGE_TYPES, true)) {
            throw new RuntimeException('That is not an image we can send.');
        }

        if ($file->getSize() > self::MAX_IMAGE_MB * 1024 * 1024) {
            throw new RuntimeException('That picture is over '.self::MAX_IMAGE_MB.' MB.');
        }

        [$encoded, $width, $height] = $this->reencode($file->getRealPath(), $mime);

        $path = $this->pathFor($conversationId, $mime === 'image/png' ? 'png' : 'jpg');

        Storage::disk('private')->put($path, $encoded);

        return [
            'path' => $path,
            'mime' => $mime === 'image/png' ? 'image/png' : 'image/jpeg',
            'size' => strlen($encoded),
            'meta' => ['width' => $width, 'height' => $height],
        ];
    }

    /**
     * @param  array<string, mixed>  $claimed  What the recorder says it captured.
     * @return array{path: string, mime: string, size: int, meta: array<string, mixed>}
     */
    public function storeAudio(UploadedFile $file, int $conversationId, array $claimed = []): array
    {
        $mime = $this->sniff($file);

        if (! in_array($mime, self::AUDIO_TYPES, true)) {
            throw new RuntimeException('That is not a sound file we can send.');
        }

        if ($file->getSize() > self::MAX_AUDIO_MB * 1024 * 1024) {
            throw new RuntimeException('That recording is over '.self::MAX_AUDIO_MB.' MB.');
        }

        $path = $this->pathFor($conversationId, $file->getClientOriginalExtension() ?: 'webm');

        Storage::disk('private')->putFileAs(
            dirname($path),
            $file,
            basename($path),
        );

        return [
            'path' => $path,
            'mime' => $mime,
            'size' => $file->getSize(),
            'meta' => [
                'duration' => $this->duration($claimed),
                'peaks' => $this->peaks($claimed),
            ],
        ];
    }

    public function delete(?string $path): void
    {
        if ($path && Storage::disk('private')->exists($path)) {
            Storage::disk('private')->delete($path);
        }
    }

    /* ----------------------------------------------------------- helpers */

    /** The type according to the bytes, not according to the filename. */
    protected function sniff(UploadedFile $file): string
    {
        $real = $file->getRealPath();

        if (! $real || ! is_readable($real)) {
            throw new RuntimeException('That file did not arrive in one piece.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($real);

        return $mime ?: 'application/octet-stream';
    }

    /**
     * Re-encode an image through GD.
     *
     * This is what strips EXIF: nothing is copied across, the pixels are drawn
     * into a new canvas and written out again. Orientation is applied first,
     * because a phone photo that is "rotated" only by its EXIF tag would
     * otherwise come out sideways once the tag is gone.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    protected function reencode(string $realPath, string $mime): array
    {
        $image = match ($mime) {
            'image/png' => @imagecreatefrompng($realPath),
            'image/webp' => @imagecreatefromwebp($realPath),
            'image/gif' => @imagecreatefromgif($realPath),
            default => @imagecreatefromjpeg($realPath),
        };

        if (! $image) {
            throw new RuntimeException('That picture could not be read.');
        }

        $image = $this->applyOrientation($image, $realPath, $mime);
        $image = $this->fit($image);

        ob_start();

        if ($mime === 'image/png') {
            imagesavealpha($image, true);
            imagepng($image, null, 7);
        } else {
            imagejpeg($image, null, 82);
        }

        $encoded = (string) ob_get_clean();

        $width = imagesx($image);
        $height = imagesy($image);

        imagedestroy($image);

        return [$encoded, $width, $height];
    }

    /** @param  \GdImage  $image */
    protected function applyOrientation($image, string $realPath, string $mime)
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($realPath);
        $orientation = $exif['Orientation'] ?? 1;

        return match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    /** @param  \GdImage  $image */
    protected function fit($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= self::MAX_EDGE) {
            return $image;
        }

        $scale = self::MAX_EDGE / $longest;

        $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));

        if ($resized === false) {
            return $image;
        }

        imagedestroy($image);

        return $resized;
    }

    protected function pathFor(int $conversationId, string $extension): string
    {
        // A generated name, never the one the sender chose: a filename is a
        // label, and treating it as a path is how somebody climbs out of a
        // directory.
        return "chat/{$conversationId}/".Str::ulid().'.'.Str::lower($extension);
    }

    /**
     * How long the recording is.
     *
     * There is no ffmpeg here, and asking PHP to decode Opus to count samples
     * would be silly, so the figure comes from the recorder, which had the
     * decoded audio in hand. It is clamped rather than trusted: a claimed
     * duration only ever affects a label.
     */
    protected function duration(array $claimed): ?int
    {
        $seconds = (int) round((float) ($claimed['duration'] ?? 0));

        if ($seconds <= 0) {
            return null;
        }

        return min($seconds, self::MAX_AUDIO_SECONDS);
    }

    /**
     * The bars a waveform is drawn from.
     *
     * Computed once by the recorder and stored, rather than by every viewer's
     * browser on every render. Values outside nought to one are clamped and the
     * list is capped, so a hostile client can make an ugly waveform and nothing
     * worse.
     *
     * @return array<int, float>
     */
    protected function peaks(array $claimed): array
    {
        $peaks = $claimed['peaks'] ?? [];

        if (! is_array($peaks)) {
            return [];
        }

        return collect($peaks)
            ->take(80)
            ->map(fn ($peak) => round(max(0, min(1, (float) $peak)), 3))
            ->values()
            ->all();
    }
}
