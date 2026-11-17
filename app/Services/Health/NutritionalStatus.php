<?php

namespace App\Services\Health;

use App\Enums\Sex;
use Carbon\CarbonInterface;

/**
 * BMI-for-age and height-for-age for School Form 8, from the WHO Growth
 * Reference 2007 (ages 5 to 19), by sex and age in completed months.
 *
 * BMI-for-age:  below -3 SD severely wasted, -3 to below -2 SD wasted,
 *               -2 to +1 SD normal, above +1 to +2 SD overweight, above +2 SD obese.
 * Height-for-age: below -3 SD severely stunted, -3 to below -2 SD stunted,
 *               -2 to +2 SD normal, above +2 SD tall.
 */
class NutritionalStatus
{
    private const DATA = 'data/who-growth-reference-2007.json';

    /** @var array<string, mixed>|null */
    private static ?array $reference = null;

    /**
     * @return array{age_months: ?int, bmi: ?float, bmi_category: ?string, height_for_age: ?string, note: ?string}
     */
    public static function assess(Sex $sex, ?CarbonInterface $birthdate, ?float $weightKg, ?float $heightM, CarbonInterface $measuredOn): array
    {
        $ageMonths = $birthdate ? (int) $birthdate->diffInMonths($measuredOn) : null;
        $bmi = $weightKg && $heightM ? round($weightKg / ($heightM * $heightM), 1) : null;
        $result = ['age_months' => $ageMonths, 'bmi' => $bmi, 'bmi_category' => null, 'height_for_age' => null, 'note' => null];

        if ($ageMonths === null) {
            return [...$result, 'note' => 'No birth date'];
        }
        if (! $weightKg || ! $heightM) {
            return [...$result, 'note' => 'No weight or height on record'];
        }

        $ref = self::reference();
        if ($ageMonths < $ref['first_month'] || $ageMonths > $ref['last_month']) {
            return [...$result, 'note' => 'Age outside the WHO 5-19 reference'];
        }

        $key = $sex === Sex::Male ? 'male' : 'female';
        $index = $ageMonths - $ref['first_month'];
        $bmiZ = self::z($bmi, ...$ref['bmi'][$key][$index]);
        $heightZ = self::z(round($heightM * 100, 1), ...$ref['height_cm'][$key][$index]);

        return [
            ...$result,
            'bmi_category' => match (true) {
                $bmiZ < -3 => 'Severely Wasted',
                $bmiZ < -2 => 'Wasted',
                $bmiZ <= 1 => 'Normal',
                $bmiZ <= 2 => 'Overweight',
                default => 'Obese',
            },
            'height_for_age' => match (true) {
                $heightZ < -3 => 'Severely Stunted',
                $heightZ < -2 => 'Stunted',
                $heightZ <= 2 => 'Normal',
                default => 'Tall',
            },
        ];
    }

    /** The measurement at a given z-score, e.g. the BMI where "overweight" starts. */
    public static function cutoff(Sex $sex, string $measure, int $ageMonths, float $z): float
    {
        $ref = self::reference();
        [$l, $m, $s] = $ref[$measure][$sex === Sex::Male ? 'male' : 'female'][$ageMonths - $ref['first_month']];

        return $l == 0 ? $m * exp($s * $z) : $m * (1 + $l * $s * $z) ** (1 / $l);
    }

    /** The WHO LMS z-score. */
    private static function z(float $x, float $l, float $m, float $s): float
    {
        return $l == 0 ? log($x / $m) / $s : (($x / $m) ** $l - 1) / ($l * $s);
    }

    /** @return array<string, mixed> */
    private static function reference(): array
    {
        return self::$reference ??= json_decode(file_get_contents(resource_path(self::DATA)), true, flags: JSON_THROW_ON_ERROR);
    }
}
