<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });

            return;
        }

        $privateColumns = array_values(array_filter(
            ['user_id', 'ip_address', 'user_agent'],
            fn (string $column): bool => Schema::hasColumn('sessions', $column),
        ));

        if ($privateColumns !== []) {
            Schema::table('sessions', function (Blueprint $table) use ($privateColumns) {
                $table->dropColumn($privateColumns);
            });
        }
    }

    public function down(): void
    {
        // Keep session data intact when rolling this migration back.
    }
};
