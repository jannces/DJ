<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\EmployeeProfile> */
class EmployeeProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'employee_no' => 'EMP-'.fake()->unique()->numerify('####'),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->lastName(),
            'last_name' => fake()->lastName(),
            'gender' => fake()->randomElement(['male', 'female']),
            'civil_status' => fake()->randomElement(['single', 'married']),
            'birth_date' => fake()->date('Y-m-d', '-25 years'),
            'contact_no' => fake()->numerify('09#########'),
            'address' => fake()->streetAddress().', Alicia',
            'salary' => fake()->randomFloat(2, 15000, 60000),
            'department_id' => Department::factory(),
            'position_id' => Position::factory(),
            'employment_status' => 'permanent',
            'date_hired' => fake()->date('Y-m-d', '-1 year'),
        ];
    }
}
