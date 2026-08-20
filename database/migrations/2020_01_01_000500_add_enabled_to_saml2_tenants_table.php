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
            //
            // No `after()`. Laravel sorts every registered migration path into
            // one list by filename, so this file runs before the vendor
            // package's `2020_10_23_072902_add_name_id_format_column`, and on a
            // database built from empty the column to sit after does not exist
            // yet: "Unknown column 'name_id_format' in 'saml2_tenants'". The
            // dev box this was written on never showed it, because there the
            // vendor package had been installed and migrated first and the
            // order that produced was install history rather than filename
            // order. Position within the row is cosmetic; being able to
            // migrate at all is not.
            $table->boolean('enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('saml2_tenants', function (Blueprint $table) {
            $table->dropColumn('enabled');
        });
    }
};
