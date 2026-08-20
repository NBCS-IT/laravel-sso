<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The moment an assertion claimed a local account that already existed.
        //
        // An account provisioned for an assertion is not recorded: it carries
        // the NameID from the moment it exists and is nobody else's. What is
        // here is the other case — an address in an assertion becoming
        // ownership of an account somebody created by hand, which until now
        // left no trace beyond a changed column.
        //
        // No foreign key on `user_id`: the users table is the consuming
        // application's, under a name this package does not know, and an audit
        // row should outlive the account it describes in any case.
        Schema::create('saml_account_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name_id');
            $table->string('email')->nullable();

            // How the account was found. Only 'email' is written today; the
            // column is here so a second way of claiming one is distinguishable
            // in the same history rather than needing its own table.
            $table->string('matched_by', 20);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saml_account_links');
    }
};
