<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drops', function (Blueprint $table) {
            $table->string('storage_disk')->nullable();
            $table->string('upload_token_hash')->nullable();
            $table->timestamp('finalized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('drops', function (Blueprint $table) {
            $table->dropColumn(['storage_disk', 'upload_token_hash', 'finalized_at']);
        });
    }
};
