<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indian B2B billing, part one (CL14): the client's purchase order number on
 * what we bill, and TDS. A client that deducts TDS pays the net amount and
 * sends its Form 16A; the deduction settles the rest of the invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('po_number', 60)->nullable()->after('budget');
        });

        Schema::table('payment_requests', function (Blueprint $table) {
            $table->string('po_number', 60)->nullable()->after('title');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('po_number', 60)->nullable()->after('number');
            // Settled without money changing hands: TDS the client deducted,
            // and credit notes against it.
            $table->unsignedBigInteger('tds_amount')->default(0)->after('amount_paid');
            $table->unsignedBigInteger('credited_amount')->default(0)->after('tds_amount');
        });

        Schema::create('tds_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->string('section', 24);
            $table->decimal('rate', 5, 2);
            // Paise, on the invoice's taxable value (GST excluded).
            $table->unsignedBigInteger('base');
            $table->unsignedBigInteger('amount');
            // The quarter of the financial year it was deducted in, such as 2026-27 Q3.
            $table->string('quarter', 12);
            $table->date('certificate_due_on');
            $table->foreignId('declared_by')->nullable()->constrained('users')->nullOnDelete();

            // declared, received (Form 16A uploaded), verified or mismatch.
            $table->string('status', 16)->default('declared');
            $table->string('certificate_path')->nullable();
            $table->timestamp('certificate_uploaded_at')->nullable();
            $table->foreignId('certificate_uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('check_note')->nullable();
            $table->timestamps();

            $table->unique('invoice_id');
            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tds_deductions');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['po_number', 'tds_amount', 'credited_amount']);
        });

        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropColumn('po_number');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('po_number');
        });
    }
};
