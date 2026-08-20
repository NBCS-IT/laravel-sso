<?php

namespace NBCSIT\Sso\Certificates;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use NBCSIT\Sso\Exceptions\CertificateGenerationFailed;
use NBCSIT\Sso\Metadata\IdpMetadataSynchroniser;
use NBCSIT\Sso\Support\Certificate;
use NBCSIT\Sso\Support\CertificateSubject;
use NBCSIT\Sso\Support\SpCertificatePair;
use NBCSIT\Sso\Support\SpCertificateReport;
use NBCSIT\Sso\Support\SpKeypair;

/**
 * The service provider's own signing certificate, on a disk.
 *
 * Two slots. `sp.*` is what this application signs with; `sp_new.*` is the one
 * an identity provider can import ahead of time, published alongside the first
 * in the SP metadata document. Rolling over means generating the second,
 * getting it imported, and only then promoting it — so that at no point is
 * anybody asked to trust a certificate they have not already been given.
 *
 * Every file is read on the call that needs it, and the disk itself is resolved
 * from config on every call. That is not indirection for its own sake: the
 * implementation this replaces read the certificate with `file_get_contents()`
 * at the top of `config/saml2.php`, which meant the values were frozen at
 * config-cache time and the test suite needed a second path to look in.
 *
 * No public method throws. {@see SpCertificateGenerator} throws; this catches,
 * and hands back a report — the same division
 * {@see IdpMetadataSynchroniser} uses.
 */
class SpCertificateStore
{
    /** The certificate in use, and the key it signs with. */
    private const PRIMARY = 'sp';

    /** Generated ahead of a rollover; published in metadata, never signs. */
    private const SECONDARY = 'sp_new';

    /**
     * Written by {@see self::promote()} before it touches anything else, and
     * overwritten by the next promotion. One generation of history, so that a
     * promotion that fails half way through has left a copy behind — not an
     * archive, and not a substitute for backups.
     */
    private const PREVIOUS = 'sp_previous';

    /**
     * A certificate that expires tomorrow is a typo, not a decision, and the
     * upper bound is the same twenty years the old implementation defaulted to.
     */
    private const MIN_DAYS = 1;

    private const MAX_DAYS = 7300;

    /**
     * Long enough for two RSA-2048 generations, short enough that a crashed
     * request does not lock the screen out for the rest of the afternoon.
     */
    private const LOCK_SECONDS = 30;

    public function __construct(private readonly SpCertificateGenerator $generator) {}

    /**
     * What is on disk, without the private keys.
     */
    public function pair(): SpCertificatePair
    {
        $primary = $this->read(self::PRIMARY, 'crt');
        $secondary = $this->read(self::SECONDARY, 'crt');

        return SpCertificatePair::of(
            $primary === null ? null : Certificate::fromBase64($primary),
            $secondary === null ? null : Certificate::fromBase64($secondary),
            $this->canSign(),
        );
    }

    /**
     * Whether this application could sign something right now.
     *
     * The one definition, asked by the builder, the published controller and the
     * view alike. It is deliberately stricter than "the files are there": a
     * certificate and a key that do not belong to each other produce signatures
     * no identity provider can verify, and nothing about the failure says so.
     */
    public function canSign(): bool
    {
        $certificate = $this->read(self::PRIMARY, 'crt');
        $key = $this->read(self::PRIMARY, 'key');

        return $certificate !== null && $key !== null && $this->belongTogether($certificate, $key);
    }

    /**
     * PEM, or null when there is nothing on disk. The builder's read path.
     */
    public function primaryCertificate(): ?string
    {
        return $this->read(self::PRIMARY, 'crt');
    }

    public function primaryKey(): ?string
    {
        return $this->read(self::PRIMARY, 'key');
    }

    public function secondaryCertificate(): ?string
    {
        return $this->read(self::SECONDARY, 'crt');
    }

    /**
     * Replace the certificate this application signs with, there and then.
     *
     * Refused by default when one already exists, and the refusal is the point:
     * writing over `sp.crt` invalidates every signature the identity provider is
     * currently validating, with no window in which both are trusted. The safe
     * path — generate a secondary, get it imported, promote — is what the
     * message names.
     */
    public function generatePrimary(?int $days = null, ?int $bits = null, ?string $commonName = null, bool $force = false): SpCertificateReport
    {
        if (! $force && $this->read(self::PRIMARY, 'crt') !== null) {
            return SpCertificateReport::failed(
                'There is already a certificate in use. Replacing it here would invalidate every signature the '
                .'identity provider is validating right now. Generate a rollover certificate instead, import it at '
                .'the identity provider, then promote it.',
                $this->pair(),
            );
        }

        return $this->generateInto(self::PRIMARY, $days, $bits, $commonName);
    }

