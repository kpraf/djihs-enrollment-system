<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Enrollment;
use App\Models\SchoolYear;
use App\Models\Strand;
use App\Models\User;
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
            ])
            ->create();
    }
}
