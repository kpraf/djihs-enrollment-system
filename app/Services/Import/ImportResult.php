<?php

namespace App\Services\Import;

class ImportResult
{
    public int $rowsRead = 0;

    public int $studentsCreated = 0;

    public int $studentsUpdated = 0;

    public int $enrollmentsCreated = 0;

    public int $enrollmentsUpdated = 0;

    /** @var list<array{row: int, message: string}> */
    public array $skipped = [];

    /** @var list<array{row: int, message: string}> */
    public array $warnings = [];

    public function __construct(public readonly bool $dryRun) {}

    public function warn(int $row, string $message): void
    {
        $this->warnings[] = ['row' => $row, 'message' => $message];
    }

    public function skip(int $row, string $message): void
    {
        $this->skipped[] = ['row' => $row, 'message' => $message];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'rows_read' => $this->rowsRead,
            'students_created' => $this->studentsCreated,
            'students_updated' => $this->studentsUpdated,
            'enrollments_created' => $this->enrollmentsCreated,
            'enrollments_updated' => $this->enrollmentsUpdated,
            'skipped' => $this->skipped,
            'warnings' => $this->warnings,
        ];
    }
}
