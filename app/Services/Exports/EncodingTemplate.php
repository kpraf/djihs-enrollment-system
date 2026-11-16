<?php

namespace App\Services\Exports;

use App\Models\Strand;
use App\Services\Import\TemplateGuide;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A blank workbook in the school's ENROLLMENT_ENCODING layout, with the
 * same dropdowns, that the importer reads back.
 */
class EncodingTemplate
{
    /**
     * A to AF are the school's own ENROLLMENT_ENCODING layout, so rows copied
     * from that workbook paste straight in. AG onwards were added for SF1.
     */
    private const LAST_COLUMN = 'AM';

    /** Rows that get the FULL NAME and AGE formulas. */
    private const FORMULA_ROWS = 1000;

    public function download(): BinaryFileResponse
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('MAIN_ENCODING_SHEET');

        [$top, $sub] = self::headers();

        foreach ($top as $col => $text) {
            $sheet->setCellValue("{$col}1", $text);
        }
        foreach ($sub as $col => $text) {
            $sheet->setCellValue("{$col}2", $text);
        }
        foreach (['D1:F1', 'N1:O1', 'P1:R1', 'S1:Y1', 'Z1:AC1', 'AG1:AI1', 'AJ1:AL1'] as $range) {
            $sheet->mergeCells($range);
        }
        $sheet->getStyle('A1:'.self::LAST_COLUMN.'2')->getFont()->setBold(true);
        $sheet->getStyle('A1:'.self::LAST_COLUMN.'2')->getAlignment()->setWrapText(true);
        $sheet->freezePane('D3');

