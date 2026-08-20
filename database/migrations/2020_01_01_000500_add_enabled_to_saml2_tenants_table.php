<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saml2_tenants', function (Blueprint $table) {
            // Whether this provider may sign anybody in.
            //
            // `saml.default_uuid` only decides which provider /login hands off
            // to. It does not restrict which providers may authenticate: every
            // row is reachable at `/saml2/{uuid}/acs` and is therefore a live
            // trust anchor, so a decommissioned provider left in the table
            // still grants access. Applications here keep more than one row on
            // purpose — a standby provider to swap to during an outage — so the
            // answer is a flag per row rather than "only the default one".
            //
            // Default true: an existing row is in use until somebody says
            // otherwise, and an upgrade must not lock a site out of itself.
            $table->boolean('enabled')->default(true)->after('name_id_format');
        });
    }

    public function down(): void
    {
        Schema::table('saml2_tenants', function (Blueprint $table) {
            $table->dropColumn('enabled');
        });
    }
};
