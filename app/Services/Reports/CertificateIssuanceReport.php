<?php

namespace App\Services\Reports;

use App\Models\Certificate;
use App\Models\InternshipDocument;
use App\Support\Table\Column;
use Illuminate\Support\Collection;

/**
 * What we have put our name to.
 *
 * Certificates and internship paperwork together, because a college asking
 * "what did you issue our students last term" means both, and answering with
 * one of the two is answering half a question.
 *
 * Withdrawn certificates are counted separately rather than quietly dropped. A
 * document we took back is a thing that happened.
 */
class CertificateIssuanceReport extends Report
{
    public function key(): string
    {
        return 'certificates';
    }

    public function title(): string
    {
        return 'Certificates issued';
    }

    public function description(): string
    {
        return 'Certificates and internship documents, by month and by course.';
    }

    public function permission(): ?string
    {
        return 'students.view';
    }

    public function summary(ReportWindow $window): array
    {
        $certificates = $this->certificates($window);
        $documents = $this->documents($window);

        return [
            ['label' => 'Certificates', 'value' => (string) $certificates->count()],
            ['label' => 'Internship documents', 'value' => (string) $documents->count()],
            [
                'label' => 'Withdrawn',
                'value' => (string) $certificates->whereNotNull('revoked_at')->count(),
                'tone' => $certificates->whereNotNull('revoked_at')->isEmpty() ? 'neutral' : 'warning',
            ],
            [
                'label' => 'Average mark on them',
                'value' => $certificates->whereNotNull('final_percent')->isEmpty()
                    ? '—'
                    : round($certificates->whereNotNull('final_percent')->avg('final_percent')).'%',
            ],
        ];
    }

    public function charts(ReportWindow $window): array
    {
        $months = $window->months();
        $certificates = $this->certificates($window);
        $documents = $this->documents($window);

        $count = fn (Collection $records, string $column) => collect($months)->map(
            fn ($month) => $records->filter(
                fn ($record) => $record->{$column}->format('Y-m') === $month->format('Y-m'),
            )->count(),
        )->all();

        return [[
            'id' => 'issued',
            'kind' => 'stacked',
            'title' => 'Issued by month',
            'subtitle' => 'Certificates and internship paperwork',
            'categories' => collect($months)->map(fn ($month) => $month->format('M Y'))->all(),
            'series' => [
                ['label' => 'Certificates', 'slot' => 1, 'values' => $count($certificates, 'issued_at')],
                ['label' => 'Internship documents', 'slot' => 2, 'values' => $count($documents, 'issued_at')],
            ],
        ]];
    }

    public function columns(): Collection
    {
        return collect([
            Column::make('number', 'Number'),
            Column::make('kind', 'Document'),
            Column::make('holder', 'Issued to'),
            Column::make('course', 'For'),
            Column::make('issued', 'Issued on'),
            Column::make('grade', 'Grade'),
            Column::make('state', 'State'),
        ]);
    }

    public function rows(ReportWindow $window): Collection
    {
        $certificates = $this->certificates($window)->map(fn (Certificate $certificate) => [
            'id' => 'c'.$certificate->id,
            'number' => $certificate->number,
            'kind' => 'Certificate',
            'holder' => $certificate->user?->name ?? '—',
            'course' => $certificate->course?->title ?? $certificate->title,
            'issued' => $certificate->issued_at->format('j M Y'),
            'issuedAt' => $certificate->issued_at->toDateString(),
            'grade' => $certificate->grade ?? '—',
            'state' => $certificate->isValid() ? 'Valid' : 'Withdrawn',
            'withdrawn' => ! $certificate->isValid(),
        ]);

        $documents = $this->documents($window)->map(fn (InternshipDocument $document) => [
            'id' => 'd'.$document->id,
            'number' => $document->number,
            'kind' => $document->kindLabel(),
            'holder' => $document->user?->name ?? '—',
            'course' => $document->course?->title ?? '—',
            'issued' => $document->issued_at->format('j M Y'),
            'issuedAt' => $document->issued_at->toDateString(),
            'grade' => '—',
            'state' => 'Valid',
            'withdrawn' => false,
        ]);

        return $certificates->concat($documents)->sortByDesc('issuedAt')->values();
    }

    protected function certificates(ReportWindow $window): Collection
    {
        return Certificate::query()
            ->with(['user:id,name', 'course:id,title'])
            ->whereBetween('issued_at', [$window->from, $window->to])
            ->get();
    }

    protected function documents(ReportWindow $window): Collection
    {
        return InternshipDocument::query()
            ->with(['user:id,name', 'course:id,title'])
            ->whereBetween('issued_at', [$window->from, $window->to])
            ->get();
    }
}
