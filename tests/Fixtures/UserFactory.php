<?php

namespace NBCSIT\Sso\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'not-a-real-hash',
            'saml_name_id' => null,
            'is_active' => true,
            'last_login_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * A user provisioned through single sign-on: identified by NameID, with no
     * local password.
     */
    public function samlOnly(?string $nameId = null): static
    {
        return $this->state(fn () => [
            'password' => null,
            'saml_name_id' => $nameId ?? fake()->uuid(),
        ]);
    }
}
