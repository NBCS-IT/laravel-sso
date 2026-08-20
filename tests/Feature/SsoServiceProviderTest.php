<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use NBCSIT\Saml2\Models\Tenant;
use NBCSIT\Saml2\OneLoginBuilder;
use NBCSIT\Sso\Contracts\ResolvesSamlUsers;
use NBCSIT\Sso\Http\Middleware\RequireSamlAuthentication;
use NBCSIT\Sso\Models\IdentityProvider;
use NBCSIT\Sso\Models\SamlAssertion;
use NBCSIT\Sso\MultiCertificateOneLoginBuilder;
use NBCSIT\Sso\Settings\SamlSettings;
use NBCSIT\Sso\SsoServiceProvider;
use NBCSIT\Sso\Tests\Fixtures\ApplicationSettings;
use NBCSIT\Sso\Users\EloquentUserResolver;

describe('the container bindings', function () {
    it('answers the vendor middleware with the multi-certificate builder', function () {
        expect(app(OneLoginBuilder::class))->toBeInstanceOf(MultiCertificateOneLoginBuilder::class);
    });

    it('resolves users through the Eloquent resolver by default', function () {
        expect(app(ResolvesSamlUsers::class))->toBeInstanceOf(EloquentUserResolver::class);
    });

    it('leaves an application\'s own resolver in place', function () {
        $mine = new class extends EloquentUserResolver {};

        app()->instance(ResolvesSamlUsers::class, $mine);

        (new SsoServiceProvider(app()))->register();

        expect(app(ResolvesSamlUsers::class))->toBe($mine);
    });
});

describe('the tenant model', function () {
    it('points the vendor package at the model that knows the metadata columns', function () {
        expect(config('saml2.tenantModel'))->toBe(IdentityProvider::class);
    });

    it('replaces the vendor default', function () {
        config(['saml2.tenantModel' => Tenant::class]);

        (new SsoServiceProvider(app()))->boot();

        expect(config('saml2.tenantModel'))->toBe(IdentityProvider::class);
    });

    it('leaves a third model an application chose deliberately', function () {
        config(['saml2.tenantModel' => 'App\Models\SomethingElse']);

        (new SsoServiceProvider(app()))->boot();

        expect(config('saml2.tenantModel'))->toBe('App\Models\SomethingElse');
    });
});

describe('the session middleware', function () {
    it('registers a group with a session in it and no CSRF check', function () {
        expect(app(Router::class)->getMiddlewareGroups()['saml.session'] ?? null)->toBe([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            SubstituteBindings::class,
        ]);
    });

    /*
     * The one that matters: the vendor package registers its routes from
     * `saml2.routesMiddleware` during its own `boot()`, so this asserts against
     * the route as the router finally holds it rather than against the config
     * the default was written to.
     */
    it('puts a session on the vendor package\'s assertion consumer', function () {
        $route = app(Router::class)->getRoutes()->getByName('saml.acs');

        expect($route)->not->toBeNull()
            ->and(app(Router::class)->gatherRouteMiddleware($route))
            ->toContain(StartSession::class)
            ->not->toContain(VerifyCsrfToken::class);
    });

    it('replaces the vendor default', function () {
        config(['saml2.routesMiddleware' => []]);

        (new SsoServiceProvider(app()))->register();

        expect(config('saml2.routesMiddleware'))->toBe(['saml.session']);
    });

    it('fills in an unpublished vendor config', function () {
        config(['saml2.routesMiddleware' => null]);

        (new SsoServiceProvider(app()))->register();

        expect(config('saml2.routesMiddleware'))->toBe(['saml.session']);
    });

    it('leaves a group an application chose deliberately', function () {
        config(['saml2.routesMiddleware' => ['my-own-group']]);

        (new SsoServiceProvider(app()))->register();

        expect(config('saml2.routesMiddleware'))->toBe(['my-own-group']);
    });
});

