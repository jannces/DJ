<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<\App\Models\User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= 'Secret!Passw0rd#1',
            'status' => 'active',
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => [
            'status' => 'blocked',
            'blocked_until' => now()->addDay(),
            'blocked_reason' => 'Exceeded maximum failed login attempts',
        ]);
    }
}
