<?php

namespace App\Console\Commands;

use App\Models\SchoolYear;
use App\Models\User;
use App\Services\Import\EncodingSheetImporter;
use Illuminate\Console\Command;

class ImportEncodingSheet extends Command
{
    protected $signature = 'enrollments:import
        {file : Path to the ENROLLMENT_ENCODING .xlsx workbook}
        {--school-year= : School year such as 2024-2025. Created if missing.}
        {--as= : Username to record as the encoder}
        {--dry-run : Check the file without saving anything}';

    protected $description = "Import learners from the school's enrollment encoding workbook";

    public function handle(EncodingSheetImporter $importer): int
    {
        $file = $this->argument('file');
        if (! is_readable($file)) {
            $this->error("Cannot read {$file}.");

            return self::FAILURE;
        }

        $label = $this->option('school-year');
        if ($label === null && preg_match('/SY\s*(\d{4})/i', basename($file), $m)) {
            $label = $m[1];
        }

        if ($label === null || ! preg_match('/^(\d{4})/', $label, $m)) {
            $this->error('Pass --school-year=YYYY-YYYY.');

            return self::FAILURE;
        }

        $year = SchoolYear::firstOrCreate(['start_year' => (int) $m[1]]);
        $actor = $this->option('as') ? User::where('username', $this->option('as'))->first() : null;

        $result = $importer->import($file, $year, $actor, (bool) $this->option('dry-run'));

        $this->info(($result->dryRun ? 'Dry run, nothing saved. ' : '')."School year {$year->name}");
        $this->table(['', 'Count'], [
            ['Rows read', $result->rowsRead],
            ['Students created', $result->studentsCreated],
            ['Students updated', $result->studentsUpdated],
            ['Enrollments created', $result->enrollmentsCreated],
            ['Enrollments updated', $result->enrollmentsUpdated],
            ['Rows skipped', count($result->skipped)],
            ['Warnings', count($result->warnings)],
        ]);

        foreach ($result->skipped as $s) {
            $this->error("Row {$s['row']} skipped: {$s['message']}");
        }

        foreach ($result->warnings as $w) {
            $this->warn("Row {$w['row']}: {$w['message']}");
        }

        return self::SUCCESS;
    }
}
