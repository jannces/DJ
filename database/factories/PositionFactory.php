<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Position> */
class PositionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->unique()->jobTitle(),
            'salary_grade' => 'SG-'.fake()->numberBetween(1, 24),
        ];
    }
}
