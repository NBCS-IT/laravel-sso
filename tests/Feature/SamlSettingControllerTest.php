<?php

use NBCSIT\Sso\Enums\SamlMetadataOutcome;
use NBCSIT\Sso\Enums\SamlMetadataSource;
use NBCSIT\Sso\Models\IdentityProvider;
use NBCSIT\Sso\Models\SamlMetadataEvent;
use NBCSIT\Sso\Settings\SamlSettings;
use NBCSIT\Sso\Tests\Fixtures\SamlMetadataFixtures;
use Spatie\Permission\Models\Role;

/*
| The other published stub. It is tested here, against the package's own copy
| mounted on the harness's routes, for the same reason the metadata controller
| is: a published stub nobody tests rots, and the person it rots on is whoever
| adopts this package next.
*/

beforeEach(function () {
    $this->admin = userWithPermissions(config('saml.gate'));
});

describe('authorisation', function () {
    it('turns away a user without the settings permission', function () {
        $tenant = IdentityProvider::factory()->create();
        $viewer = userWithPermissions('some other ability');

        $this->actingAs($viewer)->get(route('admin.settings.saml.edit'))->assertForbidden();
        $this->actingAs($viewer)->put(route('admin.settings.saml.update'))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.settings.saml.tenant.store'))->assertForbidden();
        $this->actingAs($viewer)->delete(route('admin.settings.saml.tenant.destroy', $tenant))->assertForbidden();
        $this->actingAs($viewer)->put(route('admin.settings.saml.tenant.toggle', $tenant))->assertForbidden();
    });
});

