<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The history of an identity provider's configuration: what changed,
        // when, from where, and at whose hand. Its whole purpose is the morning
        // after — sign-in broke overnight, and this says whether anything moved.
        //
        // A scheduled check that found nothing writes no row. `metadata_checked_at`
        // on the tenant records that it ran; a log of "no change" every day at
        // 03:15 would bury the one row worth reading.
        Schema::create('saml_metadata_events', function (Blueprint $table) {
            $table->id();

            // `unsignedInteger`, not `foreignId`. The tenants table is the
            // vendor package's, and it declares its key as `increments('id')`,
            // which is an unsigned INT — where `foreignId` is an unsigned
            // BIGINT. MySQL and MariaDB require the two sides of a foreign key
            // to be the same type and refuse the constraint outright:
            // errno 150, "Foreign key constraint is incorrectly formed".
            // SQLite does not check, so a test suite on SQLite cannot see it.
            $table->unsignedInteger('tenant_id')->nullable();
            $table->foreign('tenant_id')->references('id')->on('saml2_tenants')->nullOnDelete();

            $table->string('provider_name');
            $table->string('source');
            $table->string('outcome');
            $table->string('metadata_url')->nullable();
            $table->text('message');
            $table->json('change_set');
            $table->json('warnings');

            // Null is the schedule. A row with no user is one nobody watched.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saml_metadata_events');
    }
};
