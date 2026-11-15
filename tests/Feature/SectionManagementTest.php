<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\Role;
use App\Exceptions\StaleRecordException;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Strand;
use App\Models\TeacherAssignment;
use App\Models\User;
use App\Services\Sections\SectionAssigner;
use Database\Seeders\StrandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $key;

    private SchoolYear $year;

    private Strand $humss;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(StrandSeeder::class);
        $this->year = SchoolYear::factory()->active()->create();
        $this->key = User::factory()->role(Role::KeyTeacher)->create();
        $this->humss = Strand::where('code', 'HUMSS')->first();
    }

    private function section(array $attrs = []): Section
    {
        return Section::create(['school_year_id' => $this->year->id, 'grade_level' => 11, 'strand_id' => $this->humss->id, 'name' => 'Integrity', 'capacity' => 2, ...$attrs]);
    }

    private function learner(array $attrs = []): Enrollment
    {
        return Enrollment::factory()->enrolled()->create(['school_year_id' => $this->year->id, 'grade_level' => 11, 'strand_id' => $this->humss->id, ...$attrs]);
    }

    public function test_strands_can_be_managed_but_used_ones_not_deleted(): void
    {
        $this->actingAs($this->key)->post('/strands', ['code' => 'stem', 'name' => 'Science, Technology, Engineering and Mathematics', 'track' => 'academic'])->assertSessionHasNoErrors();
        $stem = Strand::where('code', 'STEM')->firstOrFail();

        $this->actingAs($this->key)->post('/strands', ['code' => 'STEM', 'name' => 'Dup', 'track' => 'academic'])->assertSessionHasErrors('code');
        $this->actingAs($this->key)->put("/strands/{$stem->id}/active", ['active' => false]);
        $this->assertFalse($stem->fresh()->is_active);

        $this->learner();
        $this->actingAs($this->key)->delete("/strands/{$this->humss->id}")->assertSessionHas('error');
        $this->actingAs($this->key)->delete("/strands/{$stem->id}")->assertSessionHas('success');
        $this->assertNull($stem->fresh());
    }

    public function test_sections_are_validated(): void
    {
        $base = ['school_year_id' => $this->year->id, 'grade_level' => 11, 'name' => 'Integrity', 'capacity' => 40];

        $this->actingAs($this->key)->post('/sections', $base)->assertSessionHasErrors('strand_id');
        $this->actingAs($this->key)->post('/sections', [...$base, 'strand_id' => $this->humss->id])->assertSessionHasNoErrors();
        $this->actingAs($this->key)->post('/sections', [...$base, 'strand_id' => $this->humss->id])->assertSessionHasErrors('name');
        $this->actingAs($this->key)->post('/sections', [...$base, 'grade_level' => 8, 'name' => 'Sampaguita', 'strand_id' => $this->humss->id])->assertSessionHasNoErrors();
        $this->assertNull(Section::where('name', 'Sampaguita')->value('strand_id'), 'Junior High sections have no strand.');

        $registrar = User::factory()->role(Role::Registrar)->create();
        $this->actingAs($this->key)->post('/sections', [...$base, 'name' => 'X', 'strand_id' => $this->humss->id, 'adviser_id' => $registrar->id])->assertSessionHasErrors('adviser_id');
    }

    public function test_capacity_cannot_drop_below_learners_and_occupied_sections_cannot_be_deleted(): void
    {
        $section = $this->section(['capacity' => 5]);
        $this->learner(['section_id' => $section->id]);
        $this->learner(['section_id' => $section->id]);

        $this->actingAs($this->key)->put("/sections/{$section->id}", ['school_year_id' => $this->year->id, 'grade_level' => 11, 'strand_id' => $this->humss->id, 'name' => 'Integrity', 'capacity' => 1])
            ->assertSessionHasErrors('capacity');
        $this->actingAs($this->key)->delete("/sections/{$section->id}")->assertSessionHas('error');
        $this->assertNotNull($section->fresh());
    }

    public function test_subject_teachers_are_assigned_once_per_subject(): void
    {
        $section = $this->section();
        $teacher = User::factory()->role(Role::SubjectTeacher)->create();

        $this->actingAs($this->key)->post("/sections/{$section->id}/teachers", ['user_id' => $teacher->id, 'subject_name' => 'General Mathematics'])->assertSessionHasNoErrors();
        $this->actingAs($this->key)->post("/sections/{$section->id}/teachers", ['user_id' => $teacher->id, 'subject_name' => 'General Mathematics'])->assertSessionHasErrors('subject_name');
        $this->actingAs($this->key)->delete('/teacher-assignments/'.TeacherAssignment::sole()->id);
        $this->assertSame(0, TeacherAssignment::count());
    }

    public function test_placing_learners_never_exceeds_capacity(): void
    {
        $section = $this->section(['capacity' => 2]);
        $learners = [$this->learner(), $this->learner(), $this->learner()];

        $this->actingAs($this->key)->post('/section-assignments', ['enrollment_ids' => array_map(fn ($l) => $l->id, $learners), 'section_id' => $section->id])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Placed 2 learners') && str_contains($m, 'full'));

        $this->assertSame(2, Enrollment::where('section_id', $section->id)->count());
    }

    public function test_only_matching_enrolled_learners_can_be_placed(): void
    {
        $section = $this->section(['capacity' => 10]);
        $abm = $this->learner(['strand_id' => Strand::where('code', 'ABM')->value('id')]);
        $pending = $this->learner(['status' => EnrollmentStatus::Pending]);
        $g12 = $this->learner(['grade_level' => 12]);

        foreach ([$abm, $pending, $g12] as $l) {
            $this->actingAs($this->key)->post('/section-assignments', ['enrollment_ids' => [$l->id], 'section_id' => $section->id])->assertSessionHas('error');
        }
        $this->assertSame(0, Enrollment::where('section_id', $section->id)->count());
    }

    public function test_auto_assign_is_first_come_first_served_by_strand_and_capacity(): void
    {
        $first = $this->section(['name' => 'Integrity', 'capacity' => 2]);
        $second = $this->section(['name' => 'Honesty', 'capacity' => 2]);
        $abmSection = $this->section(['name' => 'Diligence', 'capacity' => 5, 'strand_id' => Strand::where('code', 'ABM')->value('id')]);
        $early = $this->learner(['encoded_on' => '2026-06-01']);
        $this->learner(['encoded_on' => '2026-06-02']);
        $this->learner(['encoded_on' => '2026-06-03']);
        $this->learner(['encoded_on' => '2026-06-04']);
        $late = $this->learner(['encoded_on' => '2026-06-05']);
        $abm = $this->learner(['strand_id' => Strand::where('code', 'ABM')->value('id')]);

        $this->actingAs($this->key)->post('/section-assignments/auto', ['grade' => 11])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Auto-assigned 5') && str_contains($m, '1 could not be placed'));

        $this->assertSame($first->id, $early->fresh()->section_id);
        $this->assertNull($late->fresh()->section_id, 'The last learner encoded waits when HUMSS sections are full.');
        $this->assertSame($abmSection->id, $abm->fresh()->section_id);
        $this->assertSame(2, Enrollment::where('section_id', $second->id)->count());
    }

    public function test_unassign_and_clear(): void
    {
        $section = $this->section(['capacity' => 5]);
        $a = $this->learner(['section_id' => $section->id]);
        $this->learner(['section_id' => $section->id]);

        $this->actingAs($this->key)->delete("/section-assignments/{$a->id}");
        $this->assertNull($a->fresh()->section_id);
        $this->actingAs($this->key)->delete("/sections/{$section->id}/learners")->assertSessionHas('success', 'Removed 1 learners from Integrity.');
        $this->assertSame(0, Enrollment::whereNotNull('section_id')->count());
    }

    public function test_the_assign_page_renders(): void
    {
        $this->section();
        $this->learner();

        $this->actingAs($this->key)->get('/section-assignments?grade=11')
            ->assertInertia(fn ($p) => $p->component('section-assignments/index')->has('unassigned', 1)->has('sections', 1));
        $this->actingAs($this->key)->get('/sections')->assertInertia(fn ($p) => $p->component('sections/index')->has('sections', 1));
        $this->actingAs($this->key)->get('/strands')->assertInertia(fn ($p) => $p->component('strands/index')->has('strands', 5));
    }

    public function test_only_key_teachers_and_admins_manage_sections(): void
    {
        foreach ([Role::Registrar, Role::Adviser, Role::SubjectTeacher, Role::IctCoordinator] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->get('/sections')->assertForbidden();
        }
        $this->actingAs(User::factory()->role(Role::Admin)->create())->get('/sections')->assertOk();
    }

    public function test_the_service_refuses_a_full_section_directly(): void
    {
        $section = $this->section(['capacity' => 1]);
        app(SectionAssigner::class)->assign($this->learner(), $section);

        $this->expectException(StaleRecordException::class);
        app(SectionAssigner::class)->assign($this->learner(), $section);
    }
}
