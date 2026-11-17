<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The built-in OTP e-signature (CL2, decision X2).
 *
 * A signature records who signed, the name they typed, when, from where, the
 * one time code that proved it was them, and the SHA-256 of the exact PDF
 * they signed. Rows are never changed or deleted. A document also records
 * when the client first opened it, so "viewed" means opened, not sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_signatures', function (Blueprint $table) {
            $table->id();
            $table->morphs('signable');
            // client or us.
            $table->string('party', 8);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('signer_name', 160);
            $table->string('signer_designation', 120)->nullable();
            $table->string('signer_email');
            $table->string('organisation', 200)->nullable();
            $table->string('document_sha256', 64);
            $table->string('otp_channel', 10);
            $table->string('otp_destination', 120);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();
        });

        foreach (['agreements', 'proposals'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestamp('viewed_at')->nullable();
                $table->foreignId('viewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('signed_pdf_path')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['agreements', 'proposals'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('viewed_by');
                $table->dropColumn(['viewed_at', 'signed_pdf_path']);
            });
        }

        Schema::dropIfExists('document_signatures');
    }
};
