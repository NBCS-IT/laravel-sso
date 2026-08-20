<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replay protection. An assertion that has already been consumed must
        // not sign anyone in a second time, so its message ID is recorded here
        // and rejected on sight. Rows past `not_on_or_after` are prunable.
        Schema::create('saml_assertions', function (Blueprint $table) {
            $table->id();
            $table->string('request_id')->unique();
            $table->timestamp('not_on_or_after')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saml_assertions');
    }
};
