<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where an identity provider's configuration came from, so it can be
        // fetched again. The package's own table holds only the values in use;
        // everything about keeping them current is added here.
        Schema::table('saml2_tenants', function (Blueprint $table) {
            $table->string('metadata_url')->nullable()->after('metadata');
            $table->boolean('metadata_auto_refresh')->default(false)->after('metadata_url');

            // A hash of the values this application actually uses, not of the
            // document. Entra stamps a fresh ID and validUntil on every fetch,
            // so hashing the raw XML would report a change every single day.
            $table->string('metadata_fingerprint', 64)->nullable()->after('metadata_auto_refresh');

            $table->timestamp('metadata_checked_at')->nullable()->after('metadata_fingerprint');
            $table->timestamp('metadata_synced_at')->nullable()->after('metadata_checked_at');
            $table->text('metadata_error')->nullable()->after('metadata_synced_at');

            // Every signing certificate the IdP publishes, not just the one in
            // `idp_x509_cert`. During a rollover Entra publishes the outgoing
            // and incoming keys together and signs with either, so storing one
            // of them is a coin toss that shows up as "invalid signature".
            $table->json('idp_x509_cert_multi')->nullable()->after('idp_x509_cert');

            // Endpoint changes an unattended refresh found but refused to write.
            // See App\Services\Saml\IdpMetadataSynchroniser for why.
            $table->json('pending_metadata')->nullable()->after('metadata_error');
            $table->timestamp('pending_metadata_at')->nullable()->after('pending_metadata');
        });
    }

    public function down(): void
    {
        Schema::table('saml2_tenants', function (Blueprint $table) {
            $table->dropColumn([
                'metadata_url',
                'metadata_auto_refresh',
                'metadata_fingerprint',
                'metadata_checked_at',
                'metadata_synced_at',
                'metadata_error',
                'idp_x509_cert_multi',
                'pending_metadata',
                'pending_metadata_at',
            ]);
        });
    }
};
