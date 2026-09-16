<?php

namespace App\Support\Table;

use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * A single filter control and the query clause behind it.
 *
 * The column a filter touches is always one this class was told about, never a
 * value taken from the request, so a crafted query string cannot reach a column
 * the screen did not offer.
 */
class Filter
{
    public const SELECT = 'select';

    public const MULTI = 'multi';

    public const BOOLEAN = 'boolean';

    public const DATE_RANGE = 'date_range';

    public const NUMBER_RANGE = 'number_range';

    public const TEXT = 'text';

    protected string $label;

    protected array $options = [];

    protected ?Closure $using = null;

    protected ?string $column = null;

    protected ?string $placeholder = null;

    final public function __construct(
        protected string $key,
        protected string $type,
        ?string $label = null,
    ) {
        $this->label = $label ?? str($key)->headline()->toString();
    }

    public static function select(string $key, array $options = [], ?string $label = null): static
    {
        return (new static($key, self::SELECT, $label))->options($options);
    }

    public static function multi(string $key, array $options = [], ?string $label = null): static
    {
        return (new static($key, self::MULTI, $label))->options($options);
    }

    public static function boolean(string $key, ?string $label = null): static
    {
        return new static($key, self::BOOLEAN, $label);
    }

    public static function dateRange(string $key, ?string $label = null): static
    {
        return new static($key, self::DATE_RANGE, $label);
    }

    public static function numberRange(string $key, ?string $label = null): static
    {
        return new static($key, self::NUMBER_RANGE, $label);
    }

    public static function text(string $key, ?string $label = null): static
    {
        return new static($key, self::TEXT, $label);
    }

    /**
     * @param  array<int|string, mixed>  $options  Either ['value' => 'Label'] or a list of
     *                                             ['value' => ..., 'label' => ...] rows.
     */
    public function options(array $options): static
    {
        $this->options = array_is_list($options) && ! isset($options[0]['value'])
            ? array_map(fn ($option) => [
                'value' => $option,
                'label' => str((string) $option)->headline()->toString(),
            ], $options)
            : collect($options)->map(fn ($label, $value) => is_array($label)
                ? $label
                : ['value' => $value, 'label' => $label])->values()->all();

        return $this;
    }

    /** Target a different database column than the filter key. */
    public function column(string $column): static
    {
        $this->column = $column;

        return $this;
    }

    public function placeholder(string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    /** Replace the default clause entirely. Receives ($query, $value). */
    public function using(Closure $callback): static
    {
        $this->using = $callback;

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * Apply this filter to the query for the given user supplied value.
     */
    public function apply(Builder $query, mixed $value): void
    {
        if ($this->isEmpty($value)) {
            return;
        }

        if ($this->using) {
            ($this->using)($query, $value);

            return;
        }

        $column = $this->column ?? $this->key;

        match ($this->type) {
            self::SELECT, self::TEXT => $this->applyScalar($query, $column, $value),
            self::MULTI => $query->whereIn($column, (array) $value),
            self::BOOLEAN => $query->where($column, filter_var($value, FILTER_VALIDATE_BOOLEAN)),
            self::DATE_RANGE => $this->applyDateRange($query, $column, $value),
            self::NUMBER_RANGE => $this->applyNumberRange($query, $column, $value),
            default => null,
        };
    }

    protected function applyScalar(Builder $query, string $column, mixed $value): void
    {
        if ($this->type === self::TEXT) {
            $query->where($column, 'like', '%'.$this->escapeLike((string) $value).'%');

            return;
        }

        $query->where($column, $value);
    }

    protected function applyDateRange(Builder $query, string $column, mixed $value): void
    {
        $from = data_get($value, 'from');
        $to = data_get($value, 'to');

        if ($from) {
            $query->whereDate($column, '>=', $from);
        }

        if ($to) {
            $query->whereDate($column, '<=', $to);
        }
    }

    protected function applyNumberRange(Builder $query, string $column, mixed $value): void
    {
        $min = data_get($value, 'min');
        $max = data_get($value, 'max');

        if (is_numeric($min)) {
            $query->where($column, '>=', $min);
        }

        if (is_numeric($max)) {
            $query->where($column, '<=', $max);
        }
    }

    protected function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        // A range whose ends are both blank is the same as no filter at all.
        if (is_array($value)) {
            return collect($value)->filter(fn ($v) => $v !== null && $v !== '')->isEmpty();
        }

        return false;
    }

    /** Stop a user's % or _ from turning into a wildcard. */
    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'label' => $this->label,
            'options' => $this->options,
            'placeholder' => $this->placeholder,
        ];
    }
}
