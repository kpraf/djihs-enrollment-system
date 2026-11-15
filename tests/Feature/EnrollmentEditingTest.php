<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Strand;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\StrandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LearnerPayload;
use Tests\TestCase;

class EnrollmentEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $registrar;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(StrandSeeder::class);
        $year = SchoolYear::factory()->active()->create();
        $this->registrar = User::factory()->role(Role::Registrar)->create();
        $this->actingAs($this->registrar)->post('/enrollments', LearnerPayload::make())->assertSessionHasNoErrors();
        $this->enrollment = Enrollment::sole();
    }

    private function editPayload(array $overrides = []): array
    {
        $e = $this->enrollment->fresh();

        return LearnerPayload::make([
            'student_version' => $e->student->lock_version,
            'enrollment_version' => $e->lock_version,
            ...$overrides,
        ]);
    }

    public function test_details_and_edit_pages_render(): void
    {
        $this->actingAs($this->registrar)->get("/enrollments/{$this->enrollment->id}")
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('enrollments/show')->where('enrollment.student.full_name', 'DELA CRUZ, JUAN SANTOS')->has('history', 2));

        $this->actingAs($this->registrar)->get("/enrollments/{$this->enrollment->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('enrollments/edit')->where('enrollment.enrollment_version', 1));
    }

    public function test_an_edit_saves_and_is_recorded_in_history(): void
    {
        $abm = Strand::where('code', 'ABM')->value('id');

        $this->actingAs($this->registrar)
            ->put("/enrollments/{$this->enrollment->id}", $this->editPayload(['strand_id' => $abm, 'first_name' => 'Juanito']))
            ->assertRedirect("/enrollments/{$this->enrollment->id}");

        $fresh = $this->enrollment->fresh();
        $this->assertSame($abm, $fresh->strand_id);
        $this->assertSame('JUANITO', $fresh->student->first_name);
        $this->assertSame(2, $fresh->lock_version);
        $this->assertSame(2, $fresh->student->lock_version);
        $this->assertTrue(AuditLog::where('auditable_type', Enrollment::class)->where('event', 'updated')->exists());
    }

    /**
     * Two staff open the same learner. The first saves. The second, still
     * looking at the old version, must not silently overwrite the first.
     */
    public function test_a_save_based_on_stale_data_is_refused(): void
    {
        $stale = $this->editPayload();
        $other = User::factory()->role(Role::IctCoordinator)->create();

        $this->actingAs($other)->put("/enrollments/{$this->enrollment->id}", [...$stale, 'religion' => 'Iglesia ni Cristo'])->assertSessionHasNoErrors();

        $this->actingAs($this->registrar)
            ->put("/enrollments/{$this->enrollment->id}", [...$stale, 'religion' => 'Born Again'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Someone else changed this record'));

        $this->assertSame('IGLESIA NI CRISTO', Student::sole()->religion);
    }

    public function test_changing_to_an_lrn_owned_by_another_learner_is_refused(): void
    {
        Student::factory()->create(['lrn' => '888888888888']);

        $this->actingAs($this->registrar)
            ->put("/enrollments/{$this->enrollment->id}", $this->editPayload(['lrn' => '888888888888']))
            ->assertSessionHasErrors('lrn');

        $this->assertSame('123456789012', $this->enrollment->fresh()->student->lrn);
    }

    public function test_cancel_and_restore(): void
    {
        $this->actingAs($this->registrar)
            ->post("/enrollments/{$this->enrollment->id}/leave", ['status' => 'cancelled', 'reason' => 'Transferred out', 'enrollment_version' => 1])
            ->assertSessionHasNoErrors();

        $fresh = $this->enrollment->fresh();
        $this->assertSame(EnrollmentStatus::Cancelled, $fresh->status);
        $this->assertSame($this->registrar->id, $fresh->status_changed_by);

        $this->actingAs($this->registrar)->post("/enrollments/{$this->enrollment->id}/restore", ['enrollment_version' => 2]);
        $this->assertSame(EnrollmentStatus::Enrolled, $this->enrollment->fresh()->status);
        $this->assertNull($this->enrollment->fresh()->status_reason);
    }

    public function test_dropped_and_transferred_out_free_the_section_seat(): void
    {
        $section = Section::create(['school_year_id' => $this->enrollment->school_year_id, 'grade_level' => 11, 'strand_id' => $this->enrollment->strand_id, 'name' => 'Integrity', 'capacity' => 40]);
        $this->enrollment->update(['status' => EnrollmentStatus::Enrolled, 'section_id' => $section->id]);

        $this->actingAs($this->registrar)
            ->post("/enrollments/{$this->enrollment->id}/leave", ['status' => 'dropped', 'reason' => 'Stopped attending', 'enrollment_version' => $this->enrollment->fresh()->lock_version])
            ->assertSessionHasNoErrors();

        $this->assertSame(EnrollmentStatus::Dropped, $this->enrollment->fresh()->status);
        $this->assertNull($this->enrollment->fresh()->section_id);
    }

    public function test_cancelling_twice_from_two_screens_is_reported(): void
    {
        $this->actingAs($this->registrar)->post("/enrollments/{$this->enrollment->id}/leave", ['status' => 'cancelled', 'reason' => 'A', 'enrollment_version' => 1]);

        $this->actingAs(User::factory()->role(Role::Registrar)->create())
            ->post("/enrollments/{$this->enrollment->id}/leave", ['status' => 'cancelled', 'reason' => 'B', 'enrollment_version' => 1])
            ->assertSessionHas('error');

        $this->assertSame('A', $this->enrollment->fresh()->status_reason);
    }

    public function test_leaving_needs_a_reason_and_a_valid_status(): void
    {
        $this->actingAs($this->registrar)
            ->post("/enrollments/{$this->enrollment->id}/leave", ['status' => 'enrolled', 'reason' => '', 'enrollment_version' => 1])
            ->assertSessionHasErrors(['reason', 'status']);
    }

    public function test_key_teachers_can_view_but_not_edit(): void
    {
        $key = User::factory()->role(Role::KeyTeacher)->create();

        $this->actingAs($key)->get("/enrollments/{$this->enrollment->id}")->assertOk()->assertInertia(fn ($p) => $p->where('can.edit', false)->where('can.requestRevision', false));
        $this->actingAs($key)->get("/enrollments/{$this->enrollment->id}/edit")->assertForbidden();
        $this->actingAs($key)->put("/enrollments/{$this->enrollment->id}", $this->editPayload())->assertForbidden();
        $this->actingAs($key)->post("/enrollments/{$this->enrollment->id}/leave", ['status' => 'cancelled', 'reason' => 'x', 'enrollment_version' => 1])->assertForbidden();
    }

    public function test_the_list_searches_across_name_parts(): void
    {
        foreach (['juan dela cruz', 'DELA CRUZ, Juan', '1234567890', 'santos'] as $q) {
            $this->actingAs($this->registrar)->get('/enrollments?search='.urlencode($q))
                ->assertInertia(fn ($p) => $p->where('enrollments.total', 1));
        }

        $this->actingAs($this->registrar)->get('/enrollments?search=pedro')->assertInertia(fn ($p) => $p->where('enrollments.total', 0));
    }
}
