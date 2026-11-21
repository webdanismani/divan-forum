<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'username' => 'user_'.Str::lower(Str::random(8)),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'user_group_id' => fn () => UserGroup::system('registered')->id,
            'remember_token' => Str::random(10),
        ];
    }

    public function inGroup(string $systemKey): static
    {
        return $this->state(fn () => ['user_group_id' => UserGroup::system($systemKey)->id]);
    }
}
