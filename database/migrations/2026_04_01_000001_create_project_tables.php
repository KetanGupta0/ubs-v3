<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery: what we are building for a client, and what we quoted for it.
 *
 * Every money column in this application stores paise as an integer. Rupees in
 * a float is a rounding error waiting to appear on an invoice, and a decimal
 * column still arrives in PHP as a float unless every read remembers not to.
 * Integers cannot drift. `App\Support\Money` converts at the edges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->foreignId('solution_id')->nullable()->constrained()->nullOnDelete();
            $table->text('summary')->nullable();
            $table->longText('scope')->nullable();

            $table->string('status', 20)->default('planning');
            $table->string('phase', 40)->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);

            $table->date('start_date')->nullable();
            $table->date('target_date')->nullable();
            $table->date('delivered_on')->nullable();

            $table->unsignedBigInteger('budget')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('team')->nullable();
            $table->string('repository_url')->nullable();
            $table->string('staging_url')->nullable();
            $table->string('production_url')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
        });

        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Set when a payment is tied to reaching this milestone.
            $table->unsignedBigInteger('payment_amount')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'sort_order']);
        });

        Schema::create('project_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('body');
            $table->json('attachments')->nullable();

            // An internal note and a client update share a table, because the
            // timeline reads better as one thread with some entries withheld
            // than as two lists that have to be mentally interleaved.
            $table->boolean('visible_to_client')->default(true);

            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });

        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30)->unique();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('title');
            $table->longText('summary')->nullable();
            $table->longText('body')->nullable();
            $table->json('assumptions')->nullable();
            $table->json('deliverables')->nullable();
            $table->string('timeline')->nullable();

            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->text('response_note')->nullable();
            $table->string('responded_ip', 45)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->string('number', 30)->unique();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->string('currency', 3)->default('INR');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamps();
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->string('hsn_sac', 12)->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 20)->nullable();
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(18);
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('proposals');
        Schema::dropIfExists('project_updates');
        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('projects');
    }
};
