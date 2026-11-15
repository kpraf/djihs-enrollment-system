<?php

namespace Database\Seeders;

use App\Enums\EnrollmentStatus;
use App\Enums\Role;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Strand;
use App\Models\User;
use App\Services\Sections\SectionAssigner;
use Illuminate\Database\Seeder;

/**
 * Fake learners and one account per role, for local development only.
 * Every demo account uses the password "password".
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Role::cases() as $role) {
            User::firstOrCreate(['username' => 'demo.'.$role->value], [
                'first_name' => 'Demo',
                'last_name' => $role->label(),
                'role' => $role,
                'password' => 'password',
            ]);
        }

        $year = SchoolYear::active();
        if ($year === null || $year->enrollments()->exists()) {
            return;
        }

        $encoder = User::where('username', 'demo.registrar')->first();
        $strands = Strand::all();

        Enrollment::factory()
            ->count(120)
            ->sequence(fn () => [
                'school_year_id' => $year->id,
                'strand_id' => $strands->random()->id,
                'encoded_by' => $encoder?->id,
                'status' => fake()->randomElement([...array_fill(0, 8, EnrollmentStatus::Enrolled), EnrollmentStatus::Pending, EnrollmentStatus::Cancelled]),
            ])
            ->create();

        // One section per strand and grade, the demo adviser on the first,
        // then place about half the enrolled learners.
        $adviser = User::where('username', 'demo.adviser')->first();
        $teacher = User::where('username', 'demo.subject_teacher')->first();
        $names = ['Integrity', 'Honesty', 'Curiosity', 'Assertive', 'Diligence'];
        foreach ([11, 12] as $grade) {
            foreach ($strands->values() as $i => $strand) {
                $section = Section::create([
                    'school_year_id' => $year->id, 'grade_level' => $grade, 'strand_id' => $strand->id,
                    'name' => $names[$i % count($names)], 'capacity' => 20,
                    'adviser_id' => $grade === 11 && $i === 0 ? $adviser?->id : null,
                ]);
                if ($grade === 11 && $i === 0 && $teacher) {
                    $section->teacherAssignments()->create(['user_id' => $teacher->id, 'subject_name' => 'General Mathematics', 'subject_code' => 'GENMATH']);
                }
            }
            app(SectionAssigner::class)->autoAssign($year->id, $grade, null);
        }
        Enrollment::where('school_year_id', $year->id)->whereNotNull('section_id')->inRandomOrder()->limit(30)->update(['section_id' => null]);
    }
}
