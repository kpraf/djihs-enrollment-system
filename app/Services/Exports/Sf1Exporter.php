<?php

namespace App\Services\Exports;

use App\Enums\EnrollmentStatus;
use App\Enums\LearnerType;
use App\Enums\Sex;
use App\Models\Enrollment;
use App\Models\Section;
use App\Support\ExportName;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Fills DepEd School Form 1 (School Register) from the template the legacy
 * system used, for one section. Same cell positions as the legacy export.
 */
class Sf1Exporter
{
    private const TEMPLATE = 'templates/SF1_Template.xlsx';

    private const FIRST_ROW = 10;

    /** The template has rows 10 to 50 for learners; more rows are inserted as needed. */
    private const TEMPLATE_ROWS = 41;

    public function download(Section $section): BinaryFileResponse
    {
        $section->loadMissing(['schoolYear', 'strand']);
        $learners = Enrollment::with('student')
            ->where('section_id', $section->id)
            ->where('status', EnrollmentStatus::Enrolled)
            ->get()
            ->sortBy(fn (Enrollment $e) => [$e->student->sex === Sex::Male ? 0 : 1, $e->student->last_name, $e->student->first_name])
            ->values();

        $book = IOFactory::load(resource_path(self::TEMPLATE));
        $sheet = $book->getActiveSheet();
        $school = config('school');

        $sheet->setCellValue('F4', $school['school_id']);
        $sheet->setCellValue('J4', $school['region']);
        $sheet->setCellValue('M4', $school['division']);
        $sheet->setCellValue('S4', $school['division']);
        $sheet->setCellValue('F6', $school['name']);
        $sheet->setCellValue('O6', $section->schoolYear->name);
        $sheet->setCellValue('S6', $section->grade_level->label().($section->strand ? ' - '.$section->strand->code : ''));
        $sheet->setCellValue('V6', $section->name);

        $extra = max(0, $learners->count() - self::TEMPLATE_ROWS);
        if ($extra > 0) {
            $sheet->insertNewRowBefore(self::FIRST_ROW + self::TEMPLATE_ROWS, $extra);
        }

        // DepEd computes age as of the first Friday of June of the school year.
        $ageDate = new CarbonImmutable('first friday of june '.$section->schoolYear->start_year);
        $male = 0;

        foreach ($learners as $i => $e) {
            $s = $e->student;
            $r = self::FIRST_ROW + $i;
            $s->sex === Sex::Male && $male++;

            $sheet->setCellValueExplicit('B'.$r, (string) $s->lrn, DataType::TYPE_STRING);
            $sheet->setCellValue('C'.$r, mb_strtoupper(trim("{$s->last_name}, {$s->first_name} ".($s->middle_name ?? '').' '.($s->extension_name ?? ''))));
            $sheet->setCellValue('G'.$r, $s->sex === Sex::Male ? 'M' : 'F');
            if ($s->birthdate) {
                $sheet->setCellValue('H'.$r, $s->birthdate->format('m/d/Y'));
                $sheet->setCellValue('I'.$r, (int) $s->birthdate->diffInYears($ageDate));
            }
            $sheet->setCellValue('J'.$r, $s->province);
            $sheet->setCellValue('K'.$r, $s->mother_tongue);
            $sheet->setCellValue('L'.$r, $s->is_indigenous ? ($s->indigenous_group ?: 'Yes') : null);
            $sheet->setCellValue('M'.$r, $s->religion);
            $sheet->setCellValue('N'.$r, trim(($s->house_number ?? '').' '.($s->street ?? '')));
            $sheet->setCellValue('O'.$r, $s->barangay);
            $sheet->setCellValue('P'.$r, $s->municipality);
            $sheet->setCellValue('Q'.$r, $s->province);
            if ($s->father_first_name) {
                $sheet->setCellValue('R'.$r, $s->father_last_name === $s->last_name ? $s->father_first_name : trim("{$s->father_first_name} {$s->father_last_name}"));
            }
            $sheet->setCellValue('T'.$r, trim(implode(' ', array_filter([$s->mother_first_name, $s->mother_middle_name, $s->mother_last_name]))) ?: null);
            $sheet->setCellValue('V'.$r, trim(implode(' ', array_filter([$s->guardian_first_name, $s->guardian_middle_name, $s->guardian_last_name]))) ?: null);
            $sheet->setCellValue('W'.$r, $s->guardian_relationship);
            $sheet->setCellValueExplicit('X'.$r, (string) $s->contact_number, DataType::TYPE_STRING);
            $sheet->setCellValue('Y'.$r, $this->remarks($e));
        }

        $female = $learners->count() - $male;
        $totals = 53 + $extra;
        foreach (['R', 'S'] as $col) {
            $sheet->setCellValue($col.$totals, $male);
            $sheet->setCellValue($col.($totals + 1), $female);
            $sheet->setCellValue($col.($totals + 2), $male + $female);
        }

        $path = tempnam(sys_get_temp_dir(), 'sf1');
        (new Xlsx($book))->save($path);
        $name = ExportName::make('SF1', $section->label(), ExportName::schoolYear($section->schoolYear->name)).'.xlsx';

        return response()->download($path, $name)->deleteFileAfterSend();
    }

    /**
     * Legacy remark codes: T/I transferee, B/A balik-aral, LWD with
     * disability, CCT 4Ps, plus the enrollment's own remarks.
     */
    private function remarks(Enrollment $e): string
    {
        $codes = match ($e->learner_type) {
            LearnerType::Transferee => ['T/I'],
            LearnerType::BalikAral => ['B/A'],
            LearnerType::Repeater => ['R'],
            LearnerType::Als => ['ALS'],
            default => [],
        };
        if ($e->student->is_pwd) {
            $codes[] = 'LWD'.($e->student->disability ? " ({$e->student->disability})" : '');
        }
        if ($e->student->is_4ps_beneficiary) {
            $codes[] = 'CCT';
        }
        if ($e->remarks) {
            $codes[] = $e->remarks;
        }

        return implode(', ', $codes);
    }
}
