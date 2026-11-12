<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The importer's ledger: which v3 row each v2 row became.
 *
 * It is what makes the import re-runnable. A second run finds the row it made
 * last time and updates it, instead of making another, and skips it entirely
 * when the source row has not changed since (the checksum).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_id_map', function (Blueprint $table) {
            $table->id();
            $table->string('source_table', 60);
            $table->unsignedBigInteger('source_id');
            $table->string('target_type', 120);
            $table->unsignedBigInteger('target_id');
            $table->string('source_checksum', 64)->nullable();
            // For accounts: 1 = brought into v2 from the first site (password
            // published), 2 = created on v2 itself.
            $table->unsignedTinyInteger('generation')->nullable();
            $table->timestamps();

            $table->unique(['source_table', 'source_id']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_id_map');
    }
};
