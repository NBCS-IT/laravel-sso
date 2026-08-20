<?php

namespace NBCSIT\Sso\Console;

use Illuminate\Console\Command;
use NBCSIT\Sso\Certificates\SpCertificateStore;
use NBCSIT\Sso\Console\Concerns\RendersCertificateReports;

/**
 * Mints this application's own signing certificate.
 *
 * Not scheduled, and deliberately so. A certificate that appears on disk
 * without anybody asking is one the identity provider has never been given, and
 * the moment it starts being used is the moment sign-in stops working.
 *
 * The default is the rollover certificate rather than the one in use, because
 * that is the safe half of the operation: it can be generated at any time, and
 * nothing changes until {@see PromoteSpCertificateCommand} runs.
 */
class GenerateSpCertificateCommand extends Command
{
    use RendersCertificateReports;

    protected $signature = 'saml:generate-certificate
                            {--primary : Replace the certificate in use now, instead of generating the rollover one}
                            {--days= : How long it is valid for; defaults to config("saml.certificate.days")}
                            {--bits= : RSA key size; defaults to config("saml.certificate.bits")}
                            {--cn= : Common name; defaults to this application\'s host}
                            {--force : Replace the certificate in use without being asked to confirm}';

    protected $description = 'Generate this application\'s SAML signing certificate and private key';

    public function handle(SpCertificateStore $store): int
    {
        $days = $this->integerOption('days');
        $bits = $this->integerOption('bits');
        $commonName = $this->stringOption('cn');

        if (! $this->option('primary')) {
            return $this->renderReport($store->generateSecondary($days, $bits, $commonName));
        }

        $force = (bool) $this->option('force');

        if (! $force && $store->pair()->primary !== null) {
            // Interactively, being asked is enough. Non-interactively there is
            // nobody to ask, and the answer has to have been given in advance.
            if (! $this->confirmReplacement()) {
                $this->components->warn(
                    'Left the certificate in use alone. To roll over without an outage, run this without --primary, '
                    .'import the metadata at the identity provider, then run saml:promote-certificate.',
                );

                return self::FAILURE;
            }

            $force = true;
        }

        return $this->renderReport($store->generatePrimary($days, $bits, $commonName, $force));
    }

    private function confirmReplacement(): bool
    {
        return $this->components->confirm(
            'Replace the certificate in use now? Every signature the identity provider is validating stops being '
            .'valid the moment this is written.',
            false,
        );
    }

    private function integerOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? (int) $value : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
