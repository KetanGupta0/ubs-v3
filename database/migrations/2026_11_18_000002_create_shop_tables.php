<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cart, coupons and orders (§4).
 *
 * A cart holds only what was chosen; it is priced afresh from the catalogue
 * every time it is shown, so a price in it can never be stale. An order copies
 * what was sold and at what price, the way an invoice freezes who was billed:
 * repricing a tier tomorrow does not rewrite what somebody bought today.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A signed in person's cart. A guest's lives in the session until they sign in.
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('lines')->nullable();
            $table->json('coupons')->nullable();
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique(); // upper case
            $table->string('description', 200)->nullable();

            // A percentage of what it applies to, or a fixed amount in paise.
            $table->unsignedTinyInteger('percent')->nullable();
            $table->unsignedBigInteger('amount')->nullable();
            $table->unsignedBigInteger('max_discount')->nullable();
            $table->unsignedBigInteger('min_order')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_account')->nullable();

            // Empty means everything that is sold.
            $table->json('solution_ids')->nullable();
            $table->json('category_ids')->nullable();
            $table->boolean('first_order_only')->default(false);
            $table->boolean('stackable')->default(false);

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            // The organisation that owns what was bought (CL8), and who bought it.
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');

            // Paise. Taxable is the subtotal less the discount; GST is on that.
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('taxable');
            $table->decimal('tax_rate', 5, 2);
            $table->json('tax_breakup');
            $table->unsignedBigInteger('tax');
            $table->unsignedBigInteger('total');

            // Who is billed, as the buyer confirmed it at checkout. The invoice copies it.
            $table->json('billing');
            $table->string('place_of_supply', 60)->nullable();

            $table->foreignId('payment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // An add-on belongs to the product line it was bought with.
            $table->foreignId('parent_id')->nullable()->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('solution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10); // licence, plan, addon
            $table->foreignId('product_tier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_addon_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description');
            $table->string('sac_code', 12)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('amount');
            // This line's share of the order's discount, so the taxable value of
            // each line can be named.
            $table->unsignedBigInteger('discount')->default(0);
            // The terms it was sold on: sites, months of updates and support, interval.
            $table->json('terms')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        /*
         * Every use of a code, against the order it was used on. A use is held
         * while its order waits for payment, so two people cannot both take
         * the last use of a limited code, and released if the order lapses.
         */
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('status', 10)->default('held'); // held, redeemed, released
            $table->timestamps();

            $table->unique(['coupon_id', 'order_id']);
            $table->index(['coupon_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('carts');
    }
};
