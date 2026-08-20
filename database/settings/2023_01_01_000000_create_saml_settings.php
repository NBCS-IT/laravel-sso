<?php

/*
| Dated after Spatie's own `create_settings_table`, which it publishes into an
| application stamped 2022_12_14_083707. Migration order is global across every
| loaded path, so a settings migration dated earlier than that runs before the
| table it writes to exists.
*/

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('saml.enabled', false);
        $this->migrator->add('saml.provision_users', true);
        $this->migrator->add('saml.sync_groups', true);

        // Entra ID's default claim URIs. Sites using short claim names can change
        // these on the SAML settings screen without a deploy.
        $this->migrator->add('saml.email_attribute', 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress');
        $this->migrator->add('saml.name_attribute', 'http://schemas.microsoft.com/identity/claims/displayname');
        $this->migrator->add('saml.groups_claim', 'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups');

        $this->migrator->add('saml.group_role_map', []);
        $this->migrator->add('saml.default_uuid', null);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('saml.enabled');
        $this->migrator->deleteIfExists('saml.provision_users');
        $this->migrator->deleteIfExists('saml.sync_groups');
        $this->migrator->deleteIfExists('saml.email_attribute');
        $this->migrator->deleteIfExists('saml.name_attribute');
        $this->migrator->deleteIfExists('saml.groups_claim');
        $this->migrator->deleteIfExists('saml.group_role_map');
        $this->migrator->deleteIfExists('saml.default_uuid');
    }
};
