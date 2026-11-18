<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly service reports for maintenance clients (CL17): tickets against
 * the SLA worked out from the tickets themselves, and uptime, hours and the
 * work done as our side records them, published as a PDF into the client's
 * documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_contracts', function (Blueprint $table) {
            $table->decimal('included_hours', 6, 2)->nullable()->after('included_tickets');
        });

        Schema::create('service_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('maintenance_contracts')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            // The first day of the month reported on.
            $table->date('month');
            $table->json('figures');
            $table->decimal('uptime_percent', 6, 3)->nullable();
            $table->decimal('hours_used', 6, 2)->nullable();
            $table->text('work_done')->nullable();
            // draft or published.
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['contract_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_reports');

        Schema::table('maintenance_contracts', function (Blueprint $table) {
            $table->dropColumn('included_hours');
        });
    }
};
