<?php

namespace Database\Factories;

use App\Enums\Track;
use App\Models\Strand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Strand>
 */
class StrandFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->words(3, true),
            'track' => fake()->randomElement(Track::cases()),
            'is_active' => true,
        ];
    }
}
