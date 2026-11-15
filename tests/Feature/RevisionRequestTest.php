<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Enrollment;
use App\Models\RevisionRequest;
use App\Models\SchoolYear;
use App\Models\Strand;
use App\Models\User;
use Database\Seeders\StrandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LearnerPayload;
use Tests\TestCase;

class RevisionRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $adviser;

    private User $registrar;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(StrandSeeder::class);
        SchoolYear::factory()->active()->create();
        $this->adviser = User::factory()->role(Role::Adviser)->create();
        $this->registrar = User::factory()->role(Role::Registrar)->create();
        $this->actingAs($this->registrar)->post('/enrollments', LearnerPayload::make());
        $this->enrollment = Enrollment::sole();
    }

    private function payload(array $overrides = []): array
    {
        $e = $this->enrollment->fresh();

        return LearnerPayload::make(['student_version' => $e->student->lock_version, 'enrollment_version' => $e->lock_version, ...$overrides]);
    }

    public function test_an_advisers_edit_becomes_a_request_and_changes_nothing_yet(): void
    {
        $this->actingAs($this->adviser)->get("/enrollments/{$this->enrollment->id}/edit")->assertInertia(fn ($p) => $p->where('asRevision', true));

        $this->actingAs($this->adviser)
            ->put("/enrollments/{$this->enrollment->id}", $this->payload(['religion' => 'Iglesia ni Cristo', 'justification' => 'Parent corrected it']))
            ->assertSessionHas('success');

        $request = RevisionRequest::sole();
        $this->assertSame(['religion' => ['old' => 'ROMAN CATHOLIC', 'new' => 'IGLESIA NI CRISTO']], $request->changes);
        $this->assertSame('ROMAN CATHOLIC', $this->enrollment->fresh()->student->religion);
    }

    public function test_a_reason_is_required_and_empty_requests_are_not_sent(): void
    {
        $this->actingAs($this->adviser)->put("/enrollments/{$this->enrollment->id}", $this->payload(['religion' => 'X']))->assertSessionHasErrors('justification');
        $this->actingAs($this->adviser)->put("/enrollments/{$this->enrollment->id}", $this->payload(['justification' => 'no change']))->assertSessionHas('error');
        $this->assertSame(0, RevisionRequest::count());
    }

    public function test_approving_applies_the_change(): void
    {
        $abm = Strand::where('code', 'ABM')->value('id');
        $this->actingAs($this->adviser)->put("/enrollments/{$this->enrollment->id}", $this->payload(['strand_id' => $abm, 'weight_kg' => '55', 'justification' => 'Shifted strand']));

        $this->actingAs($this->registrar)->post('/revisions/'.RevisionRequest::sole()->id.'/approve')->assertSessionHas('success');

        $this->assertSame($abm, $this->enrollment->fresh()->strand_id);
        $this->assertEquals(55, $this->enrollment->fresh()->weight_kg);
        $this->assertSame('approved', RevisionRequest::sole()->status);
    }

    public function test_approval_is_refused_when_the_field_changed_since_the_request(): void
    {
        $this->actingAs($this->adviser)->put("/enrollments/{$this->enrollment->id}", $this->payload(['religion' => 'A', 'justification' => 'x']));
        $this->actingAs($this->registrar)->put("/enrollments/{$this->enrollment->id}", $this->payload(['religion' => 'B']));

        $this->actingAs($this->registrar)->post('/revisions/'.RevisionRequest::sole()->id.'/approve')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'was changed after the request'));

        $this->assertSame('B', $this->enrollment->fresh()->student->religion);
        $this->assertSame('pending', RevisionRequest::sole()->status);
    }

    public function test_reject_needs_a_note_and_cannot_be_decided_twice(): void
    {
        $this->actingAs($this->adviser)->put("/enrollments/{$this->enrollment->id}", $this->payload(['religion' => 'A', 'justification' => 'x']));
        $id = RevisionRequest::sole()->id;

        $this->actingAs($this->registrar)->post("/revisions/{$id}/reject", ['notes' => ''])->assertSessionHasErrors('notes');
        $this->actingAs($this->registrar)->post("/revisions/{$id}/reject", ['notes' => 'Need the PSA copy'])->assertSessionHas('success');
        $this->actingAs($this->registrar)->post("/revisions/{$id}/approve")->assertSessionHas('error');

        $this->assertSame('ROMAN CATHOLIC', $this->enrollment->fresh()->student->religion);
    }

    public function test_advisers_cannot_approve_requests(): void
    {
        $this->actingAs($this->adviser)->put("/enrollments/{$this->enrollment->id}", $this->payload(['religion' => 'A', 'justification' => 'x']));

        $this->actingAs($this->adviser)->post('/revisions/'.RevisionRequest::sole()->id.'/approve')->assertForbidden();
    }
}
