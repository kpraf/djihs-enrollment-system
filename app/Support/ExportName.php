<?php

namespace App\Support;

/**
 * File names for everything the system exports, so a downloaded file says
 * what it is without opening it: the school, what it holds, which section or
 * school year, and the day it was exported. For example
 * DJIHS_Class_List_Grade_11_HUMSS_Integrity_SY2026-2027_2026-11-16.csv.
 */
final class ExportName
{
    /**
     * @param  string|null  ...$parts  what the file holds, most general first; blanks are skipped
     */
    public static function make(?string ...$parts): string
    {
        $parts = array_filter($parts, fn (?string $p) => $p !== null && trim($p) !== '');
        // "Grade 11 - HUMSS - Integrity" reads as Grade_11_HUMSS_Integrity.
        $parts = array_map(fn (string $p) => (string) preg_replace('/\s+-\s+/', ' ', $p), $parts);
        $name = implode('_', ['DJIHS', ...$parts, now()->format('Y-m-d')]);

        // Letters, digits and hyphens only, so the name works on every computer.
        return trim((string) preg_replace('/[^A-Za-z0-9-]+/', '_', $name), '_');
    }

    /** "SY2026-2027" for a school year name like "2026-2027". */
    public static function schoolYear(?string $name): ?string
    {
        return $name ? 'SY'.$name : null;
    }
}
