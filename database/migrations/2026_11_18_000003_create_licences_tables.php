<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Licences for source code bought from the shop (CL4, X18).
 *
 * The key is shown once and only its hash is kept, like an API key; a lost
 * key is rotated, never recovered. The software checks a key and the site it
 * runs on against the verification API, which activates sites up to what the
 * tier allows. Every download is recorded against the licence it was for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('solution_id')->nullable()->constrained()->nullOnDelete();

            // As sold, so renaming the product does not rename the licence.
            $table->string('product');
            $table->string('tier', 80);

            // Made the first time somebody asks to see it, and shown once.
            $table->string('key_prefix', 12)->nullable();
            $table->char('key_hash', 64)->nullable()->unique();
            $table->timestamp('key_issued_at')->nullable();

            $table->unsignedSmallInteger('sites')->nullable(); // null: no limit
            $table->date('updates_until');
            $table->date('support_until')->nullable();

            $table->string('status', 10)->default('active'); // active, revoked
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 300)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });

        Schema::create('licence_activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('licence_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 190);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->string('last_version', 30)->nullable();
            $table->string('last_ip', 45)->nullable();
            // Released to make room for another site; checking in again takes a place again.
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['licence_id', 'domain']);
        });

        Schema::create('licence_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('licence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at');

            $table->index(['licence_id', 'product_version_id']);
        });

        // A hosted plan bought from the shop starts a subscription; this says which sale.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('order_item_id')->nullable()->after('subscribable_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_item_id');
        });

        Schema::dropIfExists('licence_downloads');
        Schema::dropIfExists('licence_activations');
        Schema::dropIfExists('licences');
    }
};
