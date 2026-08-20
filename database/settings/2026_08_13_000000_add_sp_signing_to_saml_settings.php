<?php

/*
| Separate from `create_saml_settings`, which has already run everywhere this
| package is installed. A settings migration is not re-run because its class
| gained a property, so adding these rows to the original file would leave every
| existing site resolving a settings class whose properties have no rows behind
| them — which Spatie reports as a missing-settings exception on the next
| request, not as a migration to run.
|
| Both default off. Switching signing on before the identity provider has
| imported this application's certificate makes it reject every request that
| follows, so it has to be a deliberate act taken in the right order.
*/

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('saml.sign_requests', false);
        $this->migrator->add('saml.sign_metadata', false);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('saml.sign_requests');
        $this->migrator->deleteIfExists('saml.sign_metadata');
    }
};
