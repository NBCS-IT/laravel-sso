<?php

namespace NBCSIT\Sso\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use NBCSIT\Sso\Enums\SamlMetadataOutcome;
use NBCSIT\Sso\Enums\SamlMetadataSource;
use NBCSIT\Sso\Models\IdentityProvider;
use NBCSIT\Sso\Models\SamlMetadataEvent;

/**
 * @extends Factory<SamlMetadataEvent>
 */
class SamlMetadataEventFactory extends Factory
{
    protected $model = SamlMetadataEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => IdentityProvider::factory(),
            'provider_name' => fake()->company(),
            'source' => SamlMetadataSource::Url,
            'outcome' => SamlMetadataOutcome::Updated,
            'metadata_url' => 'https://idp.example.edu.au/federationmetadata.xml',
            'message' => 'Applied 1 change.',
            'change_set' => [],
            'warnings' => [],

            // Null is a scheduled run, which is the common case; a test that
            // wants an actor passes one built from its own user model.
            'user_id' => null,
        ];
    }
}
