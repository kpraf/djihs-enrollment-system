<?php

namespace App\Services\Import;

use App\Enums\EnrollmentStatus;
use App\Enums\GradeLevel;
use App\Enums\LearnerType;
use App\Enums\Sex;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Strand;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditBuffer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Imports the school's ENROLLMENT_ENCODING workbook.
 *
 * Columns are found by their header text, not by letter, so the import keeps
 * working if someone inserts or reorders a column. Each learner becomes one
 * students row plus one enrollments row for the chosen school year.
 * Re-importing the same file updates records instead of duplicating them.
 */
class EncodingSheetImporter
{
    public const SHEET_NAME = 'MAIN_ENCODING_SHEET';

    /**
     * Field => list of header fragments. A column matches when its combined
     * "GROUP | SUB-HEADER" text contains every fragment.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'grade' => ['GRADE LEVEL'],
        'last_name' => ["LEARNER'S NAME", 'LAST NAME'],
        'first_name' => ["LEARNER'S NAME", 'FIRST NAME'],
        'middle_name' => ["LEARNER'S NAME", 'MIDDLE NAME'],
        'learner_type' => ['TYPE OF LEARNER'],
        'track' => ['TRACK'],
        'strand' => ['STRAND'],
        'lrn' => ['LRN'],
        'birthdate' => ['BIRTH DATE'],
        'sex' => ['SEX'],
        'weight' => ['WEIGHT'],
        'height' => ['HEIGHT'],
        'is_indigenous' => ['INDIGENOUS'],
        'indigenous_group' => ['OTHER DETAILS', 'SPECIFY'],
        'is_4ps' => ["4P'S"],
        'house_number' => ['HOUSE NO'],
        'street' => ['STREET'],
        'barangay' => ['BARANGAY'],
        'municipality' => ['MUNICIPALITY'],
        'province' => ['PROVINCE'],
        'country' => ['COUNTRY'],
        'zip_code' => ['ZIP'],
        'guardian_last_name' => ['GUARDIAN', 'LAST NAME'],
        'guardian_first_name' => ['GUARDIAN', 'FIRST NAME'],
        'guardian_middle_name' => ['GUARDIAN', 'MIDDLE NAME'],
        'guardian_contact' => ['GUARDIAN', 'CONTACT'],
        'encoded_on' => ['DATE ENCODED'],
        'status' => ['ENROLLMENTSTATUS'],
        'remarks' => ['REMARKS'],
    ];

    /** @var list<string> */
    private const REQUIRED = ['grade', 'last_name', 'first_name', 'learner_type', 'strand', 'lrn', 'sex', 'status'];

    /** @var Collection<int, Strand> */
    private Collection $strands;

    /** @var array<string, int> LRN => first row it appeared on */
    private array $seenLrns = [];

    /**
     * Learners and enrollments this file may touch, looked up once before
     * the rows instead of one query per row, and kept current as rows save.
     *
     * @var array<string, Student> LRN => learner
     */
    private array $byLrn = [];

    /** @var array<string, Student> "LAST|FIRST|birthdate" => learner saved without an LRN */
    private array $withoutLrn = [];

    /** @var array<int, Enrollment> student id => enrollment in the school year being imported */
    private array $enrollments = [];