describe('Spatie Settings wiring', function () {
    /*
    | All three of these are arrays the application writes too. Replacing any of
    | them would unregister a consumer's own settings classes, and that failure
    | looks nothing like a SAML bug — hence a test per array rather than one
    | test that only checks this package's own entry arrived.
    */

    it('appends its settings class without unregistering the application\'s', function () {
        expect(config('settings.settings'))
            ->toContain(SamlSettings::class)
            ->toContain(ApplicationSettings::class);
    });

    it('appends its settings migration path without dropping the application\'s', function () {
        expect(config('settings.migrations_paths'))
            ->toContain(realpath(__DIR__.'/../../database/settings'))
            ->toContain(realpath(__DIR__.'/../Fixtures/settings'));
    });

    it('appends its discovery path without dropping the application\'s', function () {
        expect(config('settings.auto_discover_settings'))
            ->toContain(realpath(__DIR__.'/../../src/Settings'))
            ->toContain(realpath(__DIR__.'/../Fixtures'));
    });

    it('leaves the application\'s own settings resolvable and migrated', function () {
        expect(app(ApplicationSettings::class)->unrelated_to_saml)->toBe('still here')
            ->and(app(SamlSettings::class))->toBeInstanceOf(SamlSettings::class);
    });
});

describe('migrations', function () {
    /*
    | The package alters `saml2_tenants`, which the vendor package creates. The
    | filenames are dated 2020 so they sort after the vendor's 2019 one, and
    | this is the assertion that says so — it is the single most likely thing to
    | break on a new installation and the least likely to be noticed on an
    | existing one.
    */

    it('adds the metadata columns to the vendor tenants table', function () {
        foreach ([
            'metadata_url',
            'metadata_auto_refresh',
            'metadata_fingerprint',
            'metadata_checked_at',
            'metadata_synced_at',
            'metadata_error',
            'idp_x509_cert_multi',
            'pending_metadata',
            'pending_metadata_at',
        ] as $column) {
            expect(Schema::hasColumn('saml2_tenants', $column))->toBeTrue();
        }
    });

    it('creates its own tables', function () {
        expect(Schema::hasTable('saml_assertions'))->toBeTrue()
            ->and(Schema::hasTable('saml_metadata_events'))->toBeTrue();
    });

    it('sorts its migrations after the vendor package creates the table', function () {
        $create = collect(glob(__DIR__.'/../../vendor/nbcsit/laravel-saml2/database/migrations/*create_saml2_tenants_table.php'))
            ->map(fn (string $path) => basename($path))
            ->sole();

        $ours = collect(glob(__DIR__.'/../../database/migrations/*.php'))
            ->map(fn (string $path) => basename($path))
            ->sort()
            ->first();

        expect(strcmp($ours, $create))->toBeGreaterThan(0);
    });

    it('never positions a column after one a later vendor migration adds', function () {
        /*
        | The create table coming first is necessary and was once assumed to be
        | sufficient. It is not. Laravel sorts every registered migration path
        | into one list by filename, so a `2020_01_01_*` file here runs before
        | the vendor's `2020_10_23_*`, and `->after('name_id_format')` on a
        | database built from empty asks for a column that does not exist yet.
        |
        | An installation that already has the vendor package migrated cannot
        | show this: there the order is install history, not filename order. It
        | surfaces only on the next new server, which is the worst place to find
        | it. Neither can the suite run it into the ground — these tests are on
        | SQLite, whose grammar ignores column positioning outright. So the
        | invariant is checked by reading the files rather than by migrating.
        */
        $columnsDeclaredIn = function (string $path): array {
            // Every method taking a single quoted name, less the ones that
            // reference or modify a column rather than declare one. A blacklist
            // and not a whitelist of column types on purpose: a type nobody
            // thought of here should fail loudly, not be waved through.
            $notDeclarations = [
                'after', 'dropColumn', 'dropIfExists', 'default', 'index',
                'unique', 'foreign', 'references', 'on', 'constrained',
                'comment', 'change', 'nullable', 'charset', 'collation',
                'storedAs', 'virtualAs', 'from', 'useCurrent', 'table',
            ];

            preg_match_all(
                '/->([a-zA-Z_]+)\(\s*\'([^\']+)\'/',
                (string) file_get_contents($path),
                $matches,
                PREG_SET_ORDER,
            );

            return collect($matches)
                ->reject(fn (array $m) => in_array($m[1], $notDeclarations, true))
                ->pluck(2)
                ->unique()
                ->all();
        };

        $vendor = collect(glob(__DIR__.'/../../vendor/nbcsit/laravel-saml2/database/migrations/*.php'))
            ->mapWithKeys(fn (string $path) => [basename($path) => $columnsDeclaredIn($path)]);

        foreach (glob(__DIR__.'/../../database/migrations/*.php') as $path) {
            preg_match_all('/->after\(\s*\'([^\']+)\'/', (string) file_get_contents($path), $matches);

            $ours = basename($path);

            // What this file may not lean on: anything introduced by a vendor
            // migration that Laravel will not have run by the time it does.
            $tooLate = $vendor
                ->filter(fn (array $columns, string $name) => strcmp($name, $ours) > 0)
                ->flatten()
                ->all();

            expect(array_intersect($matches[1], $tooLate))
                ->toBe([], "{$ours} positions a column after one a later vendor migration adds.");
        }
    });
});

