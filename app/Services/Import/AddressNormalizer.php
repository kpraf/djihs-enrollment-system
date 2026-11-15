<?php

namespace App\Services\Import;

/**
 * Cleans the free-typed address values found in the encoding sheet, such as
 * "STA. ROSA", "STA.ROSA" and "SANTAROSA" all meaning Santa Rosa.
 */
class AddressNormalizer
{
    /** @var array<string, string> */
    private const MUNICIPALITIES = [
        'STA ROSA' => 'SANTA ROSA',
        'STAROSA' => 'SANTA ROSA',
        'SANTAROSA' => 'SANTA ROSA',
        'SANTA ROS' => 'SANTA ROSA',
        'SANTA ROSA CITY' => 'SANTA ROSA',
        'CITY OF SANTA ROSA' => 'SANTA ROSA',
        'BINAN' => 'BIÑAN',
        'BINAN CITY' => 'BIÑAN',
        'CITY OF BINAN' => 'BIÑAN',
        'CABUYAO CITY' => 'CABUYAO',
    ];

    /** @var array<string, string> */
    private const PROVINCES = [
        'LGUNA' => 'LAGUNA',
        'LAHUNA' => 'LAGUNA',
        'LAGUNNA' => 'LAGUNA',
        'CAVTE' => 'CAVITE',
    ];

    /** @var list<string> */
    private const KNOWN_PROVINCES = ['LAGUNA', 'CAVITE', 'BATANGAS', 'RIZAL', 'QUEZON', 'METRO MANILA'];

    public static function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', trim((string) $value));

        return $value === '' ? null : mb_strtoupper($value);
    }

    public static function municipality(mixed $value): ?string
    {
        $value = self::clean($value);
        if ($value === null) {
            return null;
        }

        $key = trim(str_replace(['.', ','], ' ', $value));
        $key = preg_replace('/\s+/', ' ', $key);

        return self::MUNICIPALITIES[$key] ?? self::MUNICIPALITIES[str_replace(' ', '', $key)] ?? $value;
    }

    public static function province(mixed $value): ?string
    {
        $value = self::clean($value);

        return $value === null ? null : (self::PROVINCES[$value] ?? $value);
    }

    public static function looksLikeProvince(?string $value): bool
    {
        return $value !== null && in_array($value, self::KNOWN_PROVINCES, true);
    }

    public static function country(mixed $value): string
    {
        $value = self::clean($value);

        return match (true) {
            $value === null, str_starts_with($value, 'PH') => 'Philippines',
            default => mb_convert_case($value, MB_CASE_TITLE),
        };
    }
}
