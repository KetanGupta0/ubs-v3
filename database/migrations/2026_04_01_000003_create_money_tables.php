<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One ledger for both business lines.
 *
 * A client paying for a milestone and a student paying for a course are the
 * same event with a different payable attached, so they share these tables.
 * Reporting across the company then needs one query rather than a union of two
 * systems that will disagree by the second quarter.
 *
 * Amounts are paise, as integers, everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('payable');
            $table->string('reference', 30)->unique();

            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(18);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->string('currency', 3)->default('INR');

            $table->date('due_on')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('reminders_sent')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_request_id')->nullable()->constrained()->nullOnDelete();

            $table->string('number', 40)->unique();
            $table->string('financial_year', 9);
            $table->unsignedInteger('sequence');

            // Frozen at issue. An invoice that re-renders from live settings is
            // not a record of anything; it is a view of today's data wearing
            // last year's number.
            $table->json('billed_to');
            $table->json('billed_from');

            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->json('tax_breakup')->nullable();
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->string('currency', 3)->default('INR');
            $table->string('place_of_supply', 60)->nullable();

            $table->string('status', 20)->default('issued');
            $table->timestamp('issued_at');
            $table->date('due_on')->nullable();
            $table->text('terms')->nullable();
            $table->string('pdf_path')->nullable();

            $table->timestamps();

            $table->unique(['financial_year', 'sequence']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->string('hsn_sac', 12)->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(18);
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_request_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 30)->unique();
            $table->string('gateway', 30)->default('razorpay');
            $table->string('gateway_order_id')->nullable()->index();
            $table->string('gateway_payment_id')->nullable()->index();
            $table->string('gateway_signature')->nullable();

            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('INR');
            $table->string('status', 20)->default('created');
            $table->string('method', 30)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('gateway_response')->nullable();
            $table->string('receipt_path')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 30)->unique();
            $table->unsignedBigInteger('amount');
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->string('gateway_refund_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payment_requests');
    }
};