    public function import(string $path, SchoolYear $schoolYear, ?User $actor = null, bool $dryRun = false): ImportResult
    {
        $result = new ImportResult($dryRun);
        $sheet = $this->openSheet($path);
        [$columns, $firstDataRow] = $this->locateColumns($sheet);
        // Worked out once: asking on every row rescans the whole sheet, which
        // made a 3,000-row file take minutes instead of seconds.
        $lastRow = $sheet->getHighestDataRow();
        $this->strands = Strand::all();
        $this->seenLrns = [];

        DB::beginTransaction();

        try {
            $this->loadExisting($sheet, $columns['lrn'], $firstDataRow, $lastRow, $schoolYear);

            // Checking a file is rolled back, so it writes no audit entries;
            // a real import writes them in batches.
            $work = function () use ($sheet, $columns, $firstDataRow, $lastRow, $schoolYear, $actor, $result) {
                for ($row = $firstDataRow; $row <= $lastRow; $row++) {
                    $values = [];
                    foreach ($columns as $field => $column) {
                        $values[$field] = $sheet->getCell($column.$row)->getValue();
                    }

                    if ($this->blank($values['grade']) && $this->blank($values['last_name'])) {
                        continue;
                    }

                    $result->rowsRead++;
                    $this->importRow($row, $values, $schoolYear, $actor, $result);
                }
            };
            $dryRun ? AuditBuffer::skipped($work) : AuditBuffer::batched($work);

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $result;
    }

    private function openSheet(string $path): Worksheet
    {
        // Open the file only as the type its name says, so a renamed text
        // file is refused instead of being guessed at.
        $reader = IOFactory::createReader(strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv' ? IOFactory::READER_CSV : IOFactory::READER_XLSX);
        $reader->setReadDataOnly(true);
        if ($reader instanceof CsvReader) {
            // Read every value as text so LRNs and phone numbers keep their digits.
            $reader->setInputEncoding(CsvReader::GUESS_ENCODING);
            $reader->setValueBinder(new StringValueBinder);

            // A CSV is a single sheet, whatever it is named.
            return $reader->load($path)->getActiveSheet();
        }
        $book = $reader->load($path);

        $sheet = $book->getSheetByName(self::SHEET_NAME);
        if ($sheet === null) {
            throw new InvalidArgumentException('The workbook has no '.self::SHEET_NAME.' sheet.');
        }

        return $sheet;
    }

    /**
     * The sheet uses two header rows: a group label such as "CURRENT ADDRESS"
     * spanning several columns, then the field label under it.
     *
     * @return array{0: array<string, string>, 1: int}
     */
    private function locateColumns(Worksheet $sheet): array
    {
        $lastColumn = $sheet->getHighestDataColumn(1);
        $headers = [];
        $group = '';

        foreach ($sheet->getRowIterator(1, 1)->current()->getCellIterator('A', $lastColumn) as $cell) {
            $column = $cell->getColumn();
            $top = $this->headerText($cell->getValue());
            $sub = $this->headerText($sheet->getCell($column.'2')->getValue());

            // A group label only carries over to columns that have a sub-header.
            if ($top !== '') {
                $group = $top;
            } elseif ($sub === '') {
                $group = '';
            }

            $headers[$column] = trim($group.' | '.$sub, ' |');
        }

        $columns = [];
        foreach (self::COLUMNS as $field => $fragments) {
            foreach ($headers as $column => $text) {
                if (collect($fragments)->every(fn ($f) => str_contains($text, $f))) {
                    $columns[$field] = $column;
                    break;
                }
            }
        }

        $missing = array_diff(self::REQUIRED, array_keys($columns));
        if ($missing !== []) {
            throw new InvalidArgumentException('Could not find these columns in the sheet: '.implode(', ', $missing).'.');
        }

        // Optional columns may be absent; read them as empty.
        foreach (array_keys(self::COLUMNS) as $field) {
            $columns[$field] ??= 'XFD';
        }

        // Row 3 holds SYS/MAN markers in the school's template.
        $marker = strtoupper(trim((string) $sheet->getCell($columns['grade'].'3')->getValue()));
        $firstDataRow = in_array($marker, ['MAN', 'SYS'], true) ? 4 : 3;

        return [$columns, $firstDataRow];
    }

    /**
     * @param  array<string, mixed>  $v
     */
    private function importRow(int $row, array $v, SchoolYear $schoolYear, ?User $actor, ImportResult $result): void
    {
        $grade = $this->grade($v['grade']);
        if ($grade === null) {
            $result->skip($row, 'Unrecognised grade level "'.$v['grade'].'".');

            return;
        }

        // Without both names the learner can't be saved or found again.
        if ($this->name($v['last_name']) === null || $this->name($v['first_name']) === null) {
            $result->skip($row, 'Missing last name or first name.');

            return;
        }

        $learnerType = $this->learnerType((string) $v['learner_type']);
        if ($learnerType === null) {
            $result->skip($row, 'Unrecognised type of learner "'.$v['learner_type'].'".');

            return;
        }

        $sex = match (strtoupper(trim((string) $v['sex']))) {
            'MALE', 'M' => Sex::Male,
            'FEMALE', 'F' => Sex::Female,
            default => null,
        };
        if ($sex === null) {
            $result->skip($row, 'Missing or unrecognised sex.');

            return;
        }

        $strand = $this->strand((string) $v['strand']);
        if ($grade->isSeniorHigh() && $strand === null) {
            $result->skip($row, 'Unrecognised strand "'.$v['strand'].'".');

            return;
        }

        // Strands are for Grades 11 and 12 only.
        if (! $grade->isSeniorHigh() && ($strand !== null || ! $this->blank($v['strand']))) {
            $result->warn($row, "Grade {$grade->value} has no strand. Strand left blank.");
            $strand = null;
        }

        $track = strtoupper(trim((string) $v['track']));
        if ($strand && $track !== '' && $track !== strtoupper($strand->track->value)) {
            $result->warn($row, "Track says {$track} but strand {$strand->code} is {$strand->track->label()}. Used the strand's track.");
        }

        $lrn = $this->lrn($v['lrn'], $row, $result);
        $birthdate = $this->date($v['birthdate']);
        if ($birthdate === null) {
            $result->warn($row, 'No birth date.');
        }

        $studentData = [
            'lrn' => $lrn,
            'last_name' => $this->name($v['last_name']),
            'first_name' => $this->name($v['first_name']),
            'middle_name' => $this->name($v['middle_name']),
            'birthdate' => $birthdate?->toDateString(),
            'sex' => $sex,
            'is_indigenous' => $this->yes($v['is_indigenous']),
            'indigenous_group' => $this->name($v['indigenous_group']),
            'is_4ps_beneficiary' => $this->yes($v['is_4ps']),
            'house_number' => AddressNormalizer::clean($v['house_number']),
            'street' => AddressNormalizer::clean($v['street']),
            'barangay' => AddressNormalizer::clean($v['barangay']),
            'municipality' => AddressNormalizer::municipality($v['municipality']),
            'province' => AddressNormalizer::province($v['province']),
            'zip_code' => $this->zip($v['zip_code']),
            'country' => AddressNormalizer::country($v['country']),
            'guardian_last_name' => $this->name($v['guardian_last_name']),
            'guardian_first_name' => $this->name($v['guardian_first_name']),
            'guardian_middle_name' => $this->name($v['guardian_middle_name']),
            'contact_number' => $this->contact($v['guardian_contact'], $row, $result),
        ];

        if (AddressNormalizer::looksLikeProvince($studentData['municipality'])) {
            $result->warn($row, "Municipality is \"{$studentData['municipality']}\", which is a province.");
        }

        $student = $this->findStudent($studentData, $row, $result);
        if ($student) {
            $keyBefore = $student->lrn === null ? $this->nameKey($student->last_name, $student->first_name, $student->birthdate?->toDateString()) : null;
            $student->update($studentData);
            $result->studentsUpdated++;
        } else {
            $keyBefore = null;
            $student = Student::create($studentData);
            $result->studentsCreated++;
        }
        $this->remember($student, $keyBefore);

        if ($studentData['lrn'] !== null) {
            if (isset($this->seenLrns[$studentData['lrn']])) {
                $result->warn($row, "Same learner as row {$this->seenLrns[$studentData['lrn']]}. This row's details were kept.");
            } else {
                $this->seenLrns[$studentData['lrn']] = $row;
            }
        }

        $encodedOn = $this->date($v['encoded_on']);
        if ($encodedOn === null) {
            $result->warn($row, 'No date encoded.');
        }

        $status = strtoupper(trim((string) $v['status'])) === 'CANCELLED'
            ? EnrollmentStatus::Cancelled
            : EnrollmentStatus::Enrolled;

        $enrollment = $this->enrollments[$student->id] ?? null;
        if ($enrollment === null) {
            $enrollment = new Enrollment;
            $enrollment->student_id = $student->id;
            $enrollment->school_year_id = $schoolYear->id;
        }
        $isNew = ! $enrollment->exists;

        $enrollment->fill([
            'grade_level' => $grade,
            'strand_id' => $strand?->id,
            'learner_type' => $learnerType,
            'is_irregular' => str_starts_with(strtoupper(trim((string) $v['learner_type'])), 'IRREGULAR'),
            'status' => $status,
            'weight_kg' => $this->number($v['weight']),
            'height_m' => $this->height($v['height'], $row, $result),
            'encoded_on' => $encodedOn?->toDateString(),
            'remarks' => $this->text($v['remarks']),
        ]);

        if ($isNew) {
            $enrollment->encoded_by = $actor?->id;
        }

        // Rows in the school's sheet are already final: enrolled or cancelled.
        if ($status === EnrollmentStatus::Cancelled && $enrollment->status_changed_at === null) {
            $enrollment->status_changed_at = $encodedOn ?? now();
            $enrollment->status_changed_by = $actor?->id;
        } elseif ($status === EnrollmentStatus::Enrolled) {
            $enrollment->status_changed_at = null;
            $enrollment->status_changed_by = null;
            $enrollment->status_reason = null;
        }

        $enrollment->save();
        $this->enrollments[$student->id] = $enrollment;
        $isNew ? $result->enrollmentsCreated++ : $result->enrollmentsUpdated++;
    }

    /**
     * Looks up, in a few queries, every existing learner whose LRN is in the
     * file, every learner saved without an LRN, and this school year's
     * enrollments. Runs inside the import's transaction.
     */
    private function loadExisting(Worksheet $sheet, string $lrnColumn, int $firstRow, int $lastRow, SchoolYear $schoolYear): void
    {
        $lrns = [];
        for ($row = $firstRow; $row <= $lastRow; $row++) {
            $digits = preg_replace('/\D/', '', (string) $sheet->getCell($lrnColumn.$row)->getValue());
            if ($digits !== '') {
                $lrns[$digits] = true;
            }
        }

        $this->byLrn = [];
        foreach (array_chunk(array_keys($lrns), 1000) as $chunk) {
            foreach (Student::whereIn('lrn', array_map('strval', $chunk))->get() as $student) {
                $this->byLrn[$student->lrn] = $student;
            }
        }

        $this->withoutLrn = [];
        foreach (Student::whereNull('lrn')->get() as $student) {
            $this->withoutLrn[$this->nameKey($student->last_name, $student->first_name, $student->birthdate?->toDateString())] = $student;
        }

        $this->enrollments = Enrollment::where('school_year_id', $schoolYear->id)->get()->keyBy('student_id')->all();
    }

    private function nameKey(?string $last, ?string $first, ?string $birthdate): string
    {
        return $last."\x1F".$first."\x1F".$birthdate;
    }

    /**
     * Finds the learner this row describes. When the LRN already belongs to
     * someone else, the row keeps no LRN rather than overwriting that learner.
     *
     * @param  array<string, mixed>  $data
     */
    private function findStudent(array &$data, int $row, ImportResult $result): ?Student
    {
        $withoutLrn = $this->withoutLrn[$this->nameKey($data['last_name'], $data['first_name'], $data['birthdate'])] ?? null;

        if ($data['lrn'] !== null) {
            $byLrn = $this->byLrn[$data['lrn']] ?? null;

            if ($byLrn !== null && $this->samePerson($byLrn, $data)) {
                return $byLrn;
            }

            if ($byLrn === null) {
                // A learner first imported without an LRN (because it was
                // missing or clashed) gets it once the sheet is corrected.
                return $withoutLrn;
            }

            $owner = isset($this->seenLrns[$data['lrn']]) ? 'row '.$this->seenLrns[$data['lrn']] : 'an existing learner';
            $result->warn($row, "LRN is already used by {$owner}, who is a different learner. Saved this learner without an LRN. Please correct it.");
            $data['lrn'] = null;
        }

        return $withoutLrn;
    }

    /**
     * Keeps the lookups in step after a row saves its learner, so a later
     * row for the same learner finds them, just as a fresh query would.
     */
    private function remember(Student $student, ?string $keyBefore): void
    {
        if ($keyBefore !== null) {
            unset($this->withoutLrn[$keyBefore]);
        }

        if ($student->lrn !== null) {
            $this->byLrn[$student->lrn] = $student;
        } else {
            $this->withoutLrn[$this->nameKey($student->last_name, $student->first_name, $student->birthdate?->toDateString())] = $student;
        }
    }

    /**
     * Same learner if the birth date matches, or the full name does.
     * This lets a re-import fix a typo in either one.
     *
     * @param  array<string, mixed>  $data
     */
    private function samePerson(Student $student, array $data): bool
    {
        $sameBirthdate = $data['birthdate'] !== null && $student->birthdate?->toDateString() === $data['birthdate'];
        $sameName = $student->last_name === $data['last_name'] && $student->first_name === $data['first_name'];

        return $sameBirthdate || $sameName;
    }

    private function grade(mixed $value): ?GradeLevel
    {
        return preg_match('/(\d{1,2})/', (string) $value, $m) ? GradeLevel::tryFrom((int) $m[1]) : null;
    }

    /**
     * Maps labels such as "IRREGULAR - TRANSFEREE (INCOMING G12)".
     */
    private function learnerType(string $value): ?LearnerType
    {
        $value = strtoupper($value);

        return match (true) {
            str_contains($value, 'OLD STUDENT') => LearnerType::OldStudent,
            str_contains($value, 'NEW STUDENT') => LearnerType::NewStudent,
            str_contains($value, 'BALIK') => LearnerType::BalikAral,
            str_contains($value, 'REPEATER') => LearnerType::Repeater,
            str_contains($value, 'TRANSFEREE') => LearnerType::Transferee,
            (bool) preg_match('/\bALS\b/', $value) => LearnerType::Als,
            default => null,
        };
    }

    /**
     * Matches "HUMANITIES AND SOCIAL SCIENCES (HUMSS)" by the code in
     * brackets, and "IA-EIM NCII" by the code at the start.
     */
    private function strand(string $value): ?Strand
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return null;
        }

        if (preg_match('/\(([A-Z0-9-]+)\)\s*$/', $value, $m)) {
            $byCode = $this->strands->first(fn (Strand $s) => strtoupper($s->code) === $m[1]);
            if ($byCode) {
                return $byCode;
            }
        }

        return $this->strands
            ->sortByDesc(fn (Strand $s) => strlen($s->code))
            ->first(fn (Strand $s) => str_starts_with($value, strtoupper($s->code)) || strtoupper($s->name) === $value);
    }

