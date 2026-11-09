<?php

namespace App\Domain\Reports;

use App\Domain\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Writes a report to a file. CSV carries raw numbers (machine-readable),
 * XLSX typed cells, PDF formatted text with organization branding.
 * Text cells are neutralized against spreadsheet formula injection.
 */
final class ReportExporter
{
    /** dompdf renders large tables slowly; bigger sets go to CSV/Excel. */
    public const PDF_MAX_ROWS = 2000;

    public function __construct(private readonly TenantContext $tenant) {}

    public function assertExportable(ReportType $type, ReportParameters $p, string $format): void
    {
        if ($format === 'pdf' && $type->count($p) > self::PDF_MAX_ROWS) {
            throw ValidationException::withMessages([
                'format' => 'PDF exports are limited to '.number_format(self::PDF_MAX_ROWS).' rows. Narrow the filters or export to CSV or Excel.',
            ]);
        }
    }

    /** Write the file; returns the number of data rows written. */
    public function write(ReportType $type, ReportParameters $p, string $format, string $path, string $title): int
    {
        $columns = array_values(array_filter(array_map(fn (string $k) => $type->column($k), $p->columns)));

        return match ($format) {
            'csv' => $this->csv($type, $p, $columns, $path),
            'xlsx' => $this->xlsx($type, $p, $columns, $path),
            'pdf' => $this->pdf($type, $p, $columns, $path, $title),
            default => throw new \InvalidArgumentException("Unsupported export format [{$format}]."),
        };
    }

    /** @param list<ReportColumn> $columns */
    private function csv(ReportType $type, ReportParameters $p, array $columns, string $path): int
    {
        $out = fopen($path, 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it correctly
        fputcsv($out, array_map(fn (ReportColumn $c) => $c->label, $columns), escape: '');

        $count = 0;
        foreach ($type->cursor($p) as $row) {
            fputcsv($out, array_map(fn (ReportColumn $c) => $this->raw($c, $row[$c->key] ?? null), $columns), escape: '');
            $count++;
        }
        fclose($out);

        return $count;
    }

    /** @param list<ReportColumn> $columns */
    private function xlsx(ReportType $type, ReportParameters $p, array $columns, string $path): int
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValuesWithStyle(array_map(fn (ReportColumn $c) => $c->label, $columns), new Style(fontBold: true)));

        $count = 0;
        foreach ($type->cursor($p) as $row) {
            $writer->addRow(Row::fromValues(array_map(function (ReportColumn $c) use ($row) {
                $v = $row[$c->key] ?? null;

                return $c->isNumeric() ? ($v === null ? null : round((float) $v, 2)) : $this->safe($v);
            }, $columns)));
            $count++;
        }
        $writer->close();

        return $count;
    }

    /** @param list<ReportColumn> $columns */
    private function pdf(ReportType $type, ReportParameters $p, array $columns, string $path, string $title): int
    {
        $org = $this->tenant->organization();
        $rows = [];
        foreach ($type->cursor($p) as $row) {
            $rows[] = array_map(fn (ReportColumn $c) => $this->display($c, $row[$c->key] ?? null, $org->currency), $columns);
            if (count($rows) > self::PDF_MAX_ROWS) {
                break;
            }
        }

        $pdf = Pdf::loadView('reports.pdf', [
            'organization' => $org->name,
            'title' => $title,
            'reportType' => $type->label(),
            'period' => $p->range->label(),
            'note' => $type->note(),
            'columns' => $columns,
            'rows' => $rows,
            'generatedAt' => CarbonImmutable::now($org->timezone)->format('M j, Y H:i T'),
        ])
            ->setPaper('a4', count($columns) > 6 ? 'landscape' : 'portrait')
            // Embed only the glyphs used — keeps a one-page report around tens of KB instead of ~900 KB.
            ->setOption('isFontSubsettingEnabled', true);

        file_put_contents($path, $pdf->output());

        return count($rows);
    }

    /** CSV: raw values, numbers rounded to cents. */
    private function raw(ReportColumn $c, mixed $v): string
    {
        if ($v === null) {
            return '';
        }

        return $c->isNumeric() ? (string) round((float) $v, 2) : $this->safe($v);
    }

    private function display(ReportColumn $c, mixed $v, string $currency): string
    {
        if ($v === null) {
            return '—';
        }

        return match ($c->type) {
            'currency' => $currency.' '.number_format((float) $v, abs((float) $v) >= 1000 ? 0 : 2),
            'percent' => number_format((float) $v, 1).'%',
            'number' => number_format((float) $v, fmod((float) $v, 1.0) === 0.0 ? 0 : 2),
            default => (string) $v,
        };
    }

    /** Prefix values that a spreadsheet would execute as a formula. */
    private function safe(mixed $v): string
    {
        $s = (string) $v;

        return ! is_numeric($s) && preg_match('/^[=+\-@\t\r]/', $s) === 1 ? "'".$s : $s;
    }
}
