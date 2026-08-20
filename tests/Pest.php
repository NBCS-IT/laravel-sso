<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use NBCSIT\Sso\Settings\SamlSettings;
use NBCSIT\Sso\Tests\Fixtures\User;
use NBCSIT\Sso\Tests\TestCase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

pest()->extend(TestCase::class)->in('Unit');
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A user holding exactly the named permissions, and nothing else.
 *
 * Tests name the permission they are exercising rather than a role, so a change
 * to what a role bundles cannot quietly widen what a test proves.
 */
function userWithPermissions(string ...$permissions): User
{
    $guard = config('saml.guard');

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, $guard);
    }

    $role = Role::findOrCreate('Test Role '.md5(implode('|', $permissions)), $guard);
    $role->syncPermissions($permissions);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return User::factory()->create()->assignRole($role);
}

/**
 * A throwaway disk for the service provider's own certificate and key.
 *
 * A named disk rather than `local`, so nothing here can reach into Testbench's
 * own `storage/`. Nothing else needs wiring: the store resolves
 * `saml.certificate.disk` on every call rather than capturing it, which is what
 * makes a fake work at all.
 */
function fakeCertificateDisk(string $disk = 'saml-certificates'): Filesystem
{
    config([
        'saml.certificate.disk' => $disk,
        'saml.certificate.path' => 'certs',
    ]);

    return Storage::fake($disk);
}

/**
 * The package's settings, with the named fields written and saved.
 *
 * @param  array<string, mixed>  $overrides
 */
function samlSettings(array $overrides = []): SamlSettings
{
    $settings = app(SamlSettings::class);

    foreach ($overrides as $key => $value) {
        $settings->{$key} = $value;
    }

    $settings->save();

    return $settings;
}
