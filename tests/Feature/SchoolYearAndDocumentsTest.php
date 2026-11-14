<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\DocumentSubmission;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Strand;
use App\Models\User;
use Database\Seeders\StrandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolYearAndDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_years_are_created_activated_and_archived(): void
    {
        $ict = User::factory()->role(Role::IctCoordinator)->create();
        $old = SchoolYear::factory()->active()->create(['start_year' => 2025]);

        $this->actingAs($ict)->post('/school-years', ['start_year' => 2026, 'grade_10_completers' => 300])->assertSessionHasNoErrors();
        $this->actingAs($ict)->post('/school-years', ['start_year' => 2026])->assertSessionHasErrors('start_year');
        $new = SchoolYear::where('start_year', 2026)->sole();

        $this->actingAs($ict)->put("/school-years/{$old->id}/archive", ['archived' => true])->assertSessionHas('error');
        $this->actingAs($ict)->post("/school-years/{$new->id}/activate");
        $this->assertTrue($new->fresh()->is_active);
        $this->assertFalse($old->fresh()->is_active);
        $this->assertSame(1, SchoolYear::where('is_active', true)->count());

        $this->actingAs($ict)->put("/school-years/{$old->id}/archive", ['archived' => true])->assertSessionHas('success');
        $this->assertTrue($old->fresh()->is_archived);
        $this->actingAs($ict)->post("/school-years/{$old->id}/activate")->assertSessionHas('error');

        $this->actingAs($ict)->put("/school-years/{$new->id}", ['is_enrollment_open' => false, 'grade_10_completers' => 310]);
        $this->assertFalse($new->fresh()->is_enrollment_open);
        $this->assertSame(310, $new->fresh()->grade_10_completers);

        $this->actingAs(User::factory()->role(Role::Registrar)->create())->get('/school-years')->assertForbidden();
    }

    public function test_the_document_checklist(): void
    {
        $this->seed(StrandSeeder::class);
        $year = SchoolYear::factory()->active()->create();
        $e = Enrollment::factory()->enrolled()->create(['school_year_id' => $year->id, 'strand_id' => Strand::first()->id, 'learner_type' => 'transferee']);
        $adviser = User::factory()->role(Role::Adviser)->create();
        $put = fn (string $type, bool $sub, bool $ver = false, ?string $notes = null) => $this->actingAs($adviser)
            ->put("/enrollments/{$e->id}/documents", ['document_type' => $type, 'is_submitted' => $sub, 'is_verified' => $ver, 'notes' => $notes]);

        $put('psa_birth_cert', false, true)->assertSessionHasNoErrors();
        $this->assertFalse(DocumentSubmission::sole()->is_verified, 'A document cannot be verified before it is submitted.');

        $put('psa_birth_cert', true, true, 'Original seen');
        $put('report_card', true);
        $this->assertSame(2, DocumentSubmission::count());

        $incomplete = fn () => $this->actingAs($adviser)->get('/enrollments?documents=incomplete')->viewData('page')['props']['enrollments']['total'];
        $this->assertSame(1, $incomplete(), 'A transferee still needs Form 137.');

        $put('form_137', true);
        $this->assertSame(0, $incomplete());

        $this->actingAs($adviser)->get("/enrollments/{$e->id}")
            ->assertInertia(fn ($p) => $p->where('enrollment.documents_complete', true)->where('documents.0.notes', 'Original seen'));

        $this->actingAs(User::factory()->role(Role::KeyTeacher)->create())
            ->put("/enrollments/{$e->id}/documents", ['document_type' => 'good_moral', 'is_submitted' => true, 'is_verified' => false])->assertForbidden();
    }
}
