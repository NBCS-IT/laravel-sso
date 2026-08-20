<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('application.unrelated_to_saml', 'still here');
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('application.unrelated_to_saml');
    }
};
