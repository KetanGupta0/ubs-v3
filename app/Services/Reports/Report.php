<?php

namespace App\Services\Reports;

use App\Support\Table\Column;
use Illuminate\Support\Collection;

/**
 * One report.
 *
 * A report answers a question with three things: a few headline figures, a
 * chart or two, and the rows behind them. The rows are not optional — a figure
 * nobody can drill into is a figure nobody can check, and the same rows are
 * what the CSV, the spreadsheet and the PDF are made from, so a download can
 * never disagree with the screen.
 *
 * Colours are never named here. A chart declares which slot each series takes
 * and the client maps slots to the palette, so the fixed order that makes the
 * palette colour blind safe lives in one place rather than in seven reports.
 */
abstract class Report
{
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** Who may run it. Null means anybody who can reach the reports screen. */
    public function permission(): ?string
    {
        return null;
    }

    /** Extra controls this report offers beyond the date range. */
    public function controls(): array
    {
        return [];
    }

    /** Headline figures: [['label' => …, 'value' => …, 'hint' => …, 'tone' => …]] */
    abstract public function summary(ReportWindow $window): array;

    /**
     * Charts, each as data rather than as a drawing.
     *
     * [[
     *   'id' => 'revenue',
     *   'kind' => 'line'|'bar'|'stacked',
     *   'title' => …, 'subtitle' => …,
     *   'categories' => [...],
     *   'series' => [['label' => …, 'slot' => 1, 'values' => [...], 'display' => [...]]],
     * ]]
     */
    abstract public function charts(ReportWindow $window): array;

    /** @return Collection<int, Column> */
    abstract public function columns(): Collection;

    /** @return Collection<int, array<string, mixed>> */
    abstract public function rows(ReportWindow $window): Collection;

    /** Everything the screen needs, in one shape. */
    public function run(ReportWindow $window): array
    {
        $rows = $this->rows($window);

        return [
            'key' => $this->key(),
            'title' => $this->title(),
            'description' => $this->description(),
            'window' => $window->toArray(),
            'controls' => $this->controls(),
            'summary' => $this->summary($window),
            'charts' => $this->charts($window),
            'columns' => $this->columns()->map(fn (Column $column) => $column->toArray())->values()->all(),
            'rows' => $rows->values()->all(),
            'rowCount' => $rows->count(),
        ];
    }
}
