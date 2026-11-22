<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected $model = \App\Models\User::class;

    public function definition(): array
    {
        return [
            'name' => 'Test User',
            'email' => Str::uuid() . '@example.test',
            'phone' => null,
            'password' => Hash::make('password'),
            'status' => 'active',
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }
}
