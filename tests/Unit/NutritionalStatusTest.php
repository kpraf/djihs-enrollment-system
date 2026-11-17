<?php

namespace Tests\Unit;

use App\Enums\Sex;
use App\Services\Health\NutritionalStatus;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class NutritionalStatusTest extends TestCase
{
    private function assess(Sex $sex, string $age, ?float $kg, ?float $m): array
    {
        // A birth date that makes the learner exactly $age ("13y 0m") on 2026-07-01.
        [$y, $mo] = sscanf($age, '%dy %dm');
        $measured = CarbonImmutable::parse('2026-07-01');

        return NutritionalStatus::assess($sex, $measured->subYears($y)->subMonths($mo), $kg, $m, $measured);
    }

    public function test_cutoffs_match_the_published_who_2007_tables(): void
    {
        // BMI-for-age, 5 years 1 month (WHO 2007 boys and girls tables).
        $this->assertSame(16.6, round(NutritionalStatus::cutoff(Sex::Male, 'bmi', 61, 1), 1));
        $this->assertSame(18.3, round(NutritionalStatus::cutoff(Sex::Male, 'bmi', 61, 2), 1));
        $this->assertSame(13.0, round(NutritionalStatus::cutoff(Sex::Male, 'bmi', 61, -2), 1));
        $this->assertSame(16.9, round(NutritionalStatus::cutoff(Sex::Female, 'bmi', 61, 1), 1));
        // At 19 years, +1 SD and +2 SD are close to the adult BMI 25 and 30.
        $this->assertSame(25.4, round(NutritionalStatus::cutoff(Sex::Male, 'bmi', 228, 1), 1));
        $this->assertSame(29.7, round(NutritionalStatus::cutoff(Sex::Male, 'bmi', 228, 2), 1));
        $this->assertSame(25.0, round(NutritionalStatus::cutoff(Sex::Female, 'bmi', 228, 1), 1));
    }

    public function test_overweight_starts_above_plus_one_sd(): void
    {
        // An 18-year-old boy with BMI 29: overweight, not normal.
        $this->assertSame('Overweight', $this->assess(Sex::Male, '18y 0m', 29 * 1.7 * 1.7, 1.7)['bmi_category']);
        $this->assertSame('Obese', $this->assess(Sex::Male, '18y 0m', 31 * 1.7 * 1.7, 1.7)['bmi_category']);
        $this->assertSame('Normal', $this->assess(Sex::Male, '18y 0m', 21 * 1.7 * 1.7, 1.7)['bmi_category']);
    }

    public function test_boys_and_girls_use_their_own_charts(): void
    {
        // BMI 21.0 at 13: above +1 SD for boys (20.8), below it for girls (21.8).
        $kg = 21.0 * 1.5 * 1.5;
        $this->assertSame('Overweight', $this->assess(Sex::Male, '13y 0m', $kg, 1.5)['bmi_category']);
        $this->assertSame('Normal', $this->assess(Sex::Female, '13y 0m', $kg, 1.5)['bmi_category']);
    }

    public function test_height_for_age_uses_each_learners_own_age(): void
    {
        $this->assertSame('Stunted', $this->assess(Sex::Female, '13y 0m', 40, 1.40)['height_for_age']);
        $this->assertSame('Normal', $this->assess(Sex::Female, '9y 0m', 30, 1.40)['height_for_age']);
        $this->assertSame('Tall', $this->assess(Sex::Male, '13y 0m', 60, 1.80)['height_for_age']);
        $this->assertSame('Severely Stunted', $this->assess(Sex::Male, '13y 0m', 30, 1.30)['height_for_age']);
    }

    public function test_missing_data_and_ages_outside_the_reference_get_a_note(): void
    {
        $this->assertSame('No weight or height on record', $this->assess(Sex::Male, '15y 0m', null, 1.6)['note']);
        $this->assertSame('Age outside the WHO 5-19 reference', $this->assess(Sex::Male, '19y 1m', 60, 1.7)['note']);
        $this->assertNull($this->assess(Sex::Male, '19y 0m', 60, 1.7)['note']);
        $this->assertSame(20.8, $this->assess(Sex::Male, '15y 0m', 60, 1.7)['bmi']);
    }
}
