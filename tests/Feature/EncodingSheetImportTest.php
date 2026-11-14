<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\LearnerType;
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
        $this->assertNotNull($enrollment->cancelled_at);
    }

    public function test_rows_with_unknown_values_are_skipped_with_a_reason(): void
    {
        $result = $this->import(EncodingWorkbook::make()->learner(['strand' => 'STEM'])->learner(['lrn' => 123456789099, 'first' => 'B'])->save());

        $this->assertCount(1, $result->skipped);
        $this->assertSame(4, $result->skipped[0]['row']);
        $this->assertSame(1, Enrollment::count());
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
