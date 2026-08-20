<?php

namespace NBCSIT\Sso\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use NBCSIT\Saml2\Models\Tenant;
use NBCSIT\Sso\Database\Factories\IdentityProviderFactory;
use NBCSIT\Sso\Metadata\IdpMetadataSynchroniser;
use NBCSIT\Sso\Support\Certificate;
use NBCSIT\Sso\Support\MetadataChange;

/**
 * A SAML identity provider — the vendor package's tenant row, plus everything
 * this package needs to keep it current.
 *
 * It is deliberately the same table and the same records: the vendor package
 * resolves tenants through its own repository during a login, and nothing here
 * may make that stop working. This subclass exists only so the admin screens can
 * reach the columns `database/migrations/..._add_metadata_source_to_saml2_tenants_table`
 * added, which the vendor model does not know about and would therefore refuse
 * to mass-assign.
 *
 * @property int $id
 * @property string $uuid
 * @property string $key
 * @property string $idp_entity_id
 * @property string $idp_login_url
 * @property string $idp_logout_url
 * @property string $idp_x509_cert
 * @property list<string>|null $idp_x509_cert_multi
 * @property string|null $relay_state_url
 * @property string $name_id_format
 * @property bool $enabled
 * @property array<string, mixed> $metadata
 * @property string|null $metadata_url
 * @property bool $metadata_auto_refresh
 * @property string|null $metadata_fingerprint
 * @property Carbon|null $metadata_checked_at
 * @property Carbon|null $metadata_synced_at
 * @property string|null $metadata_error
 * @property array<int, array<string, mixed>>|null $pending_metadata
 * @property Carbon|null $pending_metadata_at
 * @property list<string>|null $pending_certificates
 * @property-read Collection<int, SamlMetadataEvent> $metadataEvents
 */
class IdentityProvider extends Tenant
{
    /** @use HasFactory<IdentityProviderFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'uuid',
        'key',
        'idp_entity_id',
        'idp_login_url',
        'idp_logout_url',
        'idp_x509_cert',
        'idp_x509_cert_multi',
        'relay_state_url',
        'name_id_format',
        'enabled',
        'metadata',
        'metadata_url',
        'metadata_auto_refresh',
        'metadata_fingerprint',
        'metadata_checked_at',
        'metadata_synced_at',
        'metadata_error',
        'pending_metadata',
        'pending_metadata_at',
        'pending_certificates',
    ];

    /**
     * Merged over the vendor model's `metadata => array`, not instead of it.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'idp_x509_cert_multi' => 'array',
            'enabled' => 'boolean',
            'metadata_auto_refresh' => 'boolean',
            'metadata_checked_at' => 'datetime',
            'metadata_synced_at' => 'datetime',
            'pending_metadata' => 'array',
            'pending_metadata_at' => 'datetime',
            'pending_certificates' => 'array',
        ];
    }

    /**
     * Laravel guesses `Database\Factories\IdentityProviderFactory`, which
     * resolves into the consuming application rather than into this package.
     */
    protected static function newFactory(): IdentityProviderFactory
    {
        return IdentityProviderFactory::new();
    }

    /**
     * @return HasMany<SamlMetadataEvent, $this>
     */
    public function metadataEvents(): HasMany
    {
        return $this->hasMany(SamlMetadataEvent::class, 'tenant_id');
    }

    /**
     * Providers a scheduled run should fetch: refresh switched on, and a URL to
     * fetch from. A provider added from an uploaded file has no URL and is
     * therefore never touched unattended, which is the correct behaviour for a
     * file that arrived once by email.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function autoRefreshable(Builder $query): void
    {
        $query->where('metadata_auto_refresh', true)
            ->whereNotNull('metadata_url')
            ->where('metadata_url', '!=', '');
    }

    /**
     * Every signing certificate in use, newest column first and falling back to
     * the single-certificate column for providers added before metadata import
     * existed.
     *
     * @return list<string>
     */
    public function signingCertificateBodies(): array
    {
        $certificates = $this->idp_x509_cert_multi ?? [];

        if ($certificates === []) {
            return $this->idp_x509_cert === '' ? [] : [$this->idp_x509_cert];
        }

        return $certificates;
    }

    /**
     * @return list<Certificate>
     */
    public function signingCertificates(): array
    {
        return array_map(Certificate::fromBase64(...), $this->signingCertificateBodies());
    }

    /**
     * Endpoint changes found by a refresh and not written. See
     * {@see IdpMetadataSynchroniser}.
     *
     * @return list<MetadataChange>
     */
    public function pendingChanges(): array
    {
        return array_map(MetadataChange::fromArray(...), array_values($this->pending_metadata ?? []));
    }

    public function hasPendingChanges(): bool
    {
        return $this->pendingChanges() !== [];
    }
}
