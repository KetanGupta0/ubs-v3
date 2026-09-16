<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An editable message body with placeholders.
 *
 * Lets an administrator change the wording of an automated message without a
 * deployment. Placeholders are substituted by name, and anything the template
 * does not know about is left alone rather than blanked, so a typo in a
 * placeholder is visible instead of silently deleting text.
 */
class MessageTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @param  array<string, string|null>  $values */
    public function render(array $values): string
    {
        return $this->substitute($this->body, $values);
    }

    /** @param  array<string, string|null>  $values */
    public function renderSubject(array $values): ?string
    {
        return $this->subject ? $this->substitute($this->subject, $values) : null;
    }

    protected function substitute(string $text, array $values): string
    {
        foreach ($values as $key => $value) {
            $text = str_replace('{{'.$key.'}}', (string) $value, $text);
        }

        return $text;
    }

    public static function find_by_key(string $key): ?self
    {
        return self::query()->where('key', $key)->where('is_active', true)->first();
    }
}
