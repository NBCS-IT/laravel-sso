<?php

namespace NBCSIT\Sso\Metadata;

use Illuminate\Support\Facades\DB;
use NBCSIT\Sso\Enums\SamlMetadataOutcome;
use NBCSIT\Sso\Enums\SamlMetadataSource;
use NBCSIT\Sso\Models\IdentityProvider;
use NBCSIT\Sso\Models\SamlMetadataEvent;
use NBCSIT\Sso\Support\Certificate;
use NBCSIT\Sso\Support\IdpMetadata;
use NBCSIT\Sso\Support\MetadataChange;
use NBCSIT\Sso\Support\MetadataSyncReport;
use NBCSIT\Sso\Support\MetadataUnreadable;
use Ramsey\Uuid\Uuid;

/**
 * Puts a metadata document into an identity provider, and writes down what that
 * did.
 *
 * Three rules shape the class.
 *
 * **A fetched document may roll keys, not move goalposts.** Signing certificates
 * change on a schedule the IdP owns, and keeping up with them unattended is the
 * entire reason a refresh URL exists. The entity ID, the endpoints and the
 * NameID format say who is being trusted and how a person is identified, and
 * nothing here verifies the document's own signature — so a change to one of
 * those is recorded on the provider as pending and waits for an administrator.
 * A person uploading a file or pressing Refresh is that administrator, but they
 * still get the change listed rather than slipped past them.
 *
 * Rolling a key means the old and the new certificate overlap. A document that
 * shares *no* certificate with what is configured is not rolling a key, it is
 * naming a new trust anchor, and it waits with the rest — see
 * {@see self::replacesEveryCertificate()}.
 *
 * **Nothing is written twice.** The fingerprint on the provider is a digest of
 * the values in use, not of the document, so a daily fetch of a document that
 * differs only in its `ID` attribute stops here, silently, with only
 * `metadata_checked_at` moved.
 *
 * **A run that changed nothing leaves no trace in the log.** {@see SamlMetadataEvent}
 * is written for a creation, a change, a held change, a discard or a failure —
 * the rows somebody would go looking for after sign-in broke. Three hundred
 * "no change" rows a year would bury them.
 */
class IdpMetadataSynchroniser
{
    /**
     * Columns an unattended run will not write on its own, and the names an
     * administrator reads them by.
     *
     * @var array<string, string>
     */
    private const GUARDED_FIELDS = [
        'idp_entity_id' => 'Entity ID',
        'idp_login_url' => 'Sign-in URL',
        'idp_logout_url' => 'Sign-out URL',
        'name_id_format' => 'NameID format',
    ];

    public function __construct(
        private readonly IdpMetadataReader $reader,
        private readonly MetadataFetcher $fetcher,
    ) {}

    /**
     * Add a provider from a document somebody uploaded.
     */
    public function createFromXml(
        string $name,
        string $xml,
        ?string $metadataUrl = null,
        bool $autoRefresh = false,
        ?int $userId = null,
        ?string $entityId = null,
        SamlMetadataSource $source = SamlMetadataSource::Upload,
    ): MetadataSyncReport {
        $warnings = $metadataUrl === null ? [] : $this->urlWarnings($metadataUrl);

        try {
            $metadata = $this->reader->read($xml, $entityId);
        } catch (MetadataUnreadable $exception) {
            return $this->fail($name, null, $source, $metadataUrl, $exception->getMessage(), $warnings, $userId);
        }

        $existing = IdentityProvider::query()->where('idp_entity_id', $metadata->entityId)->first();

        if ($existing !== null) {
            return $this->fail(
                $name,
                $existing,
                $source,
                $metadataUrl,
                'This is the metadata for "'.$existing->key.'", which is already set up here. Refresh that provider instead of adding a second one.',
                $warnings,
                $userId,
            );
        }

        $warnings = [...$warnings, ...$metadata->warnings];

        $provider = DB::transaction(function () use ($name, $metadata, $metadataUrl, $autoRefresh) {
            $provider = new IdentityProvider;
            $provider->uuid = Uuid::uuid4()->toString();
            $provider->key = $name;
            $provider->relay_state_url = null;
            $provider->metadata = [];

            $this->writeMetadata($provider, $metadata);
            $this->writeGuarded($provider, $metadata);

            $provider->metadata_url = $metadataUrl;
            $provider->metadata_auto_refresh = $autoRefresh && $metadataUrl !== null;
            $provider->metadata_fingerprint = $metadata->fingerprint();
            $provider->metadata_checked_at = now();
            $provider->metadata_synced_at = now();
            $provider->metadata_error = null;
            $provider->save();

            return $provider;
        });

        $report = MetadataSyncReport::created($provider, $warnings);
        $this->record($report, $provider, $source, $metadataUrl, $userId);

        return $report;
    }