    private function lrn(mixed $value, int $row, ImportResult $result): ?string
    {
        // Excel saves long numbers in a CSV as 1.60645E+11, losing digits.
        if (is_string($value) && preg_match('/^\s*\d+(\.\d+)?E\+?\d+\s*$/i', $value)) {
            $result->warn($row, "LRN \"{$value}\" was shortened by Excel and can't be recovered. Type the LRN as text and import again. Saved without an LRN.");

            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $value);

        if ($digits === '') {
            $result->warn($row, 'No LRN.');

            return null;
        }

        if (strlen($digits) > 12) {
            $result->warn($row, 'LRN has '.strlen($digits).' digits instead of 12. Saved without an LRN.');

            return null;
        }

        if (strlen($digits) !== 12) {
            $result->warn($row, 'LRN has '.strlen($digits).' digits instead of 12.');
        }

        return $digits;
    }

    /**
     * Excel drops the leading zero of phone numbers typed as numbers.
     */
    private function contact(mixed $value, int $row, ImportResult $result): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '0'.$digits;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '639')) {
            return '0'.substr($digits, 2);
        }

        if (strlen($digits) !== 11 || ! str_starts_with($digits, '09')) {
            $result->warn($row, "Guardian contact number \"{$digits}\" is not an 11-digit mobile number.");
        }

        return substr($digits, 0, 13);
    }

    private function height(mixed $value, int $row, ImportResult $result): ?float
    {
        $height = $this->number($value);

        if ($height === null || ($height >= 0.5 && $height <= 2.5)) {
            return $height;
        }

        // Someone typed centimetres into the metres column.
        if ($height >= 100 && $height <= 250) {
            $result->warn($row, "Height {$height} looks like centimetres. Converted to metres.");

            return round($height / 100, 2);
        }

        $result->warn($row, "Height {$height} is not a valid height in metres. Left blank.");

        return null;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($this->blank($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
        }

        foreach (['m/d/Y', 'Y-m-d', 'm-d-Y', 'F j, Y'] as $format) {
            $date = CarbonImmutable::createFromFormat('!'.$format, trim((string) $value));
            if ($date !== false && $date !== null) {
                return $date;
            }
        }

        return null;
    }

    private function zip(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits === '' ? null : str_pad(substr($digits, 0, 4), 4, '0', STR_PAD_LEFT);
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function yes(mixed $value): bool
    {
        return in_array(strtoupper(trim((string) $value)), ['YES', 'Y', 'TRUE', '1'], true);
    }

    private function name(mixed $value): ?string
    {
        $value = $this->text($value);

        return $value === null ? null : mb_strtoupper($value);
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', trim((string) $value));

        return $value === '' ? null : $value;
    }

    private function headerText(mixed $value): string
    {
        return strtoupper(preg_replace('/\s+/u', ' ', trim((string) $value)));
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}
