<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\Role;
use App\Enums\Sex;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Strand;
use App\Models\Student;
use App\Models\User;
use App\Services\Import\EncodingSheetImporter;
use Database\Seeders\StrandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

class TeacherPagesAndExportsTest extends TestCase
{
    use RefreshDatabase;

    private SchoolYear $year;

    private User $adviser;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(StrandSeeder::class);
        $this->year = SchoolYear::factory()->active()->create(['start_year' => 2026]);
        $this->adviser = User::factory()->role(Role::Adviser)->create();
        $this->section = Section::create(['school_year_id' => $this->year->id, 'grade_level' => 11, 'strand_id' => Strand::where('code', 'HUMSS')->value('id'), 'name' => 'Integrity', 'capacity' => 80, 'adviser_id' => $this->adviser->id]);
    }

    private function seat(int $n, array $student = []): void
    {
        Enrollment::factory()->count($n)->enrolled()->create([
            'school_year_id' => $this->year->id, 'grade_level' => 11, 'strand_id' => $this->section->strand_id, 'section_id' => $this->section->id,
            'student_id' => fn () => Student::factory()->create($student),
        ]);
    }

    private function download($response): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dl');
        file_put_contents($path, $response->streamedContent() ?: file_get_contents($response->baseResponse->getFile()->getPathname()));

        return $path;
    }

    public function test_advisers_see_their_own_section(): void
    {
        $this->seat(3);

        $this->actingAs($this->adviser)->get('/my-section')
            ->assertInertia(fn ($p) => $p->component('my-section/index')->has('sections', 1)->has('selected.learners', 3));

        $other = User::factory()->role(Role::Adviser)->create();
        $this->actingAs($other)->get('/my-section')->assertInertia(fn ($p) => $p->has('sections', 0)->where('selected', null));
        $this->actingAs($other)->get("/sections/{$this->section->id}/sf1")->assertForbidden();
        $this->actingAs($other)->get("/sections/{$this->section->id}/export/csv")->assertForbidden();
    }

    public function test_section_lists_export_as_csv_and_excel(): void
    {
        $this->seat(2);

        $csv = $this->actingAs($this->adviser)->get("/sections/{$this->section->id}/export/csv")->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF#,LRN", $csv);
        $this->assertSame(3, substr_count(trim($csv), "\n") + 1);

        $xlsx = $this->actingAs($this->adviser)->get("/sections/{$this->section->id}/export/xlsx")->assertOk();
        $sheet = IOFactory::load($xlsx->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame('Student Name', $sheet->getCell('C1')->getValue());
        $this->assertNotEmpty($sheet->getCell('C3')->getValue());
    }

    public function test_sf1_fills_the_deped_template_and_grows_past_41_learners(): void
    {
        $this->seat(30, ['sex' => Sex::Male, 'is_4ps_beneficiary' => true]);
        $this->seat(15, ['sex' => Sex::Female]);
        Enrollment::factory()->create(['school_year_id' => $this->year->id, 'grade_level' => 11, 'strand_id' => $this->section->strand_id, 'section_id' => $this->section->id, 'status' => EnrollmentStatus::Pending]);

        $response = $this->actingAs($this->adviser)->get("/sections/{$this->section->id}/sf1")->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();

        $this->assertSame('Don Jose Integrated High School', $sheet->getCell('F6')->getValue());
        $this->assertSame('2026-2027', $sheet->getCell('O6')->getValue());
        $this->assertSame('Integrity', $sheet->getCell('V6')->getValue());
        $this->assertMatchesRegularExpression('/^\d{12}$/', (string) $sheet->getCell('B10')->getValue());
        $this->assertSame('M', $sheet->getCell('G10')->getValue(), 'Males are listed first.');
        $this->assertStringContainsString('CCT', (string) $sheet->getCell('Y10')->getValue());
        $this->assertSame('F', $sheet->getCell('G54')->getValue(), '45 learners fill rows 10 to 54.');
        // Four extra rows were inserted, so the totals moved from row 53 to 57.
        $this->assertSame(30, $sheet->getCell('R57')->getValue());
        $this->assertSame(15, $sheet->getCell('R58')->getValue());
        $this->assertSame(45, $sheet->getCell('R59')->getValue(), 'Pending learners are not on SF1.');
    }

    public function test_subject_teachers_see_and_export_only_their_classes(): void
    {
        $this->seat(4);
        $teacher = User::factory()->role(Role::SubjectTeacher)->create();
        $assignment = $this->section->teacherAssignments()->create(['user_id' => $teacher->id, 'subject_name' => 'General Mathematics']);

        $this->actingAs($teacher)->get('/my-students')->assertInertia(fn ($p) => $p->has('classes', 1)->has('selected.learners', 4));
        $this->actingAs($teacher)->get("/my-students/{$assignment->id}/export")->assertOk();
        $this->actingAs(User::factory()->role(Role::SubjectTeacher)->create())->get("/my-students/{$assignment->id}/export")->assertForbidden();
    }

    public function test_the_blank_template_imports_back_correctly(): void
    {
        $registrar = User::factory()->role(Role::Registrar)->create();
        $file = $this->actingAs($registrar)->get('/imports/template')->assertOk()->baseResponse->getFile()->getPathname();

        $book = IOFactory::load($file);
        $sheet = $book->getSheetByName('MAIN_ENCODING_SHEET');
        $row = ['B' => 'GRADE 11', 'D' => 'REYES', 'E' => 'ANA', 'G' => 'REGULAR - NEW STUDENT (INCOMING G11)', 'H' => 'ACADEMIC',
            'I' => 'HUMANITIES AND SOCIAL SCIENCES (HUMSS)', 'J' => 123456789012, 'M' => 'FEMALE', 'AE' => 'ENROLLED', 'AC' => 9171234567];
        foreach ($row as $col => $v) {
            $sheet->setCellValue("{$col}3", $v);
        }
        $this->assertSame('list', $sheet->getCell('B3')->getDataValidation()->getType());
        $copy = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($copy);

        $result = app(EncodingSheetImporter::class)->import($copy, $this->year);
        $this->assertSame(1, $result->enrollmentsCreated);
        $this->assertSame('HUMSS', Enrollment::where('school_year_id', $this->year->id)->latest('id')->first()->strand->code);
    }

    public function test_backup_has_every_table_and_no_passwords(): void
    {
        $this->seat(1);
        $admin = User::factory()->role(Role::Admin)->create();

        $file = $this->actingAs($admin)->get('/backup')->assertOk()->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $zip->open($file);
        $this->assertNotFalse($zip->locateName('students.csv'));
        $this->assertNotFalse($zip->locateName('enrollments.csv'));
        $users = $zip->getFromName('users.csv');
        $columns = str_getcsv(ltrim(strtok($users, "\n"), "\xEF\xBB\xBF"));
        $this->assertNotContains('password', $columns);
        $this->assertNotContains('remember_token', $columns);
        $this->assertStringNotContainsString('$2y$', $users);

        $this->actingAs(User::factory()->role(Role::Registrar)->create())->get('/backup')->assertForbidden();
    }

    public function test_user_and_audit_downloads(): void
    {
        $admin = User::factory()->role(Role::Admin)->create(['username' => 'boss']);

        $users = $this->actingAs($admin)->get('/users/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('boss', $users);
        $this->assertStringNotContainsString('$2y$', $users);

        $audit = $this->actingAs($admin)->get('/audit-log/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Timestamp,User,Action', $audit);
    }

    public function test_spreadsheet_formulas_in_names_are_neutralised(): void
    {
        $this->seat(1, ['last_name' => '=HYPERLINK("http://evil")', 'first_name' => 'X']);

        $csv = $this->actingAs($this->adviser)->get("/sections/{$this->section->id}/export/csv")->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }
}