    /**
     * Add a provider by fetching the URL it publishes its metadata at.
     */
    public function createFromUrl(
        string $name,
        string $url,
        bool $autoRefresh = false,
        ?int $userId = null,
        ?string $entityId = null,
    ): MetadataSyncReport {
        try {
            $xml = $this->fetcher->fetch($url);
        } catch (MetadataUnreadable $exception) {
            return $this->fail($name, null, SamlMetadataSource::Url, $url, $exception->getMessage(), $this->urlWarnings($url), $userId);
        }

        return $this->createFromXml($name, $xml, $url, $autoRefresh, $userId, $entityId, SamlMetadataSource::Url);
    }

    /**
     * Re-read a provider from a document somebody uploaded.
     */
    public function refreshFromXml(IdentityProvider $provider, string $xml, ?int $userId = null): MetadataSyncReport
    {
        return $this->refresh($provider, $xml, SamlMetadataSource::Upload, null, $userId);
    }

    /**
     * Re-read a provider from its stored metadata URL. This is what the
     * schedule calls, so `$userId` is normally null.
     */
    public function refreshFromUrl(IdentityProvider $provider, ?int $userId = null): MetadataSyncReport
    {
        $url = $provider->metadata_url;

        if ($url === null || $url === '') {
            return MetadataSyncReport::failed('"'.$provider->key.'" has no metadata URL to refresh from.', [], $provider);
        }

        // A warning is the right response to plain HTTP when a person is
        // standing there setting a provider up and can weigh it. It is the
        // wrong response at 03:15: the document names the key whose signature
        // this application will accept, so over HTTP whoever is on the path
        // chooses who may sign in, and there is nobody to read the warning.
        if (! $this->isHttps($url)) {
            $message = 'The metadata URL for "'.$provider->key.'" is not HTTPS, so refreshing from it would take '
                .'signing certificates from whatever the network returned. Store an https:// address instead.';

            $provider->metadata_checked_at = now();
            $provider->metadata_error = $message;
            $provider->save();

            return $this->fail($provider->key, $provider, SamlMetadataSource::Url, $url, $message, [], $userId);
        }

        try {
            $xml = $this->fetcher->fetch($url);
        } catch (MetadataUnreadable $exception) {
            $provider->metadata_checked_at = now();
            $provider->metadata_error = $exception->getMessage();
            $provider->save();

            return $this->fail($provider->key, $provider, SamlMetadataSource::Url, $url, $exception->getMessage(), $this->urlWarnings($url), $userId);
        }

        return $this->refresh($provider, $xml, SamlMetadataSource::Url, $url, $userId);
    }

    /**
     * Write the guarded changes a refresh held back.
     */
    public function applyPending(IdentityProvider $provider, ?int $userId = null): MetadataSyncReport
    {
        $pending = $provider->pendingChanges();

        if ($pending === []) {
            return MetadataSyncReport::failed('"'.$provider->key.'" has no held changes to apply.', [], $provider);
        }

        DB::transaction(function () use ($provider, $pending) {
            foreach ($pending as $change) {
                // A held certificate change carries a description of the
                // certificate rather than the certificate, because that is what
                // an administrator has to read to decide. The values themselves
                // are written below.
                if ($change->field === 'idp_x509_cert_multi') {
                    continue;
                }

                // The column is NOT NULL, so a value the metadata dropped is
                // stored as the empty string the package treats as unset.
                $provider->setAttribute($change->field, $change->to ?? '');
            }

            $certificates = $provider->pending_certificates ?? [];

            if ($certificates !== []) {
                $provider->idp_x509_cert = $certificates[0];
                $provider->idp_x509_cert_multi = $certificates;
            }

            $provider->pending_certificates = null;
            $provider->pending_metadata = null;
            $provider->pending_metadata_at = null;
            $provider->metadata_synced_at = now();
            $provider->save();
        });

        $report = MetadataSyncReport::changed($provider, $pending, [], []);
        $this->record($report, $provider, SamlMetadataSource::Manual, $provider->metadata_url, $userId);

        return $report;
    }

    /**
     * Drop the held changes and keep what is configured.
     *
     * The fingerprint is left alone deliberately: it already matches the
     * document that raised these, so the next scheduled run finds no change and
     * does not raise them again. Discarding means "I have decided" rather than
     * "ask me tomorrow", and the decision is in the log.
     */
    public function discardPending(IdentityProvider $provider, ?int $userId = null): MetadataSyncReport
    {
        $pending = $provider->pendingChanges();

        if ($pending === []) {
            return MetadataSyncReport::failed('"'.$provider->key.'" has no held changes to discard.', [], $provider);
        }

        $provider->pending_certificates = null;
        $provider->pending_metadata = null;
        $provider->pending_metadata_at = null;
        $provider->save();

        $report = MetadataSyncReport::discarded($provider, $pending);
        $this->record($report, $provider, SamlMetadataSource::Manual, $provider->metadata_url, $userId);

        return $report;
    }

