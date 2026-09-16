<?php

namespace App\Support\Table;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns a set of table rows into a downloadable file.
 *
 * CSV and XLSX are streamed so a large export does not have to be held in
 * memory all at once. PDF is rendered through a Blade view because a printable
 * document needs a header, a footer and page breaks that a spreadsheet writer
 * cannot express.
 */
class TableExport
{
    /**
     * @param  Collection<int, Column>  $columns
     * @param  Collection<int, mixed>  $rows
     */
    public function __construct(
        protected string $name,
        protected Collection $columns,
        protected Collection $rows,
    ) {}

    public function download(string $format): Response
    {
        return match ($format) {
            'csv' => $this->csv(),
            'xlsx' => $this->xlsx(),
            'pdf' => $this->pdf(),
            default => abort(400, 'Unsupported export format.'),
        };
    }

    protected function filename(string $extension): string
    {
        return sprintf('%s-%s.%s', $this->name, now()->format('Y-m-d-His'), $extension);
    }

    protected function headings(): array
    {
        return $this->columns->map(fn (Column $column) => $column->label())->all();
    }

    protected function body(): Collection
    {
        return $this->rows->map(
            fn ($row) => $this->columns->map(fn (Column $column) => $column->exportValue($row))->all(),
        );
    }

    protected function csv(): StreamedResponse
    {
        $filename = $this->filename('csv');
        $headings = $this->headings();
        $body = $this->body();

        return response()->streamDownload(function () use ($headings, $body) {
            $handle = fopen('php://output', 'wb');

            // Byte order mark so Excel opens UTF-8 names correctly.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headings);

            foreach ($body as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    protected function xlsx(): StreamedResponse
    {
        $filename = $this->filename('xlsx');
        $headings = $this->headings();
        $body = $this->body();

        return response()->streamDownload(function () use ($headings, $body) {
            $options = new XlsxOptions;
            $options->setColumnWidth(22, ...range(1, max(count($headings), 1)));

            $writer = new XlsxWriter($options);
            $writer->openToFile('php://output');

            // OpenSpout styles are immutable, so this is built in one construction
            // rather than mutated.
            $header = new Style(
                fontBold: true,
                fontColor: Color::WHITE,
                backgroundColor: '4F46E5',
            );

            $writer->addRow(Row::fromValuesWithStyle($headings, $header));

            foreach ($body as $row) {
                $writer->addRow(Row::fromValues($row));
            }

            $writer->close();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    protected function pdf(): Response
    {
        $pdf = Pdf::loadView('exports.table', [
            'title' => str($this->name)->headline()->toString(),
            'headings' => $this->headings(),
            'rows' => $this->body(),
            'generatedAt' => now(),
        ]);

        // Wide tables are unreadable in portrait, so anything past six columns
        // turns the page.
        $pdf->setPaper('a4', $this->columns->count() > 6 ? 'landscape' : 'portrait');

        return $pdf->download($this->filename('pdf'));
    }
}
