<?php

namespace Database\Factories;

use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\LeaveBalance> */
class LeaveBalanceFactory extends Factory
{
    public function definition(): array
    {
        $earned = fake()->randomFloat(3, 5, 30);

        return [
            'user_id' => User::factory(),
            'leave_type_id' => LeaveType::factory(),
            'earned' => $earned,
            'used' => 0,
            'balance' => $earned,
        ];
    }
}
