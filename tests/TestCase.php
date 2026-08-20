<?php

namespace NBCSIT\Sso\Tests;

use App\Http\Controllers\Admin\SamlCertificateController;
use App\Http\Controllers\Admin\SamlMetadataController;
use App\Http\Controllers\Admin\SamlSettingController;
use Illuminate\Contracts\Config\Repository;
use NBCSIT\Saml2\ServiceProvider;
use NBCSIT\Sso\Metadata\HostResolver;
use NBCSIT\Sso\SsoServiceProvider;
use NBCSIT\Sso\Tests\Fixtures\ApplicationServiceProvider;
use NBCSIT\Sso\Tests\Fixtures\FakeHostResolver;
use NBCSIT\Sso\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Spatie\LaravelSettings\LaravelSettingsServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

/**
 * The package under an application that is only just enough of one.
 *
 * All four providers are registered every time: Spatie Permission and Spatie
 * Settings are hard requirements of this package, so there is no "without them"
 * configuration to test.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            // First, as an application's own provider is: the package must
            // append to what it registers, not replace it.
            ApplicationServiceProvider::class,

            ServiceProvider::class,
            PermissionServiceProvider::class,
            LaravelSettingsServiceProvider::class,
            SsoServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // The metadata fetcher resolves a URL's host before it fetches it, and
        // no test may depend on what a build machine's DNS says about
        // `idp.example.edu.au`. Everything answers as a public address unless a
        // test binds a resolver of its own.
        $app->instance(HostResolver::class, new FakeHostResolver);

        tap($app->make(Repository::class), function (Repository $config) {
            $config->set('database.default', 'testing');

            // Spatie Permission reads a model's guard from the auth config, and
            // an unmapped model resolves to no guard at all.
            $config->set('auth.defaults.guard', 'web');
            $config->set('auth.providers.users.model', User::class);

            // The seam, exercised: nothing in `src/` may name a user model, so
            // the suite runs against its own.
            $config->set('saml.user.model', User::class);

            $config->set('saml.fallback_login_route', 'local.login');
            $config->set('saml.gate', 'manage settings');

            // The published views are rendered from where they are shipped, and
            // the layout and field components they lean on are stood in for.
            // What is under test is the stub, not anybody's chrome.
            $config->set('view.paths', [
                ...$config->get('view.paths', []),
                __DIR__.'/Fixtures/views',
                __DIR__.'/../resources/stubs/views',
            ]);

            // The vendor package merges its own config in `boot()`, after it has
            // already registered its routes from it — which works in an
            // application because `config/saml2.php` is published there, and
            // does not work under Testbench unless the harness loads it first.
            // Consuming applications publish that config; see the README.
            //
            // Merged under whatever is already there rather than replacing it.
            // In an application the published file is loaded before any provider
            // registers, so `SsoServiceProvider::register()` sees it and writes
            // over the keys it owns; Testbench calls this after registration, and
            // a plain `set()` would undo that write.
            $config->set('saml2', array_merge(
                require __DIR__.'/../vendor/nbcsit/laravel-saml2/config/saml2.php',
                (array) $config->get('saml2', []),
            ));
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    /**
     * Routes the middleware and the published controllers are exercised on.
     * The package ships none of its own — see the README.
     */
    protected function defineRoutes($router): void
    {
        $router->get('local-login', fn () => 'the local password form')->name('local.login');

        $router->middleware(['web', 'saml.auth'])
            ->get('guarded', fn () => 'the guarded page')
            ->name('guarded');

        // The routes an application wires the published controllers up to. The
        // package ships none of these; naming them is the application's job,
        // and the views reference them by these names.
        $router->middleware('web')->prefix('admin/settings/saml')->name('admin.settings.saml.')->group(function ($router) {
            $router->get('/', [SamlSettingController::class, 'edit'])->name('edit');
            $router->put('/', [SamlSettingController::class, 'update'])->name('update');
            $router->post('tenant', [SamlSettingController::class, 'storeTenant'])->name('tenant.store');
            $router->put('tenant/{tenant}', [SamlSettingController::class, 'toggleTenant'])->name('tenant.toggle');
            $router->delete('tenant/{tenant}', [SamlSettingController::class, 'destroyTenant'])->name('tenant.destroy');

            $router->post('metadata', [SamlMetadataController::class, 'store'])->name('metadata.store');
            $router->get('metadata/{tenant}', [SamlMetadataController::class, 'show'])->name('metadata.show');
            $router->put('metadata/{tenant}', [SamlMetadataController::class, 'update'])->name('metadata.update');
            $router->post('metadata/{tenant}/refresh', [SamlMetadataController::class, 'refresh'])->name('metadata.refresh');
            $router->post('metadata/{tenant}/pending', [SamlMetadataController::class, 'applyPending'])->name('metadata.pending.apply');
            $router->delete('metadata/{tenant}/pending', [SamlMetadataController::class, 'discardPending'])->name('metadata.pending.discard');

            $router->get('certificate', [SamlCertificateController::class, 'show'])->name('certificate.show');
            $router->post('certificate', [SamlCertificateController::class, 'store'])->name('certificate.store');
            $router->post('certificate/promote', [SamlCertificateController::class, 'promote'])->name('certificate.promote');
            $router->put('certificate/signing', [SamlCertificateController::class, 'update'])->name('certificate.signing.update');
        });
    }
}
