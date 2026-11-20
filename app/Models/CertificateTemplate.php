<?php

namespace App\Models;

use App\Support\CertificateArt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The look of a course's certificates: a layout, then the wording, accent and
 * signatory set into it. See the first migration for why it is not a canvas.
 */
class CertificateTemplate extends Model
{
    /**
     * The fixed layouts a design can be set into. Classic is the built-in one
     * and the only one that takes an uploaded background; the others are
     * drawn whole, ornaments and typefaces included, in
     * resources/views/credentials/certificates.
     */
    public const LAYOUTS = [
        'classic' => [
            'label' => 'Classic',
            'description' => 'A clean double border, or a background of your own.',
            'accent' => 'Colours the border and the course title.',
            'ink' => null,
        ],
        'regal' => [
            'label' => 'Regal',
            'description' => 'Ivory and a gold double frame with corner flourishes, the name in script, a gold seal on ribbons.',
            'accent' => 'The ink of the heading, the name and the course. A dark colour; a light one gives navy.',
            // Printed as words on the ivory ground.
            'ink' => ['fallback' => '#1e2a4a', 'against' => ['#fbf7ec']],
        ],
        'midnight' => [
            'label' => 'Midnight',
            'description' => 'A dark ground with gold rules and lettering, the name in white, signatures on an ivory band.',
            'accent' => 'The ground. A deep colour, for gold and white words on it; a lighter one gives navy.',
            // The ground under its dimmest words: the dates and the gold lettering.
            'ink' => ['fallback' => '#0e1630', 'against' => ['#b9bfd2', '#e2c26c']],
        ],
        'heritage' => [
            'label' => 'Heritage',
            'description' => 'A coloured panel down the left with the logo and a gold seal; the wording set left in a serif.',
            'accent' => 'The panel and the name. A dark colour; a light one gives burgundy.',
            // The panel under its pale gold label, and the name on the paper.
            'ink' => ['fallback' => '#6d1f2c', 'against' => ['#ecdcae', '#fffdf7']],
        ],
        'laurel' => [
            'label' => 'Laurel',
            'description' => 'White and quiet, fine gold rules, and a gold laurel round the grade.',
            'accent' => 'The ink of the course title and the grade. A dark colour; a light one gives navy.',
            'ink' => ['fallback' => '#173a5e', 'against' => ['#ffffff']],
        ],
    ];

    protected $guarded = ['id'];

    protected $attributes = [
        'layout' => 'classic',
    ];

    protected function casts(): array
    {
        return [
            'show_grade' => 'boolean',
            'show_qr' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** The view this design's certificates are rendered from. */
    public function view(): string
    {
        $layout = array_key_exists((string) $this->layout, self::LAYOUTS) ? $this->layout : 'classic';

        return $layout === 'classic' ? 'credentials.certificate' : 'credentials.certificates.'.$layout;
    }

    /**
     * The accent as this design's layout prints it: itself where it reads at
     * 4.5:1 against everything it meets there, else the layout's own colour.
     */
    public function ink(): string
    {
        $rule = self::LAYOUTS[$this->layout]['ink'] ?? null;

        if ($rule === null) {
            return preg_match('/^#[0-9a-fA-F]{6}$/', (string) $this->accent) ? strtolower($this->accent) : '#4f46e5';
        }

        return CertificateArt::ink($this->accent, $rule['fallback'], ...$rule['against']);
    }

    /** The certificates issued with this design. */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /** Only Classic draws an uploaded background; the others are complete without one. */
    public function takesBackground(): bool
    {
        return $this->view() === 'credentials.certificate';
    }

    /**
     * The template a new certificate on this course is issued with: the
     * course's own active one, else the active default, else none (the
     * built-in design).
     */
    public static function forCourse(?int $courseId): ?self
    {
        $own = $courseId === null ? null : static::query()
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        return $own ?? static::query()
            ->whereNull('course_id')
            ->where('is_active', true)
            ->latest('id')
            ->first();
    }
}
