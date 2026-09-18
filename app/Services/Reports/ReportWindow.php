<?php

namespace App\Services\Reports;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The period a report is about, and anything else narrowing it.
 *
 * One object rather than a pair of loose dates, because every report asks the
 * same two questions — from when, to when — and each one parsing the request
 * itself is how two reports end up disagreeing about whether "this month"
 * includes today.
 */
class ReportWindow
{
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        /** @var array<string, mixed> */
        public readonly array $filters = [],
    ) {}

    /** The last twelve months, ending today, unless the request says otherwise. */
    public static function fromRequest(Request $request): self
    {
        $to = $request->date('to') ?? now();
        $from = $request->date('from') ?? $to->copy()->subMonths(11)->startOfMonth();

        // A reversed range is somebody mistyping, not a request for no rows.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return new self(
            $from->copy()->startOfDay(),
            $to->copy()->endOfDay(),
            array_filter($request->input('filters', []), fn ($value) => $value !== null && $value !== ''),
        );
    }

    public function filter(string $key, mixed $default = null): mixed
    {
        return $this->filters[$key] ?? $default;
    }

    /** Every month the window touches, oldest first, as first-of-month dates. */
    public function months(): array
    {
        $months = [];
        $cursor = $this->from->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($this->to)) {
            $months[] = $cursor->copy();
            $cursor->addMonth();
        }

        return $months;
    }

    public function label(): string
    {
        return $this->from->format('j M Y').' to '.$this->to->format('j M Y');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'label' => $this->label(),
            'filters' => $this->filters,
        ];
    }
}
