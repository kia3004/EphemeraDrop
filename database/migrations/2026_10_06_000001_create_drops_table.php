<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drops', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('kind', 4);
            $table->mediumText('ciphertext')->nullable();
            $table->string('storage_path')->nullable();
            $table->unsignedInteger('chunk_count')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('kdf_salt')->nullable();
            $table->boolean('has_password')->default(false);
            $table->unsignedTinyInteger('max_views')->default(3);
            $table->unsignedTinyInteger('views')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->string('delete_token_hash');
            $table->text('notify_url')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drops');
    }
};
