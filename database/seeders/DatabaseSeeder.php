<?php

namespace Database\Seeders;

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

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
