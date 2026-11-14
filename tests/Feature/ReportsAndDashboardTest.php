<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\Role;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Strand;
use App\Models\Student;
use App\Models\User;
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

        $admin = User::factory()->role(Role::Admin)->create();
        $this->actingAs($admin)->get('/metrics')->assertOk()->assertInertia(fn ($p) => $p->component('metrics/index')->where('metrics.total_enrollment', 4));
        $this->actingAs($admin)->get('/metrics/export')->assertOk();
        $this->actingAs(User::factory()->role(Role::Registrar)->create())->get('/metrics')->assertForbidden();
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

        $this->actingAs($registrar)->get("/analytics?compare_a={$a->id}&compare_b={$b->id}")
            ->assertInertia(fn ($p) => $p->component('analytics/index')
                ->where('report.overview.total', 5)
                ->where('comparison.by_grade.0.change', 2)
                ->has('trends', 2));
        $this->actingAs($registrar)->get('/analytics/export')->assertOk();
        $this->actingAs(User::factory()->role(Role::Adviser)->create())->get('/analytics')->assertForbidden();
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
