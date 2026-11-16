<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\Role;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Strand;
use App\Models\User;
use App\Services\Reports\EnrollmentSummary;
use Database\Seeders\StrandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LearnerPayload;
use Tests\TestCase;

class ReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private SchoolYear $year;

    private User $registrar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(StrandSeeder::class);
        $this->year = SchoolYear::factory()->active()->create();
        $this->registrar = User::factory()->role(Role::Registrar)->create();
    }

    private function pending(int $n = 1)
    {
        return Enrollment::factory()->count($n)->create([
            'school_year_id' => $this->year->id,
            'strand_id' => Strand::first()->id,
            'status' => EnrollmentStatus::Pending,
        ]);
    }

    public function test_the_review_page_lists_pending_enrollments(): void
    {
        $this->pending(3);
        Enrollment::factory()->enrolled()->create(['school_year_id' => $this->year->id, 'strand_id' => Strand::first()->id]);

        $this->actingAs($this->registrar)->get('/reviews')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('reviews/index')->where('pending.total', 3)->where('stats.pending', 3)->where('stats.enrolled', 1));
    }

    public function test_approve_and_reject(): void
    {
        [$a, $b] = $this->pending(2);

        $this->actingAs($this->registrar)->post("/reviews/{$a->id}/approve")->assertSessionHas('success');
        $this->actingAs($this->registrar)->post("/reviews/{$b->id}/reject", ['reason' => 'LRN does not match'])->assertSessionHas('success');

        $this->assertSame(EnrollmentStatus::Enrolled, $a->fresh()->status);
        $this->assertSame($this->registrar->id, $a->fresh()->reviewed_by);
        $this->assertSame(EnrollmentStatus::Rejected, $b->fresh()->status);
        $this->assertSame('LRN does not match', $b->fresh()->status_reason);
    }

    public function test_a_second_reviewer_is_told_it_was_already_reviewed(): void
    {
        [$e] = $this->pending();
        $other = User::factory()->role(Role::IctCoordinator)->create();

        $this->actingAs($this->registrar)->post("/reviews/{$e->id}/approve");
        $this->actingAs($other)->post("/reviews/{$e->id}/reject", ['reason' => 'x'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'already enrolled'));

        $this->assertSame(EnrollmentStatus::Enrolled, $e->fresh()->status);
    }

    public function test_approve_all_respects_filters_and_skips_nothing_else(): void
    {
        $this->pending(3);
        $g12 = Enrollment::factory()->create(['school_year_id' => $this->year->id, 'strand_id' => Strand::first()->id, 'status' => EnrollmentStatus::Pending, 'grade_level' => 12]);
        Enrollment::where('status', 'pending')->where('id', '!=', $g12->id)->update(['grade_level' => 11]);

        $this->actingAs($this->registrar)->post('/reviews/approve-all', ['grade' => 11])->assertSessionHas('success', 'Approved 3 enrollments.');

        $this->assertSame(EnrollmentStatus::Pending, $g12->fresh()->status);
    }

    public function test_approve_all_lists_everyone_it_will_approve_when_asked(): void
    {
        $this->pending(3);
        $g12 = Enrollment::factory()->create(['school_year_id' => $this->year->id, 'strand_id' => Strand::first()->id, 'status' => EnrollmentStatus::Pending, 'grade_level' => 12]);
        Enrollment::where('status', 'pending')->where('id', '!=', $g12->id)->update(['grade_level' => 11]);

        // Not sent with the page; only when the confirmation asks for it.
        $this->actingAs($this->registrar)->get('/reviews?grade=11')->assertInertia(fn ($p) => $p
            ->missing('approveAllList')
            ->reloadOnly('approveAllList', fn ($r) => $r
                ->has('approveAllList', 3)
                ->where('approveAllList.0.grade_level', 11)
                ->has('approveAllList.0.name')
                ->has('approveAllList.0.lrn')));
        $this->actingAs($this->registrar)->get('/reviews')->assertInertia(fn ($p) => $p->reloadOnly('approveAllList', fn ($r) => $r->has('approveAllList', 4)));
    }

    public function test_only_enrolled_learners_count_in_the_monitoring_report(): void
    {
        $this->pending(2);

        $this->assertSame(0, app(EnrollmentSummary::class)->monitoring($this->year)['total']['total']);
    }

    public function test_a_rejected_enrollment_can_be_corrected_and_submitted_again(): void
    {
        $this->actingAs($this->registrar)->post('/enrollments', LearnerPayload::make())->assertSessionHasNoErrors();
        $e = Enrollment::sole();
        $this->actingAs($this->registrar)->post("/reviews/{$e->id}/reject", ['reason' => 'Wrong strand']);

        $this->actingAs($this->registrar)->post('/enrollments', LearnerPayload::make(['strand_id' => Strand::where('code', 'ABM')->value('id')]))->assertSessionHasNoErrors();

        $this->assertSame(1, Enrollment::count());
        $this->assertSame(EnrollmentStatus::Pending, $e->fresh()->status);
        $this->assertSame('ABM', $e->fresh()->strand->code);
    }

    public function test_who_may_review(): void
    {
        $this->actingAs(User::factory()->role(Role::Adviser)->create())->get('/reviews')->assertOk()->assertInertia(fn ($p) => $p->where('canApproveRevisions', false));
        $this->actingAs(User::factory()->role(Role::KeyTeacher)->create())->get('/reviews')->assertForbidden();
        $this->actingAs(User::factory()->role(Role::SubjectTeacher)->create())->get('/reviews')->assertForbidden();
    }

    public function test_encoding_is_refused_while_enrollment_is_closed(): void
    {
        $this->year->update(['is_enrollment_open' => false]);

        $this->actingAs($this->registrar)->post('/enrollments', LearnerPayload::make())->assertSessionHas('error');
        $this->assertSame(0, Enrollment::count());
    }
}
