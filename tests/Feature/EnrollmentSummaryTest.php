<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\GradeLevel;
use App\Enums\LearnerType;
use App\Enums\Sex;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Strand;
use App\Models\Student;
use App\Models\User;
use App\Services\Reports\EnrollmentSummary;
use Database\Seeders\StrandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_counts_like_the_monitoring_sheet(): void
    {
        $this->seed(StrandSeeder::class);
        $year = SchoolYear::factory()->create(['grade_10_completers' => 4]);
        $humss = Strand::where('code', 'HUMSS')->first();
        $eim = Strand::where('code', 'IA-EIM')->first();

        $enroll = fn (Sex $sex, Strand $strand, LearnerType $type, EnrollmentStatus $status = EnrollmentStatus::Enrolled, array $student = []) => Enrollment::factory()->create([
            'student_id' => Student::factory()->create(['sex' => $sex, 'is_indigenous' => false, 'is_4ps_beneficiary' => false, ...$student]),
            'school_year_id' => $year->id,
            'grade_level' => GradeLevel::Grade11,
            'strand_id' => $strand->id,
            'learner_type' => $type,
            'status' => $status,
            'encoded_on' => '2024-07-03',
        ]);

        $enroll(Sex::Male, $humss, LearnerType::OldStudent, student: ['is_4ps_beneficiary' => true]);
        $enroll(Sex::Female, $humss, LearnerType::OldStudent);
        $enroll(Sex::Female, $humss, LearnerType::Transferee, student: ['is_indigenous' => true]);
        $enroll(Sex::Male, $eim, LearnerType::NewStudent);
        $enroll(Sex::Male, $eim, LearnerType::OldStudent, EnrollmentStatus::Cancelled);

        $summary = app(EnrollmentSummary::class)->monitoring($year);
        $g11 = $summary['grades'][0];
        $humssRow = collect($g11['strands'])->firstWhere('code', 'HUMSS');

        $this->assertSame([1, 2, 3], [$humssRow['male'], $humssRow['female'], $humssRow['total']]);
        $this->assertSame(2, $humssRow['learner_types']['old_student']);
        $this->assertSame(1, $humssRow['indigenous']);
        $this->assertSame(1, $humssRow['four_ps']);
        $this->assertSame(3, $g11['tracks']['academic']['total']);
        $this->assertSame(1, $g11['tracks']['tvl']['total']);
        $this->assertSame(4, $summary['total']['total'], 'Cancelled enrollments are not counted.');
        $this->assertSame(1, $summary['cancelled']);
        $this->assertSame(0.5, $summary['grade_11_retention']);

        $daily = app(EnrollmentSummary::class)->daily($year);
        $this->assertSame(5, $daily[0]['total'], 'Daily counts include cancelled encodings, like the sheet.');
    }

    public function test_the_dashboard_renders_with_data(): void
    {
        $this->seed(StrandSeeder::class);
        $year = SchoolYear::factory()->active()->create();
        Enrollment::factory()->count(3)->create(['school_year_id' => $year->id, 'strand_id' => Strand::first()->id]);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('dashboard')->where('selectedYear.id', $year->id)
                ->missing('monitoring')
                ->loadDeferredProps('monitoring', fn ($r) => $r->has('monitoring.grades')->has('daily')));
    }
}
