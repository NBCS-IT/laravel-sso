<?php

namespace NBCSIT\Sso\Support;

use Illuminate\Support\Carbon;

/**
 * One X.509 signing certificate, in the terms an administrator recognises.
 *
 * A certificate is a page of base64 nobody can eyeball, and "the signing
 * certificate changed" is useless without saying *which*. The IdP's own console
 * lists thumbprints and expiry dates, so those are what the log prints — they
 * are the two values that can be compared against what Entra or ADFS shows.
 */
final readonly class Certificate
{
    private function __construct(
        /** Bare base64, no PEM armour — the shape the tenant column stores. */
        public string $body,
        /** SHA-1 thumbprint, uppercase hex, the form IdP consoles print. */
        public ?string $thumbprint,
        public ?Carbon $expiresAt,
        public ?string $subject,
        /**
         * A certificate that is not valid yet reads as a broken one, so the
         * screen needs the other end of the range as well as the near end.
         */
        public ?Carbon $startsAt = null,
    ) {}

    public static function fromBase64(string $certificate): self
    {
        $body = self::normalise($certificate);

        // A certificate this application cannot decode is still a certificate
        // the toolkit may accept, so it is described as best we can rather than
        // rejected here. Refusing it is IdpMetadataReader's decision, not ours.
        $parsed = @openssl_x509_parse(self::armour($body));

        if ($parsed === false) {
            return new self($body, null, null, null);
        }

        $fingerprint = @openssl_x509_fingerprint(self::armour($body), 'sha1');

        return new self(
            $body,
            is_string($fingerprint) ? strtoupper($fingerprint) : null,
            isset($parsed['validTo_time_t']) ? Carbon::createFromTimestampUTC($parsed['validTo_time_t']) : null,
            isset($parsed['name']) && is_string($parsed['name']) ? $parsed['name'] : null,
            isset($parsed['validFrom_time_t']) ? Carbon::createFromTimestampUTC($parsed['validFrom_time_t']) : null,
        );
    }

    /**
     * Strip PEM armour and whitespace; the toolkit wants the bare base64 body.
     */
    public static function normalise(string $certificate): string
    {
        $certificate = preg_replace('/-+(BEGIN|END) CERTIFICATE-+/', '', $certificate) ?? $certificate;

        return trim(preg_replace('/\s+/', '', $certificate) ?? $certificate);
    }

    /**
     * A short, human line for the log and the settings page.
     */
    public function describe(): string
    {
        if ($this->thumbprint === null) {
            return 'certificate '.$this->shortBody().' (could not be read)';
        }

        $description = 'thumbprint '.$this->thumbprint;

        if ($this->expiresAt !== null) {
            $description .= ', expires '.$this->expiresAt->format('j M Y');
        }

        return $description;
    }

    public function hasExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->isPast();
    }

    /**
     * Close enough to expiry to be worth saying so on a screen.
     *
     * An expired certificate is not "expiring soon" — it is a different, worse
     * thing, and a page that says both at once helps nobody.
     */
    public function expiresWithin(int $days): bool
    {
        return $this->expiresAt !== null
            && ! $this->hasExpired()
            && $this->expiresAt->isBefore(Carbon::now()->addDays($days));
    }

    private function shortBody(): string
    {
        return substr($this->body, 0, 12).'…';
    }

    private static function armour(string $body): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split($body, 64, "\n").'-----END CERTIFICATE-----';
    }
}
