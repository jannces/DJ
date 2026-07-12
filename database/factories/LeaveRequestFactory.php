<?php

namespace Database\Factories;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\LeaveRequest> */
class LeaveRequestFactory extends Factory
{
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('+1 week', '+1 month');
        $end = (clone $start)->modify('+2 days');

        return [
            'reference_no' => 'LV-'.now()->year.'-'.fake()->unique()->numerify('#####'),
            'user_id' => User::factory(),
            'leave_type_id' => LeaveType::factory(),
            'date_filed' => now()->toDateString(),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'working_days' => 3,
            'purpose' => fake()->sentence(),
            'status' => LeaveRequest::STATUS_DEPT_REVIEW,
            'current_step' => 0,
            'office_snapshot' => 'Demo Office',
            'position_snapshot' => 'Demo Position',
            'salary_snapshot' => 25000,
            'applicant_signature' => fake()->name(),
        ];
    }
}
