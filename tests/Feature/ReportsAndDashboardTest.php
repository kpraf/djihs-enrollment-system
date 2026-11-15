<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\Role;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Strand;
use App\Models\Student;
use App\Models\User;
use App\Services\Reports\AnalyticsReport;
use App\Services\Reports\MetricsReport;
use Database\Seeders\StrandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsAndDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_follow_learners_across_years(): void
    {
        $this->seed(StrandSeeder::class);
        $strand = Strand::first()->id;
        $last = SchoolYear::factory()->create(['start_year' => 2025]);
        $now = SchoolYear::factory()->active()->create(['start_year' => 2026, 'grade_10_completers' => 4]);
        $enrol = fn (SchoolYear $y, Student $s, int $grade, EnrollmentStatus $status = EnrollmentStatus::Enrolled) => Enrollment::factory()->create([
            'school_year_id' => $y->id, 'student_id' => $s->id, 'grade_level' => $grade, 'strand_id' => $strand, 'status' => $status,
        ]);

        // Four Grade 11 learners last year: two promoted, one repeating, one gone.
        [$a, $b, $c, $d] = Student::factory()->count(4)->create();
        foreach ([$a, $b, $c, $d] as $s) {
            $enrol($last, $s, 11);
        }
        $enrol($now, $a, 12);
        $enrol($now, $b, 12);
        $enrol($now, $c, 11);
        // Two new Grade 11 learners, one of whom drops out.
        $enrol($now, Student::factory()->create(), 11);
        $enrol($now, Student::factory()->create(), 11, EnrollmentStatus::Dropped);

        $m = app(MetricsReport::class)->forYear($now);

        $this->assertSame(50.0, $m['promotion_rate']);
        $this->assertSame(75.0, $m['retention_rate']);
        $this->assertSame(20.0, $m['dropout_rate']);
        $this->assertSame(50.0, $m['transition_rate'], 'Two Grade 11 learners out of 4 Grade 10 completers.');
        $this->assertSame(0.0, $m['growth_rate'], 'Four enrolled last year and four enrolled now; the dropped learner does not count.');

        // On the Reports page, the rates are shown to administrators only.
        $admin = User::factory()->role(Role::Admin)->create();
        $this->actingAs($admin)->get('/reports')->assertOk()->assertInertia(fn ($p) => $p->component('reports/index')
            ->where('rates.total_enrollment', 4)->where('rates.promotion_rate', 50));
        $this->actingAs(User::factory()->role(Role::Registrar)->create())->get('/reports')->assertOk()
            ->assertInertia(fn ($p) => $p->where('rates', null));
        $this->actingAs($admin)->get('/metrics')->assertRedirect('/reports');
    }

    public function test_analytics_and_year_comparison(): void
    {
        $this->seed(StrandSeeder::class);
        $a = SchoolYear::factory()->create(['start_year' => 2025]);
        $b = SchoolYear::factory()->active()->create(['start_year' => 2026]);
        Enrollment::factory()->count(3)->enrolled()->create(['school_year_id' => $a->id, 'grade_level' => 11, 'strand_id' => Strand::first()->id]);
        Enrollment::factory()->count(5)->enrolled()->create(['school_year_id' => $b->id, 'grade_level' => 11, 'strand_id' => Strand::first()->id]);
        Enrollment::factory()->create(['school_year_id' => $b->id, 'grade_level' => 11, 'strand_id' => Strand::first()->id, 'status' => EnrollmentStatus::Pending]);
        $registrar = User::factory()->role(Role::Registrar)->create();

        // Compared with the year before by default.
        $this->actingAs($registrar)->get('/reports')
            ->assertInertia(fn ($p) => $p->component('reports/index')
                ->where('filters.compare', $a->id)
                ->where('summary.now.enrolled', 5)
                ->where('summary.before.enrolled', 3)
                ->where('table.0.total', 5)
                ->where('table.0.before', 3)
                ->has('trends', 2)
                ->has('strandTrends', 1)
                ->where('attention.0.count', 1, 'One enrollment is waiting for review.'));
        // "No comparison" turns the comparison off.
        $this->actingAs($registrar)->get('/reports?compare=0')->assertInertia(fn ($p) => $p->where('summary.before', null)->where('table.0.before', null));
        $this->actingAs($registrar)->get('/reports/export')->assertOk();
        $this->actingAs($registrar)->get('/analytics')->assertRedirect('/reports');
        $this->actingAs(User::factory()->role(Role::Adviser)->create())->get('/reports')->assertForbidden();
        $this->actingAs(User::factory()->role(Role::IctCoordinator)->create())->get('/reports')->assertForbidden();
    }

    public function test_the_grade_and_strand_table_matches_the_workbook_layout(): void
    {
        $this->seed(StrandSeeder::class);
        $year = SchoolYear::factory()->active()->create();
        $humss = Strand::where('code', 'HUMSS')->value('id');
        $abm = Strand::where('code', 'ABM')->value('id');
        $make = fn (int $n, int $grade, ?int $strand, string $sex) => Enrollment::factory()->count($n)->enrolled()->create([
            'school_year_id' => $year->id, 'grade_level' => $grade, 'strand_id' => $strand,
            'student_id' => fn () => Student::factory()->create(['sex' => $sex]),
        ]);
        $make(2, 11, $humss, 'female');
        $make(1, 11, $humss, 'male');
        $make(3, 11, $abm, 'male');
        $make(4, 12, $humss, 'female');
        $make(2, 10, null, 'male');

        $rows = app(AnalyticsReport::class)->byGradeAndStrand($year);

        $this->assertSame([
            [10, null, 2, 0, 2],
            [11, 'ABM', 3, 0, 3],
            [11, 'HUMSS', 1, 2, 3],
            [12, 'HUMSS', 0, 4, 4],
        ], array_map(fn ($r) => [$r['grade'], $r['strand'], $r['male'], $r['female'], $r['total']], $rows));
    }

    public function test_each_role_gets_its_dashboard_blocks(): void
    {
        SchoolYear::factory()->active()->create();
        $expect = [
            Role::Registrar->value => ['review' => true, 'mySections' => false, 'sectionOverview' => false, 'teaching' => false, 'system' => false],
            Role::Adviser->value => ['review' => true, 'mySections' => true, 'sectionOverview' => false, 'teaching' => false, 'system' => false],
            Role::KeyTeacher->value => ['review' => false, 'mySections' => false, 'sectionOverview' => true, 'teaching' => false, 'system' => false],
            Role::SubjectTeacher->value => ['review' => false, 'mySections' => false, 'sectionOverview' => false, 'teaching' => true, 'system' => false],
            Role::IctCoordinator->value => ['review' => true, 'mySections' => false, 'sectionOverview' => false, 'teaching' => false, 'system' => true],
            Role::Admin->value => ['review' => true, 'mySections' => false, 'sectionOverview' => true, 'teaching' => false, 'system' => true],
        ];

        foreach ($expect as $role => $blocks) {
            $this->actingAs(User::factory()->role(Role::from($role))->create())->get('/dashboard')->assertInertia(function ($p) use ($blocks, $role) {
                foreach ($blocks as $block => $shown) {
                    $shown ? $p->whereNot($block, null) : $p->where($block, null);
                }
                $p->where('monitoring', $role === Role::SubjectTeacher->value ? null : fn ($m) => $m !== null);
            });
        }
    }
}