    /**
     * Generate the certificate a rollover will promote.
     *
     * Always allowed: overwriting a rollover certificate that has never signed
     * anything costs nothing, and the identity provider re-imports metadata
     * before it matters.
     */
    public function generateSecondary(?int $days = null, ?int $bits = null, ?string $commonName = null): SpCertificateReport
    {
        return $this->generateInto(self::SECONDARY, $days, $bits, $commonName);
    }

    /**
     * Make the rollover certificate the one this application signs with.
     *
     * The demoted certificate is kept as the new secondary rather than deleted.
     * During the window after a promotion the identity provider may still be
     * validating against it, and the metadata document keeps publishing it for
     * as long as it sits there. It also makes this its own undo: run it twice
     * and the disk is back where it started.
     */
    public function promote(): SpCertificateReport
    {
        return $this->withLock(function (): SpCertificateReport {
            $certificate = $this->read(self::SECONDARY, 'crt');
            $key = $this->read(self::SECONDARY, 'key');

            if ($certificate === null || $key === null) {
                return SpCertificateReport::failed(
                    'There is no rollover certificate to promote. Generate one with `saml:generate-certificate`, '
                    .'import it at the identity provider, and promote it once the identity provider has it.',
                    $this->pair(),
                );
            }

            if (! $this->belongTogether($certificate, $key)) {
                return SpCertificateReport::failed(
                    'The rollover certificate and its private key do not belong to each other, so promoting them '
                    .'would produce signatures the identity provider cannot verify. Generate a fresh rollover '
                    .'certificate.',
                    $this->pair(),
                );
            }

            $refusal = $this->refuseWebServedDisk();

            if ($refusal !== null) {
                return $refusal;
            }

            $demotedCertificate = $this->read(self::PRIMARY, 'crt');
            $demotedKey = $this->read(self::PRIMARY, 'key');
            $hasPrimary = $demotedCertificate !== null && $demotedKey !== null;

            // Written before anything is mutated, so that every later step has a
            // recovery copy already on disk rather than one it is about to make.
            if ($hasPrimary && ! $this->writePair(self::PREVIOUS, $demotedCertificate, $demotedKey)) {
                return $this->promotionFailed('while keeping a copy of the certificate in use');
            }

            // Key first, then certificate. A request landing between the two
            // renames is wrong either way; this order means the certificate —
            // the value the identity provider caches — is the last to move.
            if (! $this->write(self::PRIMARY, 'key', $key) || ! $this->write(self::PRIMARY, 'crt', $certificate)) {
                return $this->promotionFailed('while writing the promoted certificate');
            }

            $swapped = $hasPrimary
                ? $this->writePair(self::SECONDARY, $demotedCertificate, $demotedKey)
                : $this->deletePair(self::SECONDARY);

            if (! $swapped) {
                return $this->promotionFailed('while moving the old certificate into the rollover slot');
            }

            return SpCertificateReport::completed(
                $hasPrimary
                    ? 'Promoted the rollover certificate. The one it replaced is now the rollover certificate, and '
                        .'stays published in this application\'s metadata until the next one is generated.'
                    : 'Promoted the rollover certificate. This application now has a certificate to sign with.',
                $this->pair(),
            );
        });
    }

