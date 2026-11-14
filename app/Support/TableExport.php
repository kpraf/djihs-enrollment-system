<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a table as CSV or Excel. CSVs start with a byte-order mark so
 * Excel shows names with ñ correctly.
 */
class TableExport
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function csv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => self::safe($v), $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A multi-table report as Excel (one sheet per table) or as one CSV with
     * the tables stacked, each under its title.
     *
     * @param  array<string, array{0: list<string>, 1: iterable<array<int, mixed>>}>  $sheets  title => [headers, rows]
     */
    public static function report(string $format, string $basename, array $sheets): Response
    {
        if ($format !== 'csv') {
            return self::xlsx($basename.'.xlsx', $sheets);
        }

        return response()->streamDownload(function () use ($sheets) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $first = true;
            foreach ($sheets as $title => [$headers, $rows]) {
                if (! $first) {
                    fputcsv($out, []);
                }
                $first = false;
                fputcsv($out, [self::safe($title)]);
                fputcsv($out, $headers);
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn ($v) => self::safe($v), array_values($row)));
                }
            }
            fclose($out);
        }, $basename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, array{0: list<string>, 1: iterable<array<int, mixed>>}>  $sheets  title => [headers, rows]
     */
    public static function xlsx(string $filename, array $sheets): BinaryFileResponse
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        foreach ($sheets as $title => [$headers, $rows]) {
            $sheet = $book->createSheet()->setTitle(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $title), 0, 31));
            $sheet->fromArray($headers, null, 'A1');
            $sheet->getStyle('1:1')->getFont()->setBold(true);
            $r = 2;
            foreach ($rows as $row) {
                $sheet->fromArray(array_map(fn ($v) => self::safe($v), array_values($row)), null, 'A'.$r++, true);
            }
            foreach (range(1, count($headers)) as $i) {
                $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
            }
            $sheet->freezePane('A2');
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($book))->save($path);

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    /**
     * Stops spreadsheet formula injection: a cell starting with = + - @ is
     * shown as text, not run as a formula.
     */
    private static function safe(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) && ! is_numeric($value)) {
            return "'".$value;
        }

        return $value;
    }
}
