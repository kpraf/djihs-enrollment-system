<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\Strand;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Known accounts for the browser test suite in tests/e2e.
 * Every account's password is "Password123".
 */
class E2ESeeder extends Seeder
{
    public const PASSWORD = 'Password123';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The end-to-end seeder must never run in production.');
        }

        $this->call(StrandSeeder::class);

        SchoolYear::create(['start_year' => 2025, 'is_active' => false]);
        SchoolYear::create(['start_year' => SchoolYear::currentStartYear(), 'is_active' => true, 'is_enrollment_open' => true, 'grade_10_completers' => 300]);

        $accounts = [
            ['admin', 'Ana', 'Admin', Role::Admin],
            ['registrar', 'Rita', 'Registrar', Role::Registrar],
            ['registrar2', 'Rosa', 'Encoder', Role::Registrar],
            ['ict', 'Ian', 'Coordinator', Role::IctCoordinator],
            ['keyteacher', 'Karen', 'Key', Role::KeyTeacher],
            ['adviser', 'Arnel', 'Adviser', Role::Adviser],
            ['subject', 'Sonia', 'Subject', Role::SubjectTeacher],
        ];

        foreach ($accounts as [$username, $first, $last, $role]) {
            User::create(['username' => $username, 'first_name' => $first, 'last_name' => $last, 'role' => $role, 'password' => self::PASSWORD]);
        }

        // A deliberately tiny section, so tests can race for the last seat.
        $year = SchoolYear::active();
        $humss = Strand::where('code', 'HUMSS')->first();
        $section = Section::create([
            'school_year_id' => $year->id, 'grade_level' => 11, 'strand_id' => $humss->id, 'name' => 'Integrity', 'capacity' => 2,
            'adviser_id' => User::where('username', 'adviser')->value('id'),
        ]);
        $section->teacherAssignments()->create(['user_id' => User::where('username', 'subject')->value('id'), 'subject_name' => 'General Mathematics', 'subject_code' => 'GENMATH']);

        User::create(['username' => 'newhire', 'first_name' => 'Nina', 'last_name' => 'New', 'role' => Role::Adviser, 'password' => self::PASSWORD, 'must_change_password' => true]);
        User::create(['username' => 'retired', 'first_name' => 'Rey', 'last_name' => 'Retired', 'role' => Role::Adviser, 'password' => self::PASSWORD, 'is_active' => false]);
    }
}
