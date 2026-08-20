<?php

use NBCSIT\Saml2\Models\Tenant;
use NBCSIT\Sso\Settings\SamlSettings;
use Ramsey\Uuid\Uuid;

function settingsWithUuid(?string $uuid): SamlSettings
{
    $settings = app(SamlSettings::class);
    $settings->default_uuid = $uuid;
    $settings->save();

    return $settings;
}

describe('the active tenant', function () {
    it('is null when none has been selected', function () {
        expect(settingsWithUuid(null)->activeTenant())->toBeNull();
    });

    it('is null when the stored value is not a uuid at all', function () {
        expect(settingsWithUuid('not-a-uuid')->activeTenant())->toBeNull();
    });

    it('is null when the uuid points at nothing', function () {
        expect(settingsWithUuid(Uuid::uuid4()->toString())->activeTenant())->toBeNull();
    });

    it('is the selected tenant', function () {
        $tenant = Tenant::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'key' => 'Entra',
            'idp_entity_id' => 'urn:example:idp',
            'idp_login_url' => 'https://login.example.com/sso',
            'idp_logout_url' => '',
            'idp_x509_cert' => 'CERT',
            'metadata' => [],
        ]);

        expect(settingsWithUuid($tenant->uuid)->activeTenant()->is($tenant))->toBeTrue();
    });
});

describe('usability', function () {
    it('needs both the switch and a tenant', function () {
        $tenant = Tenant::query()->create([
            'uuid' => Uuid::uuid4()->toString(),
            'key' => 'Entra',
            'idp_entity_id' => 'urn:example:idp',
            'idp_login_url' => 'https://login.example.com/sso',
            'idp_logout_url' => '',
            'idp_x509_cert' => 'CERT',
            'metadata' => [],
        ]);

        $settings = app(SamlSettings::class);

        $settings->enabled = false;
        $settings->default_uuid = $tenant->uuid;
        $settings->save();
        expect($settings->isUsable())->toBeFalse();

        $settings->enabled = true;
        $settings->default_uuid = null;
        $settings->save();
        expect($settings->isUsable())->toBeFalse();

        $settings->default_uuid = $tenant->uuid;
        $settings->save();
        expect($settings->isUsable())->toBeTrue();
    });
});

describe('group to role mapping', function () {
    it('maps only the groups it knows', function () {
        $settings = app(SamlSettings::class);
        $settings->group_role_map = ['a' => 'Role A', 'b' => 'Role B'];
        $settings->save();

        expect($settings->rolesForGroups(['a', 'unknown', 'b']))->toBe(['Role A', 'Role B'])
            ->and($settings->rolesForGroups(['unknown']))->toBe([])
            ->and($settings->rolesForGroups([]))->toBe([]);
    });

    it('de-duplicates when two groups grant the same role', function () {
        $settings = app(SamlSettings::class);
        $settings->group_role_map = ['a' => 'Same Role', 'b' => 'Same Role'];
        $settings->save();

        expect($settings->rolesForGroups(['a', 'b']))->toBe(['Same Role']);
    });

    it('ignores a mapping with a blank role', function () {
        $settings = app(SamlSettings::class);
        $settings->group_role_map = ['a' => ''];
        $settings->save();

        expect($settings->rolesForGroups(['a']))->toBe([]);
    });
});

describe('the signing switches', function () {
    it('is off on both counts until somebody says otherwise', function () {
        $settings = app(SamlSettings::class);

        expect($settings->sign_requests)->toBeFalse()
            ->and($settings->sign_metadata)->toBeFalse();
    });
});