    /**
     * @param  list<string>  $warnings
     */
    private function refresh(
        IdentityProvider $provider,
        string $xml,
        SamlMetadataSource $source,
        ?string $url,
        ?int $userId,
        array $warnings = [],
    ): MetadataSyncReport {
        if ($url !== null) {
            $warnings = [...$warnings, ...$this->urlWarnings($url)];
        }

        try {
            $metadata = $this->readForProvider($provider, $xml);
        } catch (MetadataUnreadable $exception) {
            $provider->metadata_checked_at = now();
            $provider->metadata_error = $exception->getMessage();
            $provider->save();

            return $this->fail($provider->key, $provider, $source, $url, $exception->getMessage(), $warnings, $userId);
        }

        $warnings = [...$warnings, ...$metadata->warnings];

        if ($provider->metadata_fingerprint === $metadata->fingerprint()) {
            $provider->metadata_checked_at = now();
            $provider->metadata_error = null;
            $provider->save();

            return MetadataSyncReport::unchanged($provider, $warnings);
        }

        $replacing = $this->replacesEveryCertificate($provider, $metadata);
        $certificates = $this->certificateChanges($provider, $metadata, $replacing);

        $applied = $replacing ? [] : $certificates;
        $pending = [...($replacing ? $certificates : []), ...$this->guardedChanges($provider, $metadata)];

        if ($applied === [] && $pending === []) {
            // The fingerprint moved but nothing this application stores did —
            // a provider set up by hand before it had one, most often. Record
            // the fingerprint so the next run stops at the comparison above.
            $provider->metadata_fingerprint = $metadata->fingerprint();
            $provider->metadata_checked_at = now();
            $provider->metadata_error = null;
            $provider->save();

            return MetadataSyncReport::unchanged($provider, $warnings);
        }

        DB::transaction(function () use ($provider, $metadata, $applied, $pending, $replacing) {
            if ($applied !== []) {
                $this->writeMetadata($provider, $metadata);
            }

            // Descriptions for a person to read go in `pending_metadata`; the
            // certificate bodies `applyPending()` would have to write go here,
            // because a description cannot be written into the column.
            $provider->pending_certificates = $replacing ? $metadata->certificateBodies() : null;

            $provider->pending_metadata = $pending === []
                ? null
                : array_map(fn (MetadataChange $change) => $change->toArray(), $pending);
            $provider->pending_metadata_at = $pending === [] ? null : now();

            $provider->metadata_fingerprint = $metadata->fingerprint();
            $provider->metadata_checked_at = now();
            $provider->metadata_error = null;

            if ($applied !== []) {
                $provider->metadata_synced_at = now();
            }

            $provider->save();
        });

        $report = MetadataSyncReport::changed($provider, $applied, $pending, $warnings);
        $this->record($report, $provider, $source, $url, $userId);

        return $report;
    }

    /**
     * Federation metadata can describe many providers, so the document is
     * searched for this one by entity ID first.
     *
     * A provider that has *changed* its entity ID would then look like a file
     * with nothing in it, so a second read takes whatever the document does
     * describe and lets the comparison report the difference. That difference
     * is guarded, so nobody is signed in against a new entity ID until somebody
     * agrees to it.
     *
     * The second read's failure is the one that propagates: if the document
     * describes no provider at all, "this is not identity provider metadata" is
     * more use than "it does not mention the entity ID you asked for".
     *
     * @throws MetadataUnreadable
     */
    private function readForProvider(IdentityProvider $provider, string $xml): IdpMetadata
    {
        try {
            return $this->reader->read($xml, $provider->idp_entity_id);
        } catch (MetadataUnreadable) {
            return $this->reader->read($xml);
        }
    }

    /**
     * The certificates in use, and the tenant's single-certificate column kept
     * pointing at the first of them so the package's own code path still works.
     */
    private function writeMetadata(IdentityProvider $provider, IdpMetadata $metadata): void
    {
        $provider->idp_x509_cert = $metadata->primaryCertificate()->body;
        $provider->idp_x509_cert_multi = $metadata->certificateBodies();
    }

    private function writeGuarded(IdentityProvider $provider, IdpMetadata $metadata): void
    {
        $provider->idp_entity_id = $metadata->entityId;
        $provider->idp_login_url = $metadata->ssoUrl;
        $provider->idp_logout_url = $metadata->sloUrl ?? '';

        // The package's column is NOT NULL with its own default, so a document
        // that names no format leaves the existing value alone.
        if ($metadata->nameIdFormat !== null) {
            $provider->name_id_format = $metadata->nameIdFormat;
        }
    }

