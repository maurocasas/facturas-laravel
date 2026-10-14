<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The table DatabaseStore uses (the `arca_store` table of the TypeScript SDK's
 * Postgres store): one row per key, plus one lease row per held lock whose
 * `updated_at` the holder renews.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arca_store', function (Blueprint $table) {
            $table->string('key', 512)->primary();
            $table->longText('value');
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arca_store');
    }
};
