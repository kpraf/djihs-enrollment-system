<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds a fake workbook with the same layout as the school's
 * ENROLLMENT_ENCODING sheet: two header rows, a SYS/MAN marker row,
 * then one learner per row. Uses made-up data only.
 */
class EncodingWorkbook
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    public static function make(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function learner(array $overrides = []): self
    {
        $this->rows[] = [
            'grade' => 'GRADE 11',
            'last' => 'DELA CRUZ',
            'first' => 'JUAN',
            'middle' => 'SANTOS',
            'type' => 'REGULAR - OLD STUDENT (G10/11/12 DJIHS)',
            'track' => 'ACADEMIC',
            'strand' => 'HUMANITIES AND SOCIAL SCIENCES (HUMSS)',
            'lrn' => 123456789012,
            'birthdate' => '2008-03-15',
            'sex' => 'MALE',
            'weight' => 52,
            'height' => 1.62,
            'ip' => 'NO',
            'ip_group' => null,
            '4ps' => 'NO',
            'house' => '12',
            'street' => 'RIZAL ST.',
            'barangay' => 'DON JOSE',
            'municipality' => 'SANTA ROSA',
            'province' => 'LAGUNA',
            'country' => 'PHIL',
            'zip' => 4026,
            'g_last' => 'DELA CRUZ',
            'g_first' => 'MARIA',
            'g_middle' => 'REYES',
            'g_contact' => 9171234567,
            'encoded' => '2024-07-03',
            'status' => 'ENROLLED',
            'remarks' => null,
            ...$overrides,
        ];

        return $this;
    }

    public function save(?string $path = null, string $sheetName = 'MAIN_ENCODING_SHEET'): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle($sheetName);

        $top = [
            'A' => 'NO.', 'B' => 'GRADE LEVEL TO ENROLL', 'C' => 'FULL NAME', 'D' => "LEARNER'S NAME",
            'G' => 'TYPE OF LEARNER', 'H' => 'TRACK', 'I' => 'STRAND', 'J' => 'LRN', 'K' => "BIRTH DATE\n(mm/dd/yyyy)",
            'L' => 'AGE', 'M' => 'SEX', 'N' => 'FOR SF8', 'P' => 'OTHER DETAILS', 'S' => 'CURRENT ADDRESS',
            'Z' => "LEGAL GUARDIAN'S NAME", 'AD' => "DATE ENCODED\n(mm/dd/yyyy)", 'AE' => 'ENROLLMENTSTATUS', 'AF' => 'REMARKS',
        ];
        $sub = [
            'D' => "LAST NAME\n(Include Name Extension if there is any)", 'E' => 'FIRST NAME', 'F' => 'MIDDLE NAME',
            'N' => "WEIGHT\n(kg)", 'O' => "HEIGHT\n(mtrs)", 'P' => 'BELONGING TO ANY INDIGENOUS PEOPLES (IP) ',
            'Q' => 'IF YES, PLEASE SPECIFY', 'R' => "IS YOUR FAMILY A BENEFICIARY OF 4P'S?",
            'S' => 'HOUSE NO.', 'T' => 'STREET NAME', 'U' => 'BARANGAY', 'V' => 'MUNICIPALITY / CITY', 'W' => 'PROVINCE',
            'X' => 'COUNTRY', 'Y' => 'ZIP CODE', 'Z' => 'LAST NAME', 'AA' => 'FIRST NAME', 'AB' => 'MIDDLE NAME', 'AC' => 'CONTACT NO.',
        ];

        foreach ($top as $col => $text) {
            $sheet->setCellValue($col.'1', $text);
        }
        foreach ($sub as $col => $text) {
            $sheet->setCellValue($col.'2', $text);
        }
        foreach (range(1, 32) as $i) {
            $col = Coordinate::stringFromColumnIndex($i);
            $sheet->setCellValue($col.'3', in_array($col, ['A', 'C', 'L'], true) ? 'SYS' : 'MAN');
        }

        $map = [
            'B' => 'grade', 'D' => 'last', 'E' => 'first', 'F' => 'middle', 'G' => 'type', 'H' => 'track', 'I' => 'strand',
            'J' => 'lrn', 'K' => 'birthdate', 'M' => 'sex', 'N' => 'weight', 'O' => 'height', 'P' => 'ip', 'Q' => 'ip_group',
            'R' => '4ps', 'S' => 'house', 'T' => 'street', 'U' => 'barangay', 'V' => 'municipality', 'W' => 'province',
            'X' => 'country', 'Y' => 'zip', 'Z' => 'g_last', 'AA' => 'g_first', 'AB' => 'g_middle', 'AC' => 'g_contact',
            'AD' => 'encoded', 'AE' => 'status', 'AF' => 'remarks',
        ];

        foreach ($this->rows as $i => $row) {
            $r = $i + 4;
            $sheet->setCellValue("A{$r}", $i + 1);
            foreach ($map as $col => $key) {
                $value = $row[$key];
                if (in_array($key, ['birthdate', 'encoded'], true) && $value !== null) {
                    $value = Date::PHPToExcel(new \DateTime($value));
                }
                $sheet->setCellValue($col.$r, $value);
            }
        }

        // The real workbook pre-fills COUNTRY on blank rows. Imports must skip them.
        foreach (range(count($this->rows) + 4, count($this->rows) + 8) as $r) {
            $sheet->setCellValue("X{$r}", 'PHIL');
        }

        $path ??= tempnam(sys_get_temp_dir(), 'enc').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }
}
