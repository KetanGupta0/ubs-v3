<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Agreements as their own record (CL1), and a frozen PDF for every client
 * document (CL3).
 *
 * A document's PDF is made when it is sent and kept, with its SHA-256, the way
 * an invoice is: what the client was sent is what anybody downloads later,
 * and the hash is what a signature (CL2) is a signature of. The parties are
 * copied onto the row at the same moment, so a later change to the company's
 * details or the client's address does not rewrite a document already sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->json('parties')->nullable()->after('responded_ip');
            $table->string('pdf_path')->nullable()->after('parties');
            $table->string('pdf_sha256', 64)->nullable()->after('pdf_path');
        });

        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->string('number', 30)->unique();
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('previous_id')->nullable()->constrained('agreements')->nullOnDelete();

            // msa, sow, nda, licence or other.
            $table->string('kind', 12);
            $table->string('title');
            $table->longText('body');

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('maintenance_contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->date('effective_on')->nullable();
            $table->date('expires_on')->nullable();

            // draft, sent, signed, countersigned, superseded or withdrawn.
            $table->string('status', 16)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->json('parties')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('pdf_sha256', 64)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreements');

        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn(['parties', 'pdf_path', 'pdf_sha256']);
        });
    }
};
