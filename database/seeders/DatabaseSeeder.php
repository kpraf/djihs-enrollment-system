<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Safe to run on every deploy: each seeder skips work already done.
     */
    public function run(): void
    {
        $this->call([
            StrandSeeder::class,
            SchoolYearSeeder::class,
            AdminUserSeeder::class,
        ]);

        if (app()->environment('local') || config('school.demo')) {
            $this->call(DemoSeeder::class);

            return;
        }

        // Demo accounts all share the password "password". Once demo data is
        // turned off, make sure nobody can still sign in with them.
        $switchedOff = User::where('username', 'like', 'demo.%')->where('is_active', true)->update(['is_active' => false]);
        if ($switchedOff > 0) {
            $this->command?->warn("Switched off {$switchedOff} demo accounts. Set DEMO_DATA=true to turn them back on.");
        }
    }
}