describe('the console command', function () {
    it('is registered under a name that is not an application\'s', function () {
        expect(app('Illuminate\Contracts\Console\Kernel')->all())->toHaveKey('saml:refresh-metadata');
    });

    it('is scheduled at the configured time', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'saml:refresh-metadata'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('15 3 * * *');
    });

    it('adds no scheduled job when the refresh is switched off', function () {
        $before = count(app(Schedule::class)->events());

        config(['saml.refresh.enabled' => false, 'saml.prune.enabled' => false]);

        (new SsoServiceProvider(app()))->boot();

        expect(app(Schedule::class)->events())->toHaveCount($before);
    });
});

/*
| `saml_assertions` gains a row per sign-in and nothing else removes them, so
| the prune is scheduled — but on its own switch, because switching the metadata
| refresh off is not a decision about the replay table.
*/
describe('the assertion prune', function () {
    it('is scheduled at the configured time, against this package\'s model alone', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'model:prune'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('45 3 * * *')
            ->and($events->first()->command)->toContain(SamlAssertion::class);
    });

    it('adds no scheduled job when it is switched off', function () {
        config(['saml.refresh.enabled' => false, 'saml.prune.enabled' => false]);

        (new SsoServiceProvider(app()))->boot();

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'model:prune'));

        expect($events)->toHaveCount(1);
    });
});

describe('the middleware alias', function () {
    it('registers saml.auth', function () {
        expect(app('router')->getMiddleware())
            ->toHaveKey('saml.auth')
            ->and(app('router')->getMiddleware()['saml.auth'])->toBe(RequireSamlAuthentication::class);
    });
});

describe('the publish groups', function () {
    it('publishes each tag\'s files, and every source exists', function () {
        $tags = [
            'saml-config' => ['saml.php'],
            'saml-migrations' => ['add_saml_columns_to_users_table.php'],
            'saml-admin' => [
                'SamlSettingController.php',
                'SamlMetadataController.php',
                'SamlCertificateController.php',
                'saml.blade.php',
                'saml-metadata.blade.php',
                'saml-certificate.blade.php',
            ],
        ];

        foreach ($tags as $tag => $expected) {
            $paths = ServiceProvider::pathsToPublish(SsoServiceProvider::class, $tag);

            expect($paths)->toHaveCount(count($expected));

            foreach ($paths as $source => $destination) {
                expect(file_exists($source))->toBeTrue()
                    ->and($expected)->toContain(preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($destination)));
            }
        }
    });

    it('stamps the users-table migration so it sorts after what is already run', function () {
        $destination = array_values(ServiceProvider::pathsToPublish(SsoServiceProvider::class, 'saml-migrations'))[0];

        expect(basename($destination))->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_add_saml_columns_to_users_table\.php$/');
    });
});
