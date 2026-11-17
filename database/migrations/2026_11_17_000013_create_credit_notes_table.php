<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit notes (CL14): a reduction of an invoice already issued, in its own
 * consecutive series per financial year, with the parties and tax copied
 * from the invoice it credits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('financial_year', 9);
            $table->unsignedInteger('sequence');
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('billed_to');
            $table->json('billed_from');
            $table->string('place_of_supply', 60)->nullable();
            // Paise: the taxable value credited, its tax by component, and the sum.
            $table->unsignedBigInteger('subtotal');
            $table->json('tax_breakup')->nullable();
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total');
            $table->text('reason');
            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pdf_path')->nullable();
            $table->timestamps();

            $table->unique(['financial_year', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
