<?php

namespace NBCSIT\Sso\Support;

/**
 * What generating or promoting a certificate did.
 *
 * Returned rather than thrown, for the same reason
 * {@see MetadataSyncReport} is: every caller here is either a console command
 * that has to print something and choose an exit code, or a controller that has
 * to flash something and redirect. Neither wants an exception, and both want the
 * new state of the disk to show afterwards — which is why a report carries the
 * pair even when it failed.
 */
final readonly class SpCertificateReport
{
    /**
     * @param  list<string>  $warnings
     */
    private function __construct(
        public bool $successful,
        public string $message,
        public SpCertificatePair $pair,
        public array $warnings = [],
    ) {}

    /**
     * @param  list<string>  $warnings
     */
    public static function failed(string $message, SpCertificatePair $pair, array $warnings = []): self
    {
        return new self(false, $message, $pair, $warnings);
    }

    /**
     * @param  list<string>  $warnings
     */
    public static function completed(string $message, SpCertificatePair $pair, array $warnings = []): self
    {
        return new self(true, $message, $pair, $warnings);
    }

    public function succeeded(): bool
    {
        return $this->successful;
    }
}
