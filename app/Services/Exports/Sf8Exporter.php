<?php

namespace App\Services\Exports;

use App\Enums\EnrollmentStatus;
use App\Enums\Sex;
use App\Models\Enrollment;
use App\Models\Section;
use App\Services\Health\NutritionalStatus;
use App\Support\ExportName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Fills DepEd School Form 8 (Learner's Basic Health and Nutrition Report)
 * for one section, with the DepEd seal and logo from the template.
 *
 * BMI category and height-for-age are worked out here, from the WHO 2007
 * reference by sex and age in months, and written as plain values: the form
 * reads the same in Excel, on a phone and on paper.
 */
class Sf8Exporter
{
    private const TEMPLATE = 'templates/SF8_Template.xlsx';

    /** Learner rows in the template: boys 12 to 63, girls 65 to 108. More are inserted as needed. */
    private const MALE_FIRST = 12;

    private const MALE_ROWS = 52;

    private const FEMALE_ROWS = 44;

    public const BMI_CATEGORIES = ['Severely Wasted', 'Wasted', 'Normal', 'Overweight', 'Obese'];

    public const HEIGHT_CATEGORIES = ['Severely Stunted', 'Stunted', 'Normal', 'Tall'];

    public function download(Section $section): BinaryFileResponse
    {
        $section->loadMissing(['schoolYear', 'strand', 'adviser']);
        $learners = Enrollment::with('student')
            ->where('section_id', $section->id)
            ->where('status', EnrollmentStatus::Enrolled)
            ->get()
            ->sortBy(fn (Enrollment $e) => [$e->student->last_name, $e->student->first_name])
            ->values();
        [$boys, $girls] = $learners->partition(fn (Enrollment $e) => $e->student->sex === Sex::Male);

        // Weight and height are taken when the learner is encoded.
        $measuredOn = $learners->filter(fn (Enrollment $e) => $e->weight_kg && $e->height_m)->max('encoded_on');
        $measuredOn = CarbonImmutable::parse($measuredOn ?? now())->startOfDay();

        $book = IOFactory::load(resource_path(self::TEMPLATE));
        $sheet = $book->getActiveSheet();
        $school = config('school');

        $sheet->setCellValue('F5', $school['name']);
        // The school's full name is longer than the form's box.
        $sheet->getStyle('F5')->getAlignment()->setShrinkToFit(true)->setWrapText(false);
        $sheet->setCellValue('J5', $school['district']);
        $sheet->setCellValue('M5', $school['division']);
        $sheet->setCellValue('P5', $school['region']);
        $sheet->setCellValueExplicit('D7', (string) $school['school_id'], DataType::TYPE_STRING);
        $sheet->setCellValue('G7', $section->grade_level->value);
        $sheet->setCellValue('I7', $section->name);
        $sheet->setCellValue('M7', $section->strand?->code);
        $sheet->setCellValue('P7', $section->schoolYear->name);

        // Grow each block inside itself, so the rows keep the template's borders.
        $extraBoys = max(0, $boys->count() - self::MALE_ROWS);
        if ($extraBoys > 0) {
            $sheet->insertNewRowBefore(self::MALE_FIRST + self::MALE_ROWS - 1, $extraBoys);
        }
        $femaleFirst = self::MALE_FIRST + self::MALE_ROWS + $extraBoys + 1;
        $extraGirls = max(0, $girls->count() - self::FEMALE_ROWS);
        if ($extraGirls > 0) {
            $sheet->insertNewRowBefore($femaleFirst + self::FEMALE_ROWS - 1, $extraGirls);
        }

        $counts = [];
        foreach ([[Sex::Male, $boys, self::MALE_FIRST], [Sex::Female, $girls, $femaleFirst]] as [$sex, $group, $first]) {
            $counts[$sex->value] = $this->fill($sheet, $group->values(), $first, $measuredOn);
        }

        // Summary table, and the date the measurements were taken.
        $summary = 114 + $extraBoys + $extraGirls;
        $bmiColumns = array_combine(self::BMI_CATEGORIES, ['D', 'F', 'G', 'H', 'I']);
        $heightColumns = array_combine(self::HEIGHT_CATEGORIES, ['K', 'L', 'M', 'N']);
        foreach ([Sex::Male, Sex::Female] as $i => $sex) {
            $r = $summary + $i;
            foreach ($bmiColumns as $category => $col) {
                $sheet->setCellValue($col.$r, $counts[$sex->value]['bmi'][$category] ?? 0);
            }
            $sheet->setCellValue('J'.$r, array_sum($counts[$sex->value]['bmi']));
            foreach ($heightColumns as $category => $col) {
                $sheet->setCellValue($col.$r, $counts[$sex->value]['height'][$category] ?? 0);
            }
            $sheet->setCellValue('O'.$r, array_sum($counts[$sex->value]['height']));
        }
        foreach ([...array_values($bmiColumns), 'J', ...array_values($heightColumns), 'O'] as $col) {
            $sheet->setCellValue($col.($summary + 2), "={$col}{$summary}+{$col}".($summary + 1));
        }
        $signatures = $summary + 5;
        $sheet->setCellValue('B'.$signatures, Date::PHPToExcel($measuredOn));
        $sheet->getStyle('B'.$signatures)->getNumberFormat()->setFormatCode('mm/dd/yyyy');
        $sheet->setCellValue('F'.$signatures, $section->adviser?->name ? mb_strtoupper($section->adviser->name) : null);

        // One sheet, so the first tab is tab 0: any other index makes Excel
        // "repair" the file and drop its merged cells and pictures.
        $book->setFirstSheetIndex(0);
        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'sf8');
        (new Xlsx($book))->save($path);
        $name = ExportName::make('SF8', $section->label(), ExportName::schoolYear($section->schoolYear->name)).'.xlsx';

        return response()->download($path, $name)->deleteFileAfterSend();
    }

    /**
     * @param  Collection<int, Enrollment>  $group
     * @return array{bmi: array<string, int>, height: array<string, int>}
     */
    private function fill(Worksheet $sheet, Collection $group, int $first, CarbonImmutable $measuredOn): array
    {
        $counts = ['bmi' => [], 'height' => []];

        foreach ($group as $i => $e) {
            $s = $e->student;
            $r = $first + $i;
            $weight = $e->weight_kg !== null ? (float) $e->weight_kg : null;
            $height = $e->height_m !== null ? (float) $e->height_m : null;
            $status = NutritionalStatus::assess($s->sex, $s->birthdate, $weight, $height, $measuredOn);

            $sheet->setCellValue('B'.$r, $i + 1);
            $sheet->setCellValueExplicit('C'.$r, (string) $s->lrn, DataType::TYPE_STRING);
            $sheet->setCellValue('D'.$r, mb_strtoupper(trim(implode(' ', array_filter(["{$s->last_name},", $s->first_name, $s->extension_name, $s->middle_name])))));
            if ($s->birthdate) {
                $sheet->setCellValue('H'.$r, Date::PHPToExcel($s->birthdate));
                $sheet->getStyle('H'.$r)->getNumberFormat()->setFormatCode('mm/dd/yyyy');
            }
            if ($status['age_months'] !== null) {
                $sheet->setCellValue('I'.$r, intdiv($status['age_months'], 12).'y '.($status['age_months'] % 12).'m');
            }
            $sheet->setCellValue('J'.$r, $weight);
            $sheet->setCellValue('K'.$r, $height);
            $sheet->getStyle('K'.$r)->getNumberFormat()->setFormatCode('0.00');
            if ($height) {
                $sheet->setCellValue('L'.$r, round($height * $height, 4));
                $sheet->getStyle('L'.$r)->getNumberFormat()->setFormatCode('0.0000');
            }
            $sheet->setCellValue('M'.$r, $status['bmi']);
            $sheet->getStyle('M'.$r)->getNumberFormat()->setFormatCode('0.0');
            $sheet->setCellValue('N'.$r, $status['bmi_category']);
            $sheet->setCellValue('O'.$r, $status['height_for_age']);
            $sheet->setCellValue('P'.$r, $status['note']);

            if ($status['bmi_category']) {
                $counts['bmi'][$status['bmi_category']] = ($counts['bmi'][$status['bmi_category']] ?? 0) + 1;
                $counts['height'][$status['height_for_age']] = ($counts['height'][$status['height_for_age']] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
