<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\LearnerType;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Services\Import\EncodingSheetImporter;
use Database\Seeders\StrandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\EncodingWorkbook;
use Tests\TestCase;

class EncodingSheetImportTest extends TestCase
{
    use RefreshDatabase;

    private SchoolYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(StrandSeeder::class);
        $this->year = SchoolYear::factory()->create(['start_year' => 2024]);
    }

    private function import(string $path, bool $dryRun = false)
    {
        return app(EncodingSheetImporter::class)->import($path, $this->year, dryRun: $dryRun);
    }

    public function test_it_imports_learners_and_enrollments(): void
    {
        $path = EncodingWorkbook::make()
            ->learner()
            ->learner(['lrn' => 123456789013, 'first' => 'ANA', 'sex' => 'FEMALE', 'grade' => 'GRADE 12', 'type' => 'IRREGULAR - TRANSFEREE (INCOMING G12)', 'track' => 'TVL', 'strand' => 'IA-EIM NCII'])
            ->save();

        $result = $this->import($path);

        $this->assertSame(2, $result->rowsRead);
        $this->assertSame(2, $result->enrollmentsCreated);

        $ana = Student::where('first_name', 'ANA')->first()->enrollments->first();
        $this->assertSame(12, $ana->grade_level->value);
        $this->assertSame('IA-EIM', $ana->strand->code);
        $this->assertSame(LearnerType::Transferee, $ana->learner_type);
        $this->assertTrue($ana->is_irregular);
        $this->assertSame('2024-07-03', $ana->encoded_on->toDateString());
    }

    public function test_a_big_file_writes_every_audit_entry_and_a_check_writes_none(): void
    {
        // More rows than one batch of audit entries (200), with a learner
        // repeated and one without an LRN, so the in-memory lookups are used.
        $book = EncodingWorkbook::make();
        for ($i = 0; $i < 260; $i++) {
            $book->learner(['lrn' => 980000000000 + $i, 'first' => 'BULK '.$i]);
        }
        $book->learner(['lrn' => 980000000005, 'first' => 'BULK 5']);
        $book->learner(['lrn' => '', 'first' => 'NO LRN']);
        $path = $book->save();

        $before = AuditLog::count();
        $check = $this->import($path, dryRun: true);
        $this->assertSame(262, $check->rowsRead);
        $this->assertSame(0, Student::count(), 'A check saves nothing.');
        $this->assertSame($before, AuditLog::count(), 'A check writes no audit entries.');

        $result = $this->import($path);
        $this->assertSame(261, $result->studentsCreated, 'The repeated learner is one learner.');
        $this->assertSame(261, Enrollment::count());

        // One "created" entry per learner and per enrollment, plus the
        // repeated row's update, exactly as saving them one by one would.
        $this->assertSame(261, AuditLog::where('auditable_type', (new Student)->getMorphClass())->where('event', 'created')->count());
        $this->assertSame(261, AuditLog::where('auditable_type', (new Enrollment)->getMorphClass())->where('event', 'created')->count());
        $entry = AuditLog::where('auditable_type', (new Student)->getMorphClass())->where('event', 'created')->first();
        $this->assertSame('BULK 0', $entry->new_values['first_name']);
        $this->assertNotNull($entry->created_at);

        // Importing the same file again updates instead of adding.
        $again = $this->import($path);
        $this->assertSame(0, $again->studentsCreated);
        $this->assertSame(261, Student::count());
        $this->assertSame(261, Enrollment::count());
    }

    public function test_it_cleans_values_excel_mangled(): void
    {
        $path = EncodingWorkbook::make()
            ->learner(['g_contact' => 9171234567, 'municipality' => 'STA. ROSA ', 'province' => 'LGUNA', 'country' => 'PHIL', 'zip' => 4026, 'lrn' => 12345678901])
            ->save();

        $result = $this->import($path);
        $student = Student::first();

        $this->assertSame('09171234567', $student->contact_number);
        $this->assertSame('SANTA ROSA', $student->municipality);
        $this->assertSame('LAGUNA', $student->province);
        $this->assertSame('Philippines', $student->country);
        $this->assertSame('12345678901', $student->lrn);
        $this->assertTrue(collect($result->warnings)->contains(fn ($w) => str_contains($w['message'], '11 digits')));
    }

    public function test_reimporting_updates_instead_of_duplicating(): void
    {
        $this->import(EncodingWorkbook::make()->learner()->save());
        $result = $this->import(EncodingWorkbook::make()->learner(['strand' => 'ACCOUNTANCY, BUSINESS AND MANAGEMENT (ABM)', 'remarks' => 'humss to abm'])->save());

        $this->assertSame(0, $result->studentsCreated);
        $this->assertSame(1, $result->enrollmentsUpdated);
        $this->assertSame(1, Enrollment::count());
        $this->assertSame('ABM', Enrollment::first()->strand->code);
    }

    public function test_a_shared_lrn_does_not_merge_two_different_learners(): void
    {
        $path = EncodingWorkbook::make()
            ->learner(['first' => 'JUAN', 'birthdate' => '2008-03-15'])
            ->learner(['first' => 'PEDRO', 'last' => 'GARCIA', 'birthdate' => '2007-01-01'])
            ->save();

        $result = $this->import($path);

        $this->assertSame(2, Student::count());
        $this->assertSame(2, Enrollment::count());
        $this->assertNull(Student::where('first_name', 'PEDRO')->value('lrn'));
        $this->assertTrue(collect($result->warnings)->contains(fn ($w) => $w['row'] === 5 && str_contains($w['message'], 'different learner')));
    }

    public function test_cancelled_rows_are_kept_as_cancelled(): void
    {
        $this->import(EncodingWorkbook::make()->learner(['status' => 'CANCELLED', 'remarks' => 'Confirmed cancelled'])->save());

        $enrollment = Enrollment::first();
        $this->assertSame(EnrollmentStatus::Cancelled, $enrollment->status);
        $this->assertNotNull($enrollment->status_changed_at);
    }

    public function test_rows_with_unknown_values_are_skipped_with_a_reason(): void
    {
        $result = $this->import(EncodingWorkbook::make()->learner(['strand' => 'STEM'])->learner(['lrn' => 123456789099, 'first' => 'B'])->save());

        $this->assertCount(1, $result->skipped);
        $this->assertSame(4, $result->skipped[0]['row']);
        $this->assertSame(1, Enrollment::count());
    }

    public function test_a_row_without_both_names_is_skipped_instead_of_stopping_the_import(): void
    {
        $result = $this->import(EncodingWorkbook::make()
            ->learner(['first' => ''])
            ->learner(['lrn' => 123456789066, 'last' => ''])
            ->learner(['lrn' => 123456789067, 'first' => 'OK'])
            ->save());

        $this->assertSame(['Missing last name or first name.', 'Missing last name or first name.'], array_column($result->skipped, 'message'));
        $this->assertSame(1, Student::count());
    }

    public function test_a_strand_on_a_junior_high_row_is_left_blank(): void
    {
        $result = $this->import(EncodingWorkbook::make()->learner(['grade' => 'GRADE 9', 'track' => 'ACADEMIC', 'strand' => 'HUMANITIES AND SOCIAL SCIENCES (HUMSS)'])->save());

        $this->assertNull(Enrollment::first()->strand_id);
        $this->assertTrue(collect($result->warnings)->contains('message', 'Grade 9 has no strand. Strand left blank.'));
    }

    public function test_an_lrn_with_too_many_digits_is_not_saved(): void
    {
        // The LRN column holds 12 characters; a longer one would stop the import on PostgreSQL.
        $result = $this->import(EncodingWorkbook::make()->learner(['lrn' => '1234567890123'])->save());

        $this->assertNull(Student::first()->lrn);
        $this->assertTrue(collect($result->warnings)->contains('message', 'LRN has 13 digits instead of 12. Saved without an LRN.'));
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $result = $this->import(EncodingWorkbook::make()->learner()->save(), dryRun: true);

        $this->assertSame(1, $result->studentsCreated);
        $this->assertSame(0, Student::count());
    }

    public function test_bad_heights_are_converted_or_left_blank(): void
    {
        $this->import(EncodingWorkbook::make()
            ->learner(['height' => 158])
            ->learner(['lrn' => 123456789055, 'first' => 'B', 'height' => 4.58])
            ->save());

        $this->assertEquals(1.58, Enrollment::orderBy('id')->first()->height_m);
        $this->assertNull(Enrollment::orderByDesc('id')->first()->height_m);
    }

    public function test_a_workbook_without_the_encoding_sheet_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->import(EncodingWorkbook::make()->learner()->save(sheetName: 'Sheet1'));
    }

    public function test_correcting_a_clashing_lrn_later_does_not_create_a_second_learner(): void
    {
        $first = EncodingWorkbook::make()
            ->learner(['first' => 'JUAN', 'birthdate' => '2008-03-15'])
            ->learner(['first' => 'PEDRO', 'last' => 'GARCIA', 'birthdate' => '2007-01-01'])
            ->save();
        $this->import($first);
        $this->assertNull(Student::where('first_name', 'PEDRO')->value('lrn'));

        // The school fixes Pedro's LRN in the sheet and imports again.
        $fixed = EncodingWorkbook::make()
            ->learner(['first' => 'JUAN', 'birthdate' => '2008-03-15'])
            ->learner(['first' => 'PEDRO', 'last' => 'GARCIA', 'birthdate' => '2007-01-01', 'lrn' => 123456789077])
            ->save();
        $this->import($fixed);

        $this->assertSame(2, Student::count());
        $this->assertSame(2, Enrollment::count());
        $this->assertSame('123456789077', Student::where('first_name', 'PEDRO')->value('lrn'));
    }
}
