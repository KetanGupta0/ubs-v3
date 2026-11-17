<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Client organisations with several people (EXPANSION_PLAN CL8).
 *
 * The organisation is the client account that already owns the projects,
 * proposals, invoices and the rest, so nothing that exists moves. Other people
 * join it here with a role. A person belongs to one organisation; an
 * invitation an existing account has not accepted yet has no `joined_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            // owner, signatory, finance or technical.
            $table->string('role', 12);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_members');
    }
};
