<?php

namespace NBCSIT\Sso\Support;

use Illuminate\Support\Str;
use NBCSIT\Sso\Enums\SamlMetadataOutcome;
use NBCSIT\Sso\Models\IdentityProvider;

/**
 * What reading a metadata document did, in the terms the administrator who
 * triggered it — or who reads the log afterwards — needs.
 *
 * The split between `applied` and `pending` is the whole type. "Two things
 * changed" is not an answer; "the certificate is in use, the sign-in URL is
 * waiting for you" is.
 */
final readonly class MetadataSyncReport
{
    /**
     * @param  list<MetadataChange>  $applied
     * @param  list<MetadataChange>  $pending
     * @param  list<string>  $warnings
     */
    private function __construct(
        public SamlMetadataOutcome $outcome,
        public string $message,
        public array $applied = [],
        public array $pending = [],
        public array $warnings = [],
        public ?IdentityProvider $provider = null,
    ) {}

    /**
     * @param  list<string>  $warnings
     */
    public static function failed(string $message, array $warnings = [], ?IdentityProvider $provider = null): self
    {
        return new self(SamlMetadataOutcome::Failed, $message, [], [], $warnings, $provider);
    }

    /**
     * @param  list<string>  $warnings
     */
    public static function created(IdentityProvider $provider, array $warnings): self
    {
        return new self(
            SamlMetadataOutcome::Created,
            "Added \"{$provider->key}\" from its metadata. Check it over, then choose it below and save.",
            [],
            [],
            $warnings,
            $provider,
        );
    }

    /**
     * @param  list<string>  $warnings
     */
    public static function unchanged(IdentityProvider $provider, array $warnings = []): self
    {
        return new self(
            SamlMetadataOutcome::Unchanged,
            "\"{$provider->key}\" is already up to date.",
            [],
            [],
            $warnings,
            $provider,
        );
    }

    /**
     * @param  list<MetadataChange>  $applied
     * @param  list<MetadataChange>  $pending
     * @param  list<string>  $warnings
     */
    public static function changed(IdentityProvider $provider, array $applied, array $pending, array $warnings): self
    {
        $outcome = $applied === [] ? SamlMetadataOutcome::Held : SamlMetadataOutcome::Updated;

        $message = $applied === []
            ? "Nothing was changed on \"{$provider->key}\"."
            : 'Applied '.count($applied).' '.Str::plural('change', count($applied))." to \"{$provider->key}\".";

        if ($pending !== []) {
            $message .= ' '.count($pending).' '.Str::plural('change', count($pending))
                .' to its entity ID or endpoints '.(count($pending) === 1 ? 'is' : 'are')
                .' waiting for you to apply or discard.';
        }

        return new self($outcome, $message, $applied, $pending, $warnings, $provider);
    }

    /**
     * @param  list<MetadataChange>  $discarded
     */
    public static function discarded(IdentityProvider $provider, array $discarded): self
    {
        return new self(
            SamlMetadataOutcome::Held,
            'Discarded '.count($discarded).' held '.Str::plural('change', count($discarded))." on \"{$provider->key}\". "
                ."\"{$provider->key}\" keeps the entity ID and endpoints it already had.",
            [],
            $discarded,
            [],
            $provider,
        );
    }

    public function succeeded(): bool
    {
        return $this->outcome->succeeded();
    }

    /**
     * @return list<MetadataChange>
     */
    public function changes(): array
    {
        return [...$this->applied, ...$this->pending];
    }
}
