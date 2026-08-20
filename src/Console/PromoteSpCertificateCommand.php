<?php

namespace NBCSIT\Sso\Console;

use Illuminate\Console\Command;
use NBCSIT\Sso\Certificates\SpCertificateStore;
use NBCSIT\Sso\Console\Concerns\RendersCertificateReports;

/**
 * Swaps the rollover certificate into use.
 *
 * No options, and no `--force`. There is nothing to force past: the store
 * refuses every unsafe promotion on its own — no rollover certificate, half a
 * pair, a certificate and key that do not belong together — and none of those
 * refusals is one an operator should be able to override from a flag.
 *
 * The confirmation belongs on generating over the certificate in use, which is
 * the destructive half. This is the step the rollover was for.
 */
class PromoteSpCertificateCommand extends Command
{
    use RendersCertificateReports;

    protected $signature = 'saml:promote-certificate';

    protected $description = 'Promote the rollover certificate to be the one this application signs with';

    public function handle(SpCertificateStore $store): int
    {
        return $this->renderReport($store->promote());
    }
}
