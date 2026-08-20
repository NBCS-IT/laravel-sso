<?php

namespace NBCSIT\Sso\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use NBCSIT\Sso\Models\IdentityProvider;
use Ramsey\Uuid\Uuid;

/**
 * @extends Factory<IdentityProvider>
 */
class IdentityProviderFactory extends Factory
{
    protected $model = IdentityProvider::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = fake()->domainName();

        return [
            'uuid' => Uuid::uuid4()->toString(),
            'key' => fake()->company(),
            'idp_entity_id' => 'https://'.$domain.'/saml',
            'idp_login_url' => 'https://'.$domain.'/saml/sso',
            'idp_logout_url' => 'https://'.$domain.'/saml/slo',

            // Certificate-shaped, not a certificate. A test that cares what is
            // inside one passes its own; everything else only needs a value
            // that round-trips through the column.
            'idp_x509_cert' => base64_encode(fake()->sha256()),
            'metadata' => [],
        ];
    }

    public function withMetadataUrl(string $url = 'https://idp.example.edu.au/federationmetadata.xml'): static
    {
        return $this->state(fn () => [
            'metadata_url' => $url,
            'metadata_auto_refresh' => true,
        ]);
    }
}
