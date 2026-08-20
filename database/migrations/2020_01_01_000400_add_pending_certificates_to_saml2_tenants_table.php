<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saml2_tenants', function (Blueprint $table) {
            // The signing certificates a refresh found but refused to write.
            //
            // Adding a certificate beside the one in use is a rollover and is
            // routine — keeping up with those unattended is the whole reason a
            // metadata URL exists. Replacing every certificate at once is not a
            // rollover: it is a new trust anchor, and the signing certificate
            // decides who may sign in. Those wait for an administrator, and the
            // values they are waiting to become are kept here, since
            // `pending_metadata` holds descriptions for a person to read rather
            // than certificate bodies to write.
            $table->json('pending_certificates')->nullable()->after('pending_metadata');
        });
    }

    public function down(): void
    {
        Schema::table('saml2_tenants', function (Blueprint $table) {
            $table->dropColumn('pending_certificates');
        });
    }
};