    private function generateInto(string $slot, ?int $days, ?int $bits, ?string $commonName): SpCertificateReport
    {
        return $this->withLock(function () use ($slot, $days, $bits, $commonName): SpCertificateReport {
            $days ??= (int) config('saml.certificate.days', SpCertificateGenerator::DEFAULT_DAYS);
            $bits ??= (int) config('saml.certificate.bits', SpCertificateGenerator::DEFAULT_KEY_BITS);

            if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
                return SpCertificateReport::failed(
                    'A certificate has to be valid for between '.self::MIN_DAYS.' and '.self::MAX_DAYS.' days; '
                    ."{$days} was asked for.",
                    $this->pair(),
                );
            }

            $refusal = $this->refuseWebServedDisk();

            if ($refusal !== null) {
                return $refusal;
            }

            try {
                $keypair = $this->generator->generate(
                    CertificateSubject::fromConfig((array) config('saml.certificate.subject', []), $commonName),
                    $days,
                    $bits,
                );
            } catch (CertificateGenerationFailed $failure) {
                return SpCertificateReport::failed($failure->getMessage(), $this->pair());
            }

            if (! $this->writePair($slot, $keypair->certificate, $keypair->privateKey)) {
                return SpCertificateReport::failed(
                    "The certificate was generated but could not be written to `{$this->path($slot, 'crt')}` on the "
                    ."`{$this->diskName()}` disk. Check that the disk is writable, then try again.",
                    $this->pair(),
                );
            }

            return SpCertificateReport::completed($this->generatedMessage($slot, $keypair), $this->pair(), $this->generatedWarnings($slot));
        });
    }

    private function generatedMessage(string $slot, SpKeypair $keypair): string
    {
        $details = $keypair->details()->describe();

        return $slot === self::PRIMARY
            ? "This application now signs with a new certificate — {$details}. Import it at the identity provider now: "
                .'until you do, the identity provider is validating against a certificate this application no longer has.'
            : "Generated a rollover certificate — {$details}. Import this application's metadata at the identity "
                .'provider, then promote it.';
    }

    /**
     * @return list<string>
     */
    private function generatedWarnings(string $slot): array
    {
        if ($slot === self::SECONDARY && $this->read(self::PRIMARY, 'crt') === null) {
            return ['There is no certificate in use for this one to roll over from, so nothing is signing yet. '
                .'Promote it to start.'];
        }

        return [];
    }

    /**
     * Publishing a private key over HTTP is the worst thing available here, it
     * is one configuration mistake away, and nothing else would notice.
     */
    private function refuseWebServedDisk(): ?SpCertificateReport
    {
        $disk = $this->diskName();

        if (config("filesystems.disks.{$disk}.url") === null) {
            return null;
        }

        return SpCertificateReport::failed(
            "The `{$disk}` disk is served over HTTP, so a private key written to it would be downloadable. Point "
            .'`saml.certificate.disk` at a disk that is not public.',
            $this->pair(),
        );
    }

    private function promotionFailed(string $step): SpCertificateReport
    {
        return SpCertificateReport::failed(
            "The promotion failed {$step}, so the certificate on disk may be half-changed. The certificate and key "
            ."that were in use are at `{$this->path(self::PREVIOUS, 'crt')}` and `{$this->path(self::PREVIOUS, 'key')}` "
            ."on the `{$this->diskName()}` disk; copy them back over `{$this->path(self::PRIMARY, 'crt')}` and "
            ."`{$this->path(self::PRIMARY, 'key')}` to get back to where you were.",
            $this->pair(),
        );
    }

    /**
     * Two administrators pressing Promote at once is not hypothetical on a
     * screen with one obvious button, and the second one arriving mid-rename is
     * how a disk ends up with a certificate from one pair and a key from another.
     */
    private function withLock(callable $work): SpCertificateReport
    {
        $report = Cache::lock('saml:sp-certificate', self::LOCK_SECONDS)->get($work);

        if ($report instanceof SpCertificateReport) {
            return $report;
        }

        return SpCertificateReport::failed(
            'Somebody else is generating or promoting a certificate right now. Wait for that to finish, reload this '
            .'page, and check what is on disk before trying again.',
            $this->pair(),
        );
    }

    private function belongTogether(string $certificate, string $key): bool
    {
        $parsedCertificate = @openssl_x509_read($certificate);
        $parsedKey = @openssl_pkey_get_private($key);

        return $parsedCertificate !== false
            && $parsedKey !== false
            && openssl_x509_check_private_key($parsedCertificate, $parsedKey);
    }

    private function writePair(string $slot, string $certificate, string $key): bool
    {
        return $this->write($slot, 'crt', $certificate) && $this->write($slot, 'key', $key);
    }

    private function write(string $slot, string $extension, string $contents): bool
    {
        return (bool) $this->disk()->put($this->path($slot, $extension), $contents, ['visibility' => 'private']);
    }

    private function deletePair(string $slot): bool
    {
        return $this->disk()->delete($this->path($slot, 'crt'))
            && $this->disk()->delete($this->path($slot, 'key'));
    }

    private function read(string $slot, string $extension): ?string
    {
        $contents = $this->disk()->get($this->path($slot, $extension));

        return is_string($contents) && trim($contents) !== '' ? $contents : null;
    }

    private function path(string $slot, string $extension): string
    {
        $directory = rtrim((string) config('saml.certificate.path', 'certs'), '/');

        return ($directory === '' ? '' : $directory.'/')."{$slot}.{$extension}";
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    private function diskName(): string
    {
        return (string) config('saml.certificate.disk', 'local');
    }
}
