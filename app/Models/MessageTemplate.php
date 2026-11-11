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
        if (isset(self::$previewing[$key])) {
            return self::$previewing[$key];
        }

        return self::query()->where('key', $key)->where('is_active', true)->first();
    }

    /** @var array<string, self> unsaved wording standing in for the stored one */
    protected static array $previewing = [];

    /**
     * Run $callback as if this (possibly unsaved) wording were in use.
     *
     * How the preview and the test send show exactly what the real
     * notification would produce, rather than a second rendering of it that
     * could drift from the first.
     */
    public static function using(self $draft, \Closure $callback): mixed
    {
        self::$previewing[$draft->key] = $draft;

        try {
            return $callback();
        } finally {
            unset(self::$previewing[$draft->key]);
        }
    }

    /**
     * Every placeholder written into $text, as typed.
     *
     * Anything between double braces counts, so a misspelt or spaced one is
     * caught rather than sent to somebody as literal braces.
     *
     * @return array<int, string>
     */
    public static function placeholdersIn(?string $text): array
    {
        preg_match_all('/\{\{(.*?)\}\}/s', (string) $text, $matches);

        return array_values(array_unique($matches[1]));
    }
}
