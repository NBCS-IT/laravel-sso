<?php

namespace NBCSIT\Sso\Support;

use NBCSIT\Sso\Certificates\SpCertificateStore;

/**
 * What is on the certificate disk, in the terms a screen or a command needs.
 *
 * Never the private keys. This is the object a Blade view is handed, and a key
 * that reaches a view is a key one `{{ }}` away from being rendered into a page.
 *
 * `usable` is not "a primary certificate exists". It is "there is a certificate
 * and a key, both parse, and they belong to each other" — the same question
 * {@see SpCertificateStore::canSign()} answers, carried
 * here so a view does not have to ask twice.
 */
final readonly class SpCertificatePair
{
    private function __construct(
        /** The certificate this application signs with, if it has one. */
        public ?Certificate $primary,
        /** The one waiting to be promoted, published in metadata meanwhile. */
        public ?Certificate $secondary,
        public bool $usable,
    ) {}

    public static function of(?Certificate $primary, ?Certificate $secondary, bool $usable): self
    {
        return new self($primary, $secondary, $usable);
    }

    public function hasSecondary(): bool
    {
        return $this->secondary !== null;
    }
}
