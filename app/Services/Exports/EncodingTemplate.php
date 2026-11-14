<?php

namespace App\Services\Exports;

use App\Models\Strand;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A blank workbook in the school's ENROLLMENT_ENCODING layout, with the
 * same dropdowns, that the importer reads back.
 */
class EncodingTemplate
{
    public function download(): BinaryFileResponse
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('MAIN_ENCODING_SHEET');

        $top = ['A' => 'NO.', 'B' => 'GRADE LEVEL TO ENROLL', 'D' => "LEARNER'S NAME", 'G' => 'TYPE OF LEARNER', 'H' => 'TRACK', 'I' => 'STRAND', 'J' => 'LRN',
            'K' => "BIRTH DATE\n(mm/dd/yyyy)", 'M' => 'SEX', 'N' => 'FOR SF8', 'P' => 'OTHER DETAILS', 'S' => 'CURRENT ADDRESS', 'Z' => "LEGAL GUARDIAN'S NAME",
            'AD' => "DATE ENCODED\n(mm/dd/yyyy)", 'AE' => 'ENROLLMENTSTATUS', 'AF' => 'REMARKS'];
        $sub = ['D' => 'LAST NAME', 'E' => 'FIRST NAME', 'F' => 'MIDDLE NAME', 'N' => "WEIGHT\n(kg)", 'O' => "HEIGHT\n(mtrs)",
            'P' => 'BELONGING TO ANY INDIGENOUS PEOPLES (IP)', 'Q' => 'IF YES, PLEASE SPECIFY', 'R' => "IS YOUR FAMILY A BENEFICIARY OF 4P'S?",
            'S' => 'HOUSE NO.', 'T' => 'STREET NAME', 'U' => 'BARANGAY', 'V' => 'MUNICIPALITY / CITY', 'W' => 'PROVINCE', 'X' => 'COUNTRY', 'Y' => 'ZIP CODE',
            'Z' => 'LAST NAME', 'AA' => 'FIRST NAME', 'AB' => 'MIDDLE NAME', 'AC' => 'CONTACT NO.'];

        foreach ($top as $col => $text) {
            $sheet->setCellValue("{$col}1", $text);
        }
        foreach ($sub as $col => $text) {
            $sheet->setCellValue("{$col}2", $text);
        }
        foreach (['D1:F1', 'N1:O1', 'P1:R1', 'S1:Y1', 'Z1:AC1'] as $range) {
            $sheet->mergeCells($range);
        }
        $sheet->getStyle('A1:AF2')->getFont()->setBold(true);
        $sheet->getStyle('A1:AF2')->getAlignment()->setWrapText(true);
        $sheet->freezePane('D3');

        $strands = Strand::where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn (Strand $s) => $s->track->value === 'academic' ? strtoupper("{$s->name} ({$s->code})") : strtoupper("{$s->code} NCII"));

        $lists = [
            'B' => ['GRADE 11', 'GRADE 12'],
            'G' => ['REGULAR - OLD STUDENT (G10/11/12 DJIHS)', 'REGULAR - NEW STUDENT (INCOMING G11)', 'REGULAR - ALS', 'REGULAR - BALIK-ARAL (DJIHS/NON-DJIHS)',
                'IRREGULAR - BALIK-ARAL (DJIHS/NON-DJIHS)', 'IRREGULAR - REPEATER', 'IRREGULAR - TRANSFEREE (INCOMING G11)', 'IRREGULAR - TRANSFEREE (INCOMING G12)'],
            'H' => ['ACADEMIC', 'TVL'],
            'I' => $strands->all(),
            'M' => ['MALE', 'FEMALE'],
            'P' => ['YES', 'NO'],
            'R' => ['YES', 'NO'],
            'AE' => ['ENROLLED', 'CANCELLED'],
        ];

        // The choices live on a hidden sheet so long lists still work.
        $lookup = $book->createSheet()->setTitle('LISTS');
        $i = 1;
        foreach ($lists as $col => $values) {
            $listCol = Coordinate::stringFromColumnIndex($i++);
            foreach (array_values($values) as $r => $v) {
                $lookup->setCellValue($listCol.($r + 1), $v);
            }
            $validation = new DataValidation;
            $validation->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)
                ->setFormula1("LISTS!\${$listCol}\$1:\${$listCol}\$".count($values));
            $sheet->setDataValidation("{$col}3:{$col}2000", $validation);
        }
        $lookup->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'tpl');
        (new Xlsx($book))->save($path);

        return response()->download($path, 'ENROLLMENT_ENCODING_TEMPLATE.xlsx')->deleteFileAfterSend();
    }
}
