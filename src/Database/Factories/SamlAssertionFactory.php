<?php

namespace NBCSIT\Sso\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use NBCSIT\Sso\Models\SamlAssertion;

/**
 * @extends Factory<SamlAssertion>
 */
class SamlAssertionFactory extends Factory
{
    protected $model = SamlAssertion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_id' => '_'.fake()->unique()->sha1(),
            'not_on_or_after' => now()->addMinutes(5),
        ];
    }
}
