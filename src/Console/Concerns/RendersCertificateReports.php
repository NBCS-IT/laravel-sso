<?php

namespace NBCSIT\Sso\Console\Concerns;

use NBCSIT\Sso\Support\SpCertificateReport;

/**
 * The two-line summary both certificate commands print.
 *
 * Shared rather than duplicated because the pair of slots is the thing an
 * administrator is reading for — which certificate is signing and which is
 * waiting — and the two commands disagreeing about how to say that is how a
 * rollover gets done in the wrong order.
 */
trait RendersCertificateReports
{
    private function renderReport(SpCertificateReport $report): int
    {
        $pair = $report->pair;

        $this->components->twoColumnDetail(
            'In use now',
            $pair->primary === null ? '<fg=yellow>none</>' : '<fg=green>'.$pair->primary->describe().'</>',
        );

        $this->components->twoColumnDetail(
            'Rollover',
            $pair->secondary === null ? '<fg=gray>none</>' : '<fg=yellow>'.$pair->secondary->describe().'</>',
        );

        $this->line('  '.$report->message);

        foreach ($report->warnings as $warning) {
            $this->line('  · '.$warning);
        }

        return $report->succeeded() ? self::SUCCESS : self::FAILURE;
    }
}
