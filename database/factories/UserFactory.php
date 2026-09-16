<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => '+91'.fake()->unique()->numerify('9#########'),
            'email_verified_at' => now(),
            'mobile_verified_at' => now(),
            'password' => static::$password ??= bcrypt('Password123!'),
            'role' => Role::Student,
            'status' => UserStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => Role::Admin]);
    }

    /** The founding account, which holds every permission implicitly. */
    public function owner(): static
    {
        return $this->state(fn () => ['role' => Role::Admin, 'is_owner' => true]);
    }

    public function client(): static
    {
        return $this->state(fn () => ['role' => Role::Client]);
    }

    public function student(): static
    {
        return $this->state(fn () => ['role' => Role::Student]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => [
            'email_verified_at' => null,
            'mobile_verified_at' => null,
        ]);
    }

    /** An account created by an administrator, still holding its issued password. */
    public function mustChangePassword(): static
    {
        return $this->state(fn () => ['must_change_password' => true]);
    }

    /** Signed up through Google, so there is no password at all. */
    public function passwordless(): static
    {
        return $this->state(fn () => ['password' => null]);
    }
}
