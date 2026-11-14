<?php

namespace Database\Seeders;

use App\Models\SchoolYear;
use Illuminate\Database\Seeder;

class SchoolYearSeeder extends Seeder
{
    public function run(): void
    {
        if (SchoolYear::exists()) {
            return;
        }

        SchoolYear::create([
            'start_year' => SchoolYear::currentStartYear(),
            'is_active' => true,
            'is_enrollment_open' => true,
        ]);
    }
}
