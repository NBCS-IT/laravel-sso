<?php

namespace NBCSIT\Sso\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use NBCSIT\Sso\Metadata\IdpMetadataSynchroniser;
use NBCSIT\Sso\Models\IdentityProvider;
use NBCSIT\Sso\Support\MetadataSyncReport;

/**
 * Re-reads each identity provider's metadata from the URL it publishes it at.
 *
 * Scheduled daily by the package's service provider, at the time and under the
 * switch `config('saml.refresh')` carries. It runs in the foreground rather
 * than on the queue: the work is one HTTP request and one row per provider, and
 * a queued job would need a worker running on the box for a job that takes a
 * second.
 *
 * What a refresh will and will not change on its own is
 * {@see IdpMetadataSynchroniser}'s decision, not this command's.
 */
class RefreshSamlMetadataCommand extends Command
{
    protected $signature = 'saml:refresh-metadata
                            {--provider= : Refresh one provider, by UUID or by name}
                            {--force : Refresh a provider whose automatic refresh is switched off}';

    protected $description = 'Re-read identity provider metadata and apply certificate changes';

    public function handle(IdpMetadataSynchroniser $synchroniser): int
    {
        $providers = $this->providers();

        if ($providers->isEmpty()) {
            $this->components->info('No identity provider is set up to refresh from a metadata URL.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($providers as $provider) {
            $report = $synchroniser->refreshFromUrl($provider);

            $this->components->twoColumnDetail($provider->key, $this->status($report));
            $this->line('  '.$report->message);

            foreach ([...$report->changes(), ...$report->warnings] as $line) {
                $this->line('  · '.(is_string($line) ? $line : $line->describe()));
            }

            if (! $report->succeeded()) {
                $failed++;
            }
        }

        // A failure here is a provider whose certificate may have moved without
        // this knowing, so the exit status has to say so — a cron that reports
        // only on non-zero would otherwise never mention it.
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return Collection<int, IdentityProvider>
     */
    private function providers(): Collection
    {
        $named = $this->option('provider');

        if (is_string($named) && $named !== '') {
            return IdentityProvider::query()
                ->where(fn ($query) => $query->where('uuid', $named)->orWhere('key', $named))
                ->when(! $this->option('force'), fn ($query) => $query->autoRefreshable())
                ->whereNotNull('metadata_url')
                ->get();
        }

        return IdentityProvider::query()
            ->when(
                (bool) $this->option('force'),
                fn ($query) => $query->whereNotNull('metadata_url')->where('metadata_url', '!=', ''),
                fn ($query) => $query->autoRefreshable(),
            )
            ->get();
    }

    private function status(MetadataSyncReport $report): string
    {
        $colour = match (true) {
            ! $report->succeeded() => 'red',
            $report->pending !== [] => 'yellow',
            default => 'green',
        };

        return "<fg={$colour}>".$report->outcome->label().'</>';
    }
}
