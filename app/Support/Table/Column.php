<?php

namespace App\Support\Table;

use Closure;

/**
 * One column of a table.
 *
 * Carries both the display contract sent to Vue and the value resolver used
 * when the same table is exported, so a spreadsheet can never drift out of
 * sync with what the screen shows.
 */
class Column
{
    protected string $label;

    protected bool $sortable = false;

    protected bool $exportable = true;

    protected bool $visible = true;

    protected bool $numeric = false;

    protected ?Closure $exportUsing = null;

    protected ?string $width = null;

    final public function __construct(protected string $key, ?string $label = null)
    {
        $this->label = $label ?? str($key)->afterLast('.')->headline()->toString();
    }

    public static function make(string $key, ?string $label = null): static
    {
        return new static($key, $label);
    }

    public function sortable(bool $sortable = true): static
    {
        $this->sortable = $sortable;

        return $this;
    }

    /** Right aligned and rendered with tabular figures. */
    public function numeric(bool $numeric = true): static
    {
        $this->numeric = $numeric;

        return $this;
    }

    /** Present in the column picker but switched off until the user asks for it. */
    public function hidden(bool $hidden = true): static
    {
        $this->visible = ! $hidden;

        return $this;
    }

    public function notExportable(): static
    {
        $this->exportable = false;

        return $this;
    }

    public function width(string $width): static
    {
        $this->width = $width;

        return $this;
    }

    /**
     * Override the exported value, for example to flatten a relation or to
     * write a raw number where the screen shows a formatted currency string.
     */
    public function exportUsing(Closure $callback): static
    {
        $this->exportUsing = $callback;

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function isExportable(): bool
    {
        return $this->exportable;
    }

    /**
     * Resolve this column's value for a single row during export.
     */
    public function exportValue(mixed $row): mixed
    {
        if ($this->exportUsing) {
            return ($this->exportUsing)($row);
        }

        $value = data_get($row, $this->key);

        return match (true) {
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i'),
            is_array($value) => implode(', ', $value),
            default => $value,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'sortable' => $this->sortable,
            'visible' => $this->visible,
            'numeric' => $this->numeric,
            'width' => $this->width,
        ];
    }
}
