<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time based one time password secrets.
 *
 * A row exists from the moment setup starts, but two factor authentication is
 * only enforced once `confirmed_at` is set. That stops someone locking
 * themselves out by abandoning setup half way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_factor_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Both are encrypted at rest by the model's casts.
            $table->text('secret');
            $table->text('recovery_codes')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_secrets');
    }
};