    /**
     * @return list<MetadataChange>
     */
    private function guardedChanges(IdentityProvider $provider, IdpMetadata $metadata): array
    {
        $incoming = [
            'idp_entity_id' => $metadata->entityId,
            'idp_login_url' => $metadata->ssoUrl,
            'idp_logout_url' => $metadata->sloUrl ?? '',
            'name_id_format' => $metadata->nameIdFormat ?? $provider->name_id_format,
        ];

        $changes = [];

        foreach (self::GUARDED_FIELDS as $field => $label) {
            $current = (string) $provider->getAttribute($field);

            if ($current === $incoming[$field]) {
                continue;
            }

            $changes[] = MetadataChange::guarded(
                $field,
                $label,
                $current === '' ? null : $current,
                $incoming[$field] === '' ? null : $incoming[$field],
            );
        }

        return $changes;
    }

    /**
     * Certificates are compared as a set: the same two keys published in the
     * other order is not a change, and is not worth waking anyone for.
     *
     * @return list<MetadataChange>
     */
    private function certificateChanges(IdentityProvider $provider, IdpMetadata $metadata, bool $guarded): array
    {
        $before = $provider->signingCertificateBodies();
        $after = $metadata->certificateBodies();

        // Guarded changes are written from `pending_certificates`, so the field
        // named here is only ever read back for display. It names the column the
        // set lives in so that `applyPending()` can tell the two kinds apart.
        $change = $guarded
            ? fn (string $label, ?string $from, ?string $to) => MetadataChange::guarded('idp_x509_cert_multi', $label, $from, $to)
            : fn (string $label, ?string $from, ?string $to) => MetadataChange::routine('idp_x509_cert', $label, $from, $to);

        $changes = [];

        foreach (array_diff($after, $before) as $body) {
            $changes[] = $change('Signing certificate added', null, Certificate::fromBase64($body)->describe());
        }

        foreach (array_diff($before, $after) as $body) {
            $changes[] = $change('Signing certificate withdrawn', Certificate::fromBase64($body)->describe(), null);
        }

        return $changes;
    }

    /**
     * Whether the document shares no signing certificate at all with what is
     * configured.
     *
     * This is the line between a rollover and a new trust anchor. An identity
     * provider rolling a key publishes the outgoing and incoming certificates
     * together for a while, so the sets overlap — that is routine, it is the
     * entire reason an unattended refresh exists, and it stays unattended. A
     * document that shares nothing with what is in use is not rolling a key: it
     * is naming a different set of keys as the ones this application will accept
     * signatures from, and the signing certificate is what decides who may sign
     * in. Nothing here verifies the document's own signature, so that decision
     * waits for an administrator.
     *
     * A provider with no certificates yet cannot be replaced, only filled in.
     */
    private function replacesEveryCertificate(IdentityProvider $provider, IdpMetadata $metadata): bool
    {
        $before = $provider->signingCertificateBodies();

        return $before !== [] && array_intersect($before, $metadata->certificateBodies()) === [];
    }

    /**
     * `https` is not a formality here: the document names the key whose
     * signature this application will accept, and over plain HTTP anyone on the
     * path can choose that key.
     *
     * @return list<string>
     */
    private function urlWarnings(string $url): array
    {
        return $this->isHttps($url)
            ? []
            : ['The metadata URL is not HTTPS, so what it returns cannot be trusted to have come from the identity provider.'];
    }

    private function isHttps(string $url): bool
    {
        return str_starts_with(strtolower($url), 'https://');
    }

    /**
     * @param  list<string>  $warnings
     */
    private function fail(
        string $name,
        ?IdentityProvider $provider,
        SamlMetadataSource $source,
        ?string $url,
        string $message,
        array $warnings,
        ?int $userId,
    ): MetadataSyncReport {
        $report = MetadataSyncReport::failed($message, $warnings, $provider);

        SamlMetadataEvent::query()->create([
            'tenant_id' => $provider?->getKey(),
            'provider_name' => $name,
            'source' => $source,
            'outcome' => SamlMetadataOutcome::Failed,
            'metadata_url' => $url,
            'message' => $message,
            'change_set' => [],
            'warnings' => $warnings,
            'user_id' => $userId,
        ]);

        return $report;
    }

    private function record(
        MetadataSyncReport $report,
        IdentityProvider $provider,
        SamlMetadataSource $source,
        ?string $url,
        ?int $userId,
    ): void {
        SamlMetadataEvent::query()->create([
            'tenant_id' => $provider->getKey(),
            'provider_name' => $provider->key,
            'source' => $source,
            'outcome' => $report->outcome,
            'metadata_url' => $url,
            'message' => $report->message,
            'change_set' => array_map(fn (MetadataChange $change) => $change->toArray(), $report->changes()),
            'warnings' => $report->warnings,
            'user_id' => $userId,
        ]);
    }
}
