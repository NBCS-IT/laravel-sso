<?php

namespace NBCSIT\Sso\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;

/**
 * The consuming application's own wiring, registered before the package's.
 *
 * It exists so the suite can prove the package appends to Spatie Settings'
 * three config arrays rather than replacing them. Setting these in the test
 * case's `defineEnvironment()` would not prove it: Testbench runs that after
 * package providers register, which is the opposite of the order a real
 * application boots in.
 */
class ApplicationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config([
            'settings.settings' => [ApplicationSettings::class],
            'settings.migrations_paths' => [__DIR__.'/settings'],
            'settings.auto_discover_settings' => [__DIR__],
        ]);
    }
}
