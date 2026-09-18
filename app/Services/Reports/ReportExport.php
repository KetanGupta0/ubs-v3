<?php

namespace App\Services\Reports;

use App\Support\Table\TableExport;
use Symfony\Component\HttpFoundation\Response;

/**
 * A report, as a file.
 *
 * Through the same renderer every list already uses, rather than a second one
 * for reports. A spreadsheet that formats dates differently from the one the
 * clients screen produces is the kind of small inconsistency that makes people
 * stop trusting both.
 */
class ReportExport
{
    public function download(Report $report, ReportWindow $window, string $format): Response
    {
        $export = new TableExport(
            str($report->key())->slug()->toString(),
            $report->columns(),
            $report->rows($window),
        );

        return $export->download($format);
    }
}
