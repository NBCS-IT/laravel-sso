<?php

namespace NBCSIT\Sso;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use NBCSIT\Saml2\Events\SignedIn;
use NBCSIT\Saml2\Events\SignedOut;
use NBCSIT\Saml2\Models\Tenant;
use NBCSIT\Saml2\OneLoginBuilder;
use NBCSIT\Sso\Console\GenerateSpCertificateCommand;
use NBCSIT\Sso\Console\PromoteSpCertificateCommand;
use NBCSIT\Sso\Console\RefreshSamlMetadataCommand;
use NBCSIT\Sso\Contracts\ResolvesSamlUsers;
use NBCSIT\Sso\Http\Middleware\RequireSamlAuthentication;
use NBCSIT\Sso\Listeners\HandleSamlSignIn;
use NBCSIT\Sso\Listeners\HandleSamlSignOut;
use NBCSIT\Sso\Models\IdentityProvider;
use NBCSIT\Sso\Models\SamlAssertion;
use NBCSIT\Sso\Settings\SamlSettings;
use NBCSIT\Sso\Users\EloquentUserResolver;
use Spatie\LaravelSettings\SettingsContainer;

/**
 * All the wiring a consuming application would otherwise have to write itself.
 *
 * What it deliberately does not do is serve any web routes: the admin screens
 * are published into the application, which then owns their layout and their
 * route names. See the README.
 */
class SsoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/saml.php', 'saml');

        // The vendor package's `ResolveTenant` middleware asks the container for
        // the parent class by name, which is the only reason this override takes
        // effect at all — and it is what carries a tenant's full certificate
        // list to the toolkit during a key rollover.
        $this->app->bind(OneLoginBuilder::class, MultiCertificateOneLoginBuilder::class);

        // `bindIf`, so an application that binds its own resolver wins whichever
        // order the providers happen to load in.
        $this->app->bindIf(ResolvesSamlUsers::class, EloquentUserResolver::class);

        $this->registerSessionMiddleware();
    }

    public function boot(): void
    {
        $this->bootTenantModel();
        $this->bootRequestBinding();
        $this->bootSettings();

        Event::listen(SignedIn::class, HandleSamlSignIn::class);
        Event::listen(SignedOut::class, HandleSamlSignOut::class);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->app->make(Router::class)->aliasMiddleware('saml.auth', RequireSamlAuthentication::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RefreshSamlMetadataCommand::class,
                GenerateSpCertificateCommand::class,
                PromoteSpCertificateCommand::class,
            ]);
            $this->bootPublishes();
        }

        $this->bootSchedule();
    }

    /**
     * Give the vendor package's routes a session.
     *
     * Sign-in is a write to the session — the authenticated user, and the
     * outcome `RequireSamlAuthentication` turns into a page. The vendor package
     * ships `routesMiddleware => []`, so an application that follows its README
     * gets an assertion consumer with no session at all: the sign-in appears to
     * succeed, the session is discarded with the response, and the guarded page
     * redirects back to the identity provider. That loop is the whole reason
     * this default exists.
     *
     * `VerifyCsrfToken` is deliberately absent: the identity provider posts to
     * the ACS endpoint cross-site, so a token is neither present nor meaningful
     * there. Replay protection for those posts is this package's job, and lives
     * in {@see SamlAuthenticator}.
     *
     * This must stay in `register()`. The vendor provider includes its routes
     * file from its own `boot()`, and provider order is not guaranteed — by the
     * time any `boot()` here runs, the routes may already carry whatever
     * `routesMiddleware` said. `bootTenantModel()` gets away with `boot()`
     * because tenants resolve per request; middleware is fixed at route
     * registration.
     *
     * An application that has set `routesMiddleware` to something of its own
     * keeps it, on the same reasoning as the tenant model — but only the vendor
     * default is replaced, and `HandleSamlSignIn` refuses rather than
     * authenticating into a void if that replacement omits a session.
     */
    private function registerSessionMiddleware(): void
    {
        $this->app->make(Router::class)->middlewareGroup('saml.session', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            SubstituteBindings::class,
        ]);

        $configured = config('saml2.routesMiddleware');

        if ($configured === null || $configured === []) {
            config(['saml2.routesMiddleware' => ['saml.session']]);
        }
    }

    /**
     * Spatie Settings has three arrays that must know about this package, and
     * all three are arrays the application writes too — so every one of them is
     * appended to rather than replaced. Overwriting `settings.settings` would
     * unregister the consumer's own settings classes, and that failure looks
     * nothing like a SAML bug.
     *
     * All of this is in `boot()` rather than `register()`, and none of it may
     * move: provider order is not guaranteed, and `nbcsit/laravel-sso` sorts
     * before `spatie/laravel-settings` in a discovered application, so at
     * `register()` time Spatie has merged neither its config nor its bindings.
     * Appending to `settings.migrations_paths` there would replace the
     * application's `database/settings` rather than extend it.
     *
     * `registerBindings()` is called again because Spatie does it in its own
     * `register()`: if it ran first, it has already memoised a class list with
     * no mention of this package.
     */
    private function bootSettings(): void
    {
        config([
            'settings.settings' => array_values(array_unique([
                ...config('settings.settings', []),
                SamlSettings::class,
            ])),
            'settings.migrations_paths' => array_values(array_unique([
                ...config('settings.migrations_paths', []),
                (string) realpath(__DIR__.'/../database/settings'),
            ])),
            'settings.auto_discover_settings' => array_values(array_unique([
                ...config('settings.auto_discover_settings', []),
                __DIR__.'/Settings',
            ])),
        ]);

        $container = $this->app->make(SettingsContainer::class);
        $container->clearCache();
        $container->registerBindings();

        // Loaded here as well as appended above, because Spatie reads
        // `migrations_paths` in its own `boot()` — which may already have run.
        $this->loadMigrationsFrom(__DIR__.'/../database/settings');
    }

    /**
     * Resolve tenants as the model that knows about the metadata columns.
     *
     * An application that has deliberately pointed this at a third model of its
     * own keeps it — but the vendor default is replaced, because a login that
     * resolves a different model from the admin screens is a bug that shows up
     * only during a certificate rollover.
     */
    private function bootTenantModel(): void
    {
        $configured = config('saml2.tenantModel');

        if ($configured === null || $configured === Tenant::class) {
            config(['saml2.tenantModel' => IdentityProvider::class]);
        }
    }

    /**
     * Carry the request-binding switch down to the vendor package.
     *
     * The binding itself lives there — it is the vendor controller that keeps
     * the AuthnRequest's ID in the session and hands it back at the assertion
     * consumer. The switch lives here, because `config/saml.php` is where an
     * administrator looks for what this package demands of an identity
     * provider, and two switches meaning one thing is how they end up
     * disagreeing.
     *
     * The vendor package before 2.5.0 does not read this key and does not bind
     * anything; see {@see MultiCertificateOneLoginBuilder::applySecurityFloor()},
     * which is what stops that combination locking everybody out.
     */
    private function bootRequestBinding(): void
    {
        config(['saml2.strictRequestBinding' => config()->boolean('saml.security.strict_request_binding', false)]);
    }

    private function bootPublishes(): void
    {
        $this->publishes([
            __DIR__.'/../config/saml.php' => config_path('saml.php'),
        ], 'saml-config');

        // Stamped on publish, so it sorts after whatever the application has
        // already run. Not auto-loaded: the column names are configurable, and
        // altering a table the application owns is presumptuous.
        $this->publishes([
            __DIR__.'/../database/stubs/add_saml_columns_to_users_table.php.stub' => database_path(
                'migrations/'.date('Y_m_d_His').'_add_saml_columns_to_users_table.php',
            ),
        ], 'saml-migrations');

        $this->publishes([
            __DIR__.'/../resources/stubs/controllers/SamlSettingController.php.stub' => app_path('Http/Controllers/Admin/SamlSettingController.php'),
            __DIR__.'/../resources/stubs/controllers/SamlMetadataController.php.stub' => app_path('Http/Controllers/Admin/SamlMetadataController.php'),
            __DIR__.'/../resources/stubs/controllers/SamlCertificateController.php.stub' => app_path('Http/Controllers/Admin/SamlCertificateController.php'),
            __DIR__.'/../resources/stubs/views/admin/settings/saml.blade.php' => resource_path('views/admin/settings/saml.blade.php'),
            __DIR__.'/../resources/stubs/views/admin/settings/saml-metadata.blade.php' => resource_path('views/admin/settings/saml-metadata.blade.php'),
            __DIR__.'/../resources/stubs/views/admin/settings/saml-certificate.blade.php' => resource_path('views/admin/settings/saml-certificate.blade.php'),
        ], 'saml-admin');
    }

    /**
     * A package that silently adds a scheduled job is unwelcome. Both of these
     * are in `config/saml.php` where they can be seen, moved or switched off,
     * and each has its own switch: an administrator turning the metadata refresh
     * off should not silently stop the replay table being trimmed.
     */
    private function bootSchedule(): void
    {
        $this->bootMetadataRefresh();
        $this->bootAssertionPrune();
    }

    private function bootMetadataRefresh(): void
    {
        if (! config('saml.refresh.enabled', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(RefreshSamlMetadataCommand::class)
                ->dailyAt((string) config('saml.refresh.schedule', '03:15'))
                ->withoutOverlapping()
                ->runInBackground();
        });
    }

    /**
     * `saml_assertions` gains a row per sign-in and nothing else removes them.
     *
     * `--model` is scoped deliberately: an unscoped `model:prune` would prune
     * the consuming application's own models too, which is not this package's
     * decision to make.
     */
    private function bootAssertionPrune(): void
    {
        if (! config('saml.prune.enabled', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('model:prune', ['--model' => [SamlAssertion::class]])
                ->dailyAt((string) config('saml.prune.schedule', '03:45'))
                ->withoutOverlapping()
                ->runInBackground();
        });
    }
}