/*
| Removing a provider is revocation and it is permanent. This is the same
| revocation with the row kept, which is what a standby provider or one that is
| merely under suspicion calls for.
*/
describe('switching a provider off', function () {
    it('stops it signing anybody in, and says so in the history', function () {
        $provider = IdentityProvider::factory()->create(['key' => 'Entra ID', 'enabled' => true]);

        $this->actingAs($this->admin)
            ->put(route('admin.settings.saml.tenant.toggle', $provider))
            ->assertSessionHas('status');

        expect($provider->fresh()->enabled)->toBeFalse()
            ->and(SamlMetadataEvent::query()->sole()->message)->toContain('Switched off "Entra ID"');
    });

    it('switches it back on again', function () {
        $provider = IdentityProvider::factory()->create(['enabled' => false]);

        $this->actingAs($this->admin)->put(route('admin.settings.saml.tenant.toggle', $provider));

        expect($provider->fresh()->enabled)->toBeTrue();
    });

    it('does not leave sign-on pointed at a provider that refuses everybody', function () {
        $provider = IdentityProvider::factory()->create(['enabled' => true]);
        samlSettings(['enabled' => true, 'default_uuid' => $provider->uuid]);

        $this->actingAs($this->admin)->put(route('admin.settings.saml.tenant.toggle', $provider));

        $settings = app(SamlSettings::class)->refresh();

        expect($settings->enabled)->toBeFalse()
            ->and($settings->default_uuid)->toBeNull();
    });

    it('leaves the settings alone when it is not the provider in use', function () {
        $inUse = IdentityProvider::factory()->create();
        $standby = IdentityProvider::factory()->create();
        samlSettings(['enabled' => true, 'default_uuid' => $inUse->uuid]);

        $this->actingAs($this->admin)->put(route('admin.settings.saml.tenant.toggle', $standby));

        expect(app(SamlSettings::class)->refresh()->default_uuid)->toBe($inUse->uuid);
    });

    it('shows the state on the settings screen', function () {
        IdentityProvider::factory()->create(['key' => 'Standby', 'enabled' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.settings.saml.edit'))
            ->assertOk()
            ->assertSee('Switched off');
    });
});

describe('the settings screen', function () {
    it('lists the providers and the roles a group can be mapped to', function () {
        $provider = IdentityProvider::factory()->create(['key' => 'Entra ID']);
        Role::findOrCreate('Network Team', config('saml.guard'));

        $this->actingAs($this->admin)
            ->get(route('admin.settings.saml.edit'))
            ->assertOk()
            ->assertSee('Entra ID')
            ->assertSee('Network Team');
    });

    it('saves the fields the package owns', function () {
        Role::findOrCreate('Network Team', config('saml.guard'));
        $provider = IdentityProvider::factory()->create();

        $this->actingAs($this->admin)->put(route('admin.settings.saml.update'), [
            'enabled' => '1',
            'provision_users' => '1',
            'sync_groups' => '1',
            'email_attribute' => 'mail',
            'name_attribute' => 'displayName',
            'groups_claim' => 'groups',
            'default_uuid' => $provider->uuid,
            'group_map' => [
                ['group' => 'group-network-oid', 'role' => 'Network Team'],
                // A blank row is how a mapping is removed, and must not be saved.
                ['group' => '', 'role' => ''],
            ],
        ])->assertSessionHas('status');

        $settings = app(SamlSettings::class)->refresh();

        expect($settings->enabled)->toBeTrue()
            ->and($settings->email_attribute)->toBe('mail')
            ->and($settings->name_attribute)->toBe('displayName')
            ->and($settings->groups_claim)->toBe('groups')
            ->and($settings->default_uuid)->toBe($provider->uuid)
            ->and($settings->group_role_map)->toBe(['group-network-oid' => 'Network Team']);
    });

    it('refuses a role that does not exist', function () {
        $this->actingAs($this->admin)->put(route('admin.settings.saml.update'), [
            'email_attribute' => 'mail',
            'name_attribute' => 'displayName',
            'groups_claim' => 'groups',
            'group_map' => [['group' => 'g', 'role' => 'No Such Role']],
        ])->assertSessionHasErrors('group_map.0.role');
    });

    it('ignores a group row that is not a row at all', function () {
        $this->actingAs($this->admin)->put(route('admin.settings.saml.update'), [
            'email_attribute' => 'mail',
            'name_attribute' => 'displayName',
            'groups_claim' => 'groups',
            'group_map' => ['not-an-array'],
        ])->assertSessionHas('status');

        expect(app(SamlSettings::class)->refresh()->group_role_map)->toBe([]);
    });
});

describe('adding a provider by hand', function () {
    it('stores it, logs it and selects the first one', function () {
        $this->actingAs($this->admin)->post(route('admin.settings.saml.tenant.store'), [
            'name' => 'Entra ID',
            'idp_entity_id' => SamlMetadataFixtures::ENTITY_ID,
            'idp_login_url' => SamlMetadataFixtures::SSO_URL,
            'idp_logout_url' => SamlMetadataFixtures::SLO_URL,
            'idp_x509_cert' => "-----BEGIN CERTIFICATE-----\n".chunk_split(SamlMetadataFixtures::CERT_A, 64, "\n")."-----END CERTIFICATE-----\n",
            'name_id_format' => 'emailAddress',
        ])->assertSessionHas('status');

        $tenant = IdentityProvider::query()->sole();

        // The armour and the line breaks are stripped, because that is the
        // shape the toolkit's column wants.
        expect($tenant->idp_x509_cert)->toBe(SamlMetadataFixtures::CERT_A)
            ->and($tenant->idp_x509_cert_multi)->toBe([SamlMetadataFixtures::CERT_A])
            ->and($tenant->name_id_format)->toBe('emailAddress');

        $event = SamlMetadataEvent::query()->sole();

        expect($event->outcome)->toBe(SamlMetadataOutcome::Created)
            ->and($event->source)->toBe(SamlMetadataSource::Manual)
            ->and($event->user_id)->toBe($this->admin->getKey())
            ->and($event->actor())->toBe($this->admin->name);

        expect(app(SamlSettings::class)->refresh()->default_uuid)->toBe($tenant->uuid);
    });

    it('leaves the NameID format at the package default when none is given', function () {
        $this->actingAs($this->admin)->post(route('admin.settings.saml.tenant.store'), [
            'name' => 'Entra ID',
            'idp_entity_id' => SamlMetadataFixtures::ENTITY_ID,
            'idp_login_url' => SamlMetadataFixtures::SSO_URL,
            'idp_x509_cert' => SamlMetadataFixtures::CERT_A,
        ]);

        $tenant = IdentityProvider::query()->sole();

        expect($tenant->name_id_format)->not->toBeEmpty()
            ->and($tenant->idp_logout_url)->toBe('');
    });

    it('leaves an already chosen provider alone', function () {
        $existing = IdentityProvider::factory()->create();
        samlSettings(['default_uuid' => $existing->uuid]);

        $this->actingAs($this->admin)->post(route('admin.settings.saml.tenant.store'), [
            'name' => 'Second',
            'idp_entity_id' => 'https://second.example.edu.au',
            'idp_login_url' => 'https://second.example.edu.au/sso',
            'idp_x509_cert' => SamlMetadataFixtures::CERT_A,
        ]);

        expect(app(SamlSettings::class)->refresh()->default_uuid)->toBe($existing->uuid);
    });
});

describe('removing a provider', function () {
    it('records the removal before making it, so the history still reads', function () {
        $provider = IdentityProvider::factory()->create(['key' => 'Entra ID']);

        $this->actingAs($this->admin)
            ->delete(route('admin.settings.saml.tenant.destroy', $provider))
            ->assertSessionHas('status');

        expect(IdentityProvider::query()->count())->toBe(0);

        $event = SamlMetadataEvent::query()->sole();

        expect($event->outcome)->toBe(SamlMetadataOutcome::Removed)
            ->and($event->provider_name)->toBe('Entra ID')
            ->and($event->message)->toContain('Removed "Entra ID"');
    });

    it('switches sign-on off rather than leaving a uuid pointing at nothing', function () {
        $provider = IdentityProvider::factory()->create();
        samlSettings(['enabled' => true, 'default_uuid' => $provider->uuid]);

        $this->actingAs($this->admin)->delete(route('admin.settings.saml.tenant.destroy', $provider));

        $settings = app(SamlSettings::class)->refresh();

        expect($settings->default_uuid)->toBeNull()
            ->and($settings->enabled)->toBeFalse();
    });

    it('leaves the chosen provider alone when a different one is removed', function () {
        $chosen = IdentityProvider::factory()->create();
        $other = IdentityProvider::factory()->create();
        samlSettings(['enabled' => true, 'default_uuid' => $chosen->uuid]);

        $this->actingAs($this->admin)->delete(route('admin.settings.saml.tenant.destroy', $other));

        $settings = app(SamlSettings::class)->refresh();

        expect($settings->default_uuid)->toBe($chosen->uuid)
            ->and($settings->enabled)->toBeTrue();
    });
});
