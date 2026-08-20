<?php

namespace NBCSIT\Sso\Support;

/**
 * The distinguished name a generated service provider certificate carries.
 *
 * Nothing here is checked by anybody. A service provider certificate is
 * self-signed, and an identity provider trusts it because an administrator
 * imported it, not because of what its subject says. What the subject is for is
 * telling two certificates apart in a console that lists a dozen of them, which
 * is why the common name defaults to this application's host rather than to
 * anything more official-looking.
 *
 * Three layers, most specific first: an explicit common name (the console
 * command's `--cn`), then `config('saml.certificate.subject')`, then what can be
 * worked out from the application itself. A site that has `APP_URL` and
 * `APP_NAME` right — and SAML needs `APP_URL` right regardless, because the
 * assertion consumer URL is built from it — configures nothing.
 *
 * Empty components are dropped rather than sent as empty strings: openssl is
 * content with a subject of nothing but a common name, and an empty `C=` upsets
 * parsers that a missing one does not.
 */
final readonly class CertificateSubject
{
    /**
     * @param  array<string, string>  $components
     */
    private function __construct(public array $components) {}

    /**
     * @param  array<string, mixed>  $configured  `config('saml.certificate.subject')`
     */
    public static function fromConfig(array $configured, ?string $commonName = null): self
    {
        $components = [
            'commonName' => $commonName
                ?? self::text($configured['commonName'] ?? null)
                ?? self::applicationHost(),
            'organizationName' => self::text($configured['organizationName'] ?? null)
                ?? self::text(config('app.name')),
            'organizationalUnitName' => self::text($configured['organizationalUnitName'] ?? null),
            'countryName' => self::text($configured['countryName'] ?? null),
            'stateOrProvinceName' => self::text($configured['stateOrProvinceName'] ?? null),
            'localityName' => self::text($configured['localityName'] ?? null),
            'emailAddress' => self::text($configured['emailAddress'] ?? null),
        ];

        return new self(array_filter($components, is_string(...)));
    }

    public function commonName(): string
    {
        return $this->components['commonName'] ?? '';
    }

    /**
     * A short, human line for the log and the settings page.
     */
    public function describe(): string
    {
        return implode(', ', array_map(
            fn (string $key, string $value) => self::abbreviation($key).'='.$value,
            array_keys($this->components),
            array_values($this->components),
        ));
    }

    /**
     * The shape openssl wants.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->components;
    }

    /**
     * The host `APP_URL` names, which is what an administrator recognises this
     * application as. A configured `APP_URL` with no host — or none at all —
     * falls back to the application's name, because a certificate with no
     * common name at all is one openssl refuses outright.
     */
    private static function applicationHost(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return self::text(is_string($host) ? $host : null) ?? self::text(config('app.name'));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function abbreviation(string $component): string
    {
        return match ($component) {
            'commonName' => 'CN',
            'organizationName' => 'O',
            'organizationalUnitName' => 'OU',
            'countryName' => 'C',
            'stateOrProvinceName' => 'ST',
            'localityName' => 'L',
            default => 'E',
        };
    }
}
