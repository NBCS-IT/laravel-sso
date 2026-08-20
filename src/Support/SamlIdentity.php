<?php

namespace NBCSIT\Sso\Support;

use Illuminate\Support\Carbon;

/**
 * The parts of a SAML response this application actually uses.
 *
 * The listener flattens the OneLogin objects into this so the sign-in logic can
 * be exercised without standing up a whole IdP response.
 */
final readonly class SamlIdentity
{
    /**
     * Attribute values are whatever the identity provider sent, so they are
     * typed as loosely as they arrive; the accessors below do the narrowing.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $nameId,
        public array $attributes,
        public ?string $messageId = null,
        public ?Carbon $notOnOrAfter = null,
        public ?string $assertionId = null,
    ) {}

    /**
     * The value replay detection is keyed on.
     *
     * The assertion ID whenever the toolkit has one: it sits inside the signed
     * region, where the <samlp:Response> envelope's own ID does not. Under
     * assertion-only signing — the default at Entra ID, and the configuration
     * this package's security floor settles for — the envelope can be rebuilt
     * around an unexpired assertion without disturbing the signature, so keying
     * on the envelope's ID would let the same assertion in twice.
     *
     * The message ID stays as a fallback rather than a preference: it is worth
     * something against a toolkit or provider that reports no assertion ID, and
     * nothing is lost by trying it second.
     */
    public function replayKey(): ?string
    {
        foreach ([$this->assertionId, $this->messageId] as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The first value of an attribute, or null when the IdP did not send it.
     */
    public function attribute(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        $values = $this->attributes[$name] ?? null;

        if (! is_array($values) || $values === []) {
            return null;
        }

        $first = $values[0];

        return is_scalar($first) ? trim((string) $first) : null;
    }

    /**
     * Every value of a multi-valued attribute, e.g. group membership.
     *
     * @return list<string>
     */
    public function attributeValues(?string $name): array
    {
        if ($name === null || $name === '') {
            return [];
        }

        $values = $this->attributes[$name] ?? null;

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $value) => trim((string) $value),
            array_filter($values, 'is_scalar'),
        ));
    }
}
