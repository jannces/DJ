<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\LeaveType> */
class LeaveTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('????')),
            'name' => fake()->words(2, true).' Leave',
            'category' => 'regular',
            'deductible' => false,
            'approval_flow' => ['department_head', 'hr', 'mayor'],
            'active' => true,
        ];
    }
}
