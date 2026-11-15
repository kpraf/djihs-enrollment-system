<?php

namespace Database\Seeders;

use App\Enums\Track;
use App\Models\Strand;
use Illuminate\Database\Seeder;

/**
 * The strands DJIHS offers, taken from the DATABASE sheet of the school's
 * encoding workbook. The codes are what the importer matches on.
 */
class StrandSeeder extends Seeder
{
    public function run(): void
    {
        $strands = [
            ['code' => 'ABM', 'name' => 'Accountancy, Business and Management', 'track' => Track::Academic],
            ['code' => 'HUMSS', 'name' => 'Humanities and Social Sciences', 'track' => Track::Academic],
            ['code' => 'ICT-CSS', 'name' => 'ICT: Computer Systems Servicing NC II', 'track' => Track::Tvl],
            ['code' => 'HE-COOKERY', 'name' => 'Home Economics: Cookery / Bread and Pastry / Food and Beverage Services NC II', 'track' => Track::Tvl],
            ['code' => 'IA-EIM', 'name' => 'Industrial Arts: Electrical Installation and Maintenance NC II', 'track' => Track::Tvl],
        ];

        foreach ($strands as $order => $strand) {
            Strand::firstOrCreate(['code' => $strand['code']], [...$strand, 'sort_order' => $order]);
        }
    }
}