        $strands = Strand::where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn (Strand $s) => $s->track->value === 'academic' ? strtoupper("{$s->name} ({$s->code})") : strtoupper("{$s->code} NCII"));

        $lists = [
            // A Grade 7 to 12 school. Track and strand stay blank for Grades 7 to 10.
            'B' => ['GRADE 7', 'GRADE 8', 'GRADE 9', 'GRADE 10', 'GRADE 11', 'GRADE 12'],
            'G' => ['REGULAR - OLD STUDENT (DJIHS)', 'REGULAR - NEW STUDENT', 'REGULAR - ALS', 'REGULAR - BALIK-ARAL (DJIHS/NON-DJIHS)',
                'IRREGULAR - BALIK-ARAL (DJIHS/NON-DJIHS)', 'IRREGULAR - REPEATER', 'IRREGULAR - TRANSFEREE'],
            'H' => ['ACADEMIC', 'TVL'],
            'I' => $strands->all(),
            'M' => ['MALE', 'FEMALE'],
            'P' => ['YES', 'NO'],
            'R' => ['YES', 'NO'],
            'AE' => ['ENROLLED', 'CANCELLED'],
        ];

        // The choices live on a hidden sheet so long lists still work.
        $lookup = $book->createSheet()->setTitle('LISTS');
        $listRanges = [];
        $i = 1;
        foreach ($lists as $col => $values) {
            $listCol = Coordinate::stringFromColumnIndex($i++);
            foreach (array_values($values) as $r => $v) {
                $lookup->setCellValue($listCol.($r + 1), $v);
            }
            $listRanges[$col] = "LISTS!\${$listCol}\$1:\${$listCol}\$".count($values);
        }
        $lookup->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        // Every column shows a short tip when one of its cells is selected,
        // and the columns with set choices also get a dropdown.
        foreach (TemplateGuide::columns() as $guide) {
            $col = $guide['column'];
            $validation = new DataValidation;
            if (isset($listRanges[$col])) {
                $validation->setType(DataValidation::TYPE_LIST)->setShowDropDown(true)->setFormula1($listRanges[$col]);
            }
            $validation->setAllowBlank(true)->setShowInputMessage(true)
                ->setPromptTitle(mb_substr("{$col}: ".self::shortHeader($guide['header']), 0, 32))
                ->setPrompt(mb_substr($guide['tip'], 0, 255));
            $sheet->setDataValidation("{$col}3:{$col}2000", $validation);
        }

        // Kept as text so Excel never drops a leading zero or shortens an LRN.
        foreach (['J', 'AC'] as $col) {
            $sheet->getStyle("{$col}3:{$col}2000")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }

        // FULL NAME and AGE fill in by themselves as a row is typed.
        for ($r = 3; $r <= self::FORMULA_ROWS + 2; $r++) {
            $sheet->setCellValue("C{$r}", "=IF(D{$r}=\"\",\"\",TRIM(D{$r}&\", \"&E{$r}&\" \"&F{$r}))");
            $sheet->setCellValue("L{$r}", "=IF(K{$r}=\"\",\"\",IFERROR(DATEDIF(K{$r},TODAY(),\"Y\"),\"\"))");
        }
        // Shaded grey, header included, so they read as "not for typing".
        foreach (['C', 'L'] as $col) {
            $sheet->getStyle("{$col}3:{$col}".(self::FORMULA_ROWS + 2))->getFont()->getColor()->setRGB('475569');
            $sheet->getStyle("{$col}1:{$col}".(self::FORMULA_ROWS + 2))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
            $sheet->getStyle("{$col}2")->getFont()->setBold(false)->setItalic(true);
        }
        self::guideSheet($book);
        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'tpl');
        (new Xlsx($book))->save($path);

        return response()->download($path, 'ENROLLMENT_ENCODING_TEMPLATE.xlsx')->deleteFileAfterSend();
    }

    /**
     * A readable HOW TO FILL IN tab, second after the encoding sheet. The
     * importer reads MAIN_ENCODING_SHEET by name, so this tab is ignored.
     */
    private static function guideSheet(Spreadsheet $book): void
    {
        $guide = $book->createSheet(1)->setTitle('HOW TO FILL IN');
        $guide->getTabColor()->setRGB('085019');
        $row = 1;

        $guide->setCellValue("A{$row}", 'HOW TO FILL IN THE ENROLLMENT ENCODING TEMPLATE');
        $guide->getStyle("A{$row}")->getFont()->setBold(true)->setSize(14);
        $row++;
        $guide->setCellValue("A{$row}", 'Type learners on the MAIN_ENCODING_SHEET tab, from row 3. Selecting a cell there also shows a short tip for its column.');
        $row += 2;

        $section = function (string $title) use ($guide, &$row) {
            $guide->setCellValue("A{$row}", $title);
            $guide->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
            $row++;
        };
        $list = function (string $heading, array $items, string $color) use ($guide, &$row) {
            $guide->setCellValue("A{$row}", $heading);
            $guide->getStyle("A{$row}")->getFont()->setBold(true)->getColor()->setRGB($color);
            $row++;
            foreach ($items as $item) {
                // Not wrapped or merged: the line runs across the empty cells beside it.
                $guide->setCellValue("B{$row}", '•  '.$item);
                $row++;
            }
        };

        $status = TemplateGuide::status();
        $section('ENROLLMENTSTATUS (column AE)');
        $list('DO', $status['do'], '166534');
        $list("DON'T", $status['dont'], 'B91C1C');
        $row++;

        $general = TemplateGuide::general();
        $section('THE WHOLE SHEET');
        $list('DO', $general['do'], '166534');
        $list("DON'T", $general['dont'], 'B91C1C');
        $row++;

        $section('COLUMN BY COLUMN');
        $head = $row;
        foreach (['COLUMN', 'HEADER', 'NEEDED?', 'WHAT TO TYPE', 'EXAMPLE', 'WHAT NOT TO DO'] as $i => $text) {
            $guide->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$row, $text);
        }
        $guide->getStyle("A{$row}:F{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $guide->getStyle("A{$row}:F{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('085019');
        $row++;

        $needs = [TemplateGuide::REQUIRED => 'REQUIRED', TemplateGuide::OPTIONAL => 'Optional', TemplateGuide::SENIOR_HIGH => 'Grades 11-12', TemplateGuide::AUTOMATIC => 'Automatic'];
        foreach (TemplateGuide::columns() as $c) {
            $guide->setCellValue("A{$row}", $c['column']);
            $guide->setCellValue("B{$row}", $c['header']);
            $guide->setCellValue("C{$row}", $needs[$c['need']]);
            $guide->setCellValue("D{$row}", $c['enter']);
            // Kept as text, so an example such as 09171234567 reads exactly as typed.
            $guide->setCellValueExplicit("E{$row}", $c['example'], DataType::TYPE_STRING);
            $guide->setCellValue("F{$row}", $c['avoid']);
            if (in_array($c['need'], [TemplateGuide::REQUIRED, TemplateGuide::SENIOR_HIGH], true)) {
                $guide->getStyle("C{$row}")->getFont()->setBold(true)->getColor()->setRGB('B91C1C');
            }
            $row++;
        }
        $guide->getStyle("A{$head}:F".($row - 1))->getAlignment()->setVertical('top')->setWrapText(true);
        $guide->setAutoFilter("A{$head}:F".($row - 1));

        foreach (['A' => 9, 'B' => 30, 'C' => 11, 'D' => 46, 'E' => 26, 'F' => 52] as $col => $width) {
            $guide->getColumnDimension($col)->setWidth($width);
        }
    }

    /** The field part of a guide header, such as "LAST NAME" for "LEARNER'S NAME: LAST NAME". */
    private static function shortHeader(string $header): string
    {
        return trim(str_contains($header, ':') ? substr($header, strrpos($header, ':') + 1) : $header);
    }

    /**
     * The same two header rows as the Excel template, for people who
     * prefer CSV. Dropdowns can't be kept in a CSV, so values must be typed
     * exactly as in the workbook.
     */
    public function csv(): StreamedResponse
    {
        [$top, $sub] = self::headers();
        $columns = array_map(
            fn (int $i) => Coordinate::stringFromColumnIndex($i),
            range(1, Coordinate::columnIndexFromString(self::LAST_COLUMN)),
        );

        return response()->streamDownload(function () use ($top, $sub, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(fn ($c) => $top[$c] ?? '', $columns));
            fputcsv($out, array_map(fn ($c) => $sub[$c] ?? '', $columns));
            fclose($out);
        }, 'ENROLLMENT_ENCODING_TEMPLATE.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>} top row and sub-header row, by column
     */
    private static function headers(): array
    {
        // C (FULL NAME) and L (AGE) are worked out from other columns, as in the
        // school's workbook; the importer reads the name and birth date instead.
        $top = ['A' => 'NO.', 'B' => 'GRADE LEVEL TO ENROLL', 'C' => 'FULL NAME', 'D' => "LEARNER'S NAME", 'G' => 'TYPE OF LEARNER', 'H' => 'TRACK', 'I' => 'STRAND', 'J' => 'LRN',
            'K' => "BIRTH DATE\n(mm/dd/yyyy)", 'L' => 'AGE', 'M' => 'SEX', 'N' => 'FOR SF8', 'P' => 'OTHER DETAILS', 'S' => 'CURRENT ADDRESS', 'Z' => "LEGAL GUARDIAN'S NAME",
            'AD' => "DATE ENCODED\n(mm/dd/yyyy)", 'AE' => 'ENROLLMENTSTATUS', 'AF' => 'REMARKS',
            'AG' => "FATHER'S NAME", 'AJ' => "MOTHER'S MAIDEN NAME", 'AM' => "LEGAL GUARDIAN'S RELATIONSHIP TO LEARNER"];
        $sub = ['C' => '(fills in by itself)', 'L' => '(fills in by itself)', 'D' => 'LAST NAME', 'E' => 'FIRST NAME', 'F' => 'MIDDLE NAME', 'N' => "WEIGHT\n(kg)", 'O' => "HEIGHT\n(mtrs)",
            'P' => 'BELONGING TO ANY INDIGENOUS PEOPLES (IP)', 'Q' => 'IF YES, PLEASE SPECIFY', 'R' => "IS YOUR FAMILY A BENEFICIARY OF 4P'S?",
            'S' => 'HOUSE NO.', 'T' => 'STREET NAME', 'U' => 'BARANGAY', 'V' => 'MUNICIPALITY / CITY', 'W' => 'PROVINCE', 'X' => 'COUNTRY', 'Y' => 'ZIP CODE',
            'Z' => 'LAST NAME', 'AA' => 'FIRST NAME', 'AB' => 'MIDDLE NAME', 'AC' => 'CONTACT NO.',
            'AG' => 'LAST NAME', 'AH' => 'FIRST NAME', 'AI' => 'MIDDLE NAME', 'AJ' => 'LAST NAME', 'AK' => 'FIRST NAME', 'AL' => 'MIDDLE NAME'];

        return [$top, $sub];
    }
}
