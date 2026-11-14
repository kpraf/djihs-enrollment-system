<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Enums\Role;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Strand;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\StrandSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LearnerPayload;
use Tests\TestCase;

class EnrollmentEncodingTest extends TestCase
{
    use RefreshDatabase;

    private User $registrar;

    private SchoolYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(StrandSeeder::class);
        $this->year = SchoolYear::factory()->active()->create(['start_year' => 2026]);
        $this->registrar = User::factory()->role(Role::Registrar)->create();
    }

    private function enroll(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->registrar)->post('/enrollments', LearnerPayload::make($overrides));
    }

    public function test_the_form_renders(): void
    {
        $this->actingAs($this->registrar)->get('/enrollments/create')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('enrollments/create')->where('schoolYear.name', '2026-2027')->has('options.strands', 5));
    }

    public function test_a_new_learner_is_enrolled_with_cleaned_values(): void
    {
        $this->enroll()->assertSessionHasNoErrors()->assertRedirect('/enrollments/create');

        $student = Student::sole();
        $this->assertSame('DELA CRUZ', $student->last_name);
        $this->assertSame('JUAN', $student->first_name);
        $this->assertSame('09171234567', $student->contact_number);
        $this->assertTrue($student->is_4ps_beneficiary);

        $enrollment = Enrollment::sole();
        $this->assertSame($this->year->id, $enrollment->school_year_id);
        $this->assertSame($this->registrar->id, $enrollment->encoded_by);
        $this->assertSame(EnrollmentStatus::Enrolled, $enrollment->status);
        $this->assertSame(now()->toDateString(), $enrollment->encoded_on->toDateString());
    }

    public function test_required_fields_and_formats_are_checked(): void
    {
        $this->enroll([
            'lrn' => '12345',
            'strand_id' => null,
            'contact_number' => '12345',
            'mother_last_name' => '',
            'learner_type' => '',
            'birthdate' => now()->addDay()->toDateString(),
        ])->assertSessionHasErrors(['lrn', 'strand_id', 'contact_number', 'guardian_last_name', 'learner_type', 'birthdate']);

        $this->assertSame(0, Student::count());
    }

    public function test_specify_fields_are_required_only_when_yes(): void
    {
        $this->enroll(['is_indigenous' => true, 'indigenous_group' => '', 'is_pwd' => true, 'disability' => ''])
            ->assertSessionHasErrors(['indigenous_group', 'disability']);

        $this->enroll(['is_indigenous' => false, 'indigenous_group' => 'leftover text'])->assertSessionHasNoErrors();
        $this->assertNull(Student::sole()->indigenous_group, 'Text in a hidden "specify" box must not be saved.');
    }

    public function test_junior_high_learners_have_no_strand(): void
    {
        $this->enroll(['grade_level' => 8, 'strand_id' => null])->assertSessionHasNoErrors();
        $this->assertNull(Enrollment::sole()->strand_id);
    }

    public function test_the_same_learner_cannot_be_enrolled_twice_in_a_year(): void
    {
        $this->enroll()->assertSessionHasNoErrors();

        $other = User::factory()->role(Role::Adviser)->create();
        $this->enroll([], $other)->assertSessionHasErrors(['lrn' => 'This learner is already enrolled for school year 2026-2027: Grade 11 HUMSS by '.$this->registrar->name.' on '.now()->format('M j, Y').'.']);

        $this->assertSame(1, Enrollment::count());
    }

    public function test_an_old_student_is_re_enrolled_on_their_existing_record(): void
    {
        $student = Student::factory()->create(['lrn' => '123456789012', 'last_name' => 'DELA CRUZ', 'first_name' => 'JUAN', 'birthdate' => '2009-03-15']);
        Enrollment::factory()->create(['student_id' => $student->id, 'school_year_id' => SchoolYear::factory()->create(['start_year' => 2025])->id]);

        $this->enroll(['grade_level' => 12, 'learner_type' => 'old_student'])->assertSessionHasNoErrors();

        $this->assertSame(1, Student::count());
        $this->assertSame(2, $student->enrollments()->count());
    }

    public function test_an_lrn_that_belongs_to_someone_else_is_refused(): void
    {
        Student::factory()->create(['lrn' => '123456789012', 'last_name' => 'GARCIA', 'first_name' => 'PEDRO', 'birthdate' => '2008-01-01']);

        $this->enroll()->assertSessionHasErrors('lrn');
        $this->assertSame(0, Enrollment::count());
    }

    public function test_a_lookalike_learner_needs_confirmation(): void
    {
        Student::factory()->create(['lrn' => '999999999999', 'last_name' => 'DELA CRUZ', 'first_name' => 'JUAN', 'birthdate' => '2009-03-15']);

        $this->enroll()->assertSessionHasErrors('possible_duplicate');
        $this->assertSame(0, Enrollment::count());

        $this->enroll(['confirm_not_duplicate' => true])->assertSessionHasNoErrors();
        $this->assertSame(1, Enrollment::count());
    }

    /**
     * Two staff press Submit for the same new learner at the same instant.
     * Both pass the "already exists?" check and the database lets only one
     * insert through. Here the competing row is inserted just before ours so
     * the real unique-constraint error fires. (It shares our transaction, so
     * it rolls back too; the end-to-end suite covers true parallel requests.)
     */
    public function test_losing_a_simultaneous_save_gives_a_clear_message_not_a_crash(): void
    {
        Student::creating(function (Student $s) {
            if (! DB::table('students')->where('lrn', $s->lrn)->exists()) {
                DB::table('students')->insert([
                    'lrn' => $s->lrn, 'last_name' => 'DELA CRUZ', 'first_name' => 'JUAN', 'sex' => 'male',
                    'country' => 'Philippines', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->enroll()->assertStatus(302)->assertSessionHasErrors('lrn');

        $this->assertStringContainsString('Someone else just saved a learner with this LRN', session('errors')->first('lrn'));
        $this->assertSame(0, Enrollment::count(), 'Nothing half-saved may remain.');
    }

    public function test_lrn_lookup_reports_existing_learners(): void
    {
        $this->actingAs($this->registrar)->getJson('/students/lookup?lrn=123456789012')->assertOk()->assertJson(['student' => null]);

        $this->enroll();

        $this->actingAs($this->registrar)->getJson('/students/lookup?lrn=123456789012')
            ->assertOk()
            ->assertJsonPath('student.full_name', 'DELA CRUZ, JUAN SANTOS')
            ->assertJsonPath('enrollment.status', 'enrolled');

        $this->actingAs($this->registrar)->getJson('/students/lookup?lrn=12')->assertUnprocessable();
    }

    public function test_encoding_needs_an_active_school_year(): void
    {
        $this->year->update(['is_active' => false]);

        $this->actingAs($this->registrar)->get('/enrollments/create')->assertRedirect('/dashboard');
    }

    public function test_only_the_required_fields_are_needed(): void
    {
        // Found by the end-to-end burst test: leaving out optional fields such
        // as country crashed the request instead of using defaults.
        $this->actingAs($this->registrar)->post('/enrollments', [
            'grade_level' => 11,
            'strand_id' => Strand::where('code', 'ABM')->value('id'),
            'learner_type' => 'new_student',
            'lrn' => '111122223333',
            'last_name' => 'Minimal',
            'first_name' => 'Entry',
            'birthdate' => '2009-01-01',
            'sex' => 'female',
            'barangay' => 'Don Jose',
            'municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'mother_last_name' => 'Reyes',
            'contact_number' => '09171234567',
        ])->assertSessionHasNoErrors()->assertRedirect('/enrollments/create');

        $this->assertSame('Philippines', Student::sole()->country);
    }
}
