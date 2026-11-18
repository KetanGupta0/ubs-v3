<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a solution can be bought as (§4).
 *
 * A solution has always been something we build to order, priced as a band.
 * Some are also sold as they are, with the source code, at a fixed price by
 * licence tier; some are hosted and billed as a plan; some have a live demo
 * the owner hosts on its own subdomain (X9). A fixed figure is only ever shown
 * for something actually sold at that figure, and it is before tax (X17).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solutions', function (Blueprint $table) {
            // custom_build, source_code, subscription, demo. Null reads as
            // custom_build only, which is what every solution was before.
            $table->json('offerings')->nullable()->after('needs_api_keys');

            // A demo sign in is public by design: it is printed on the page.
            $table->string('demo_url')->nullable()->after('offerings');
            $table->string('demo_username', 120)->nullable()->after('demo_url');
            $table->string('demo_password', 120)->nullable()->after('demo_username');
            $table->string('demo_notes', 300)->nullable()->after('demo_password');

            // The SAC printed against a licence on the invoice.
            $table->string('sac_code', 12)->nullable()->after('demo_notes');
        });

        /*
         * A licence tier (source code at a fixed price) or a hosted plan.
         * Orders copy everything they need from here, so a tier can be
         * repriced or retired without rewriting anything already sold.
         */
        Schema::create('product_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solution_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // licence, plan
            $table->string('name', 80);
            $table->string('description', 300)->nullable();
            $table->unsignedBigInteger('price'); // paise, before tax

            // Licence terms. Sites null means no limit.
            $table->unsignedSmallInteger('sites')->nullable();
            $table->unsignedSmallInteger('update_months')->default(12);
            $table->unsignedSmallInteger('support_months')->default(0);

            // Plan terms.
            $table->string('interval', 10)->nullable(); // monthly, yearly

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['solution_id', 'kind', 'is_active']);
        });

        Schema::create('product_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solution_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 300)->nullable();
            // service: work we deliver; support and updates: months added to the licence.
            $table->string('kind', 10)->default('service');
            $table->unsignedBigInteger('price'); // paise per unit, before tax
            $table->string('unit', 10)->nullable(); // hour, month; null means once
            $table->unsignedSmallInteger('max_quantity')->default(1);
            $table->string('sac_code', 12)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        /*
         * Every release of the code, kept (X16): private storage, a size cap,
         * and a hash, so what a licence holder downloads can be checked
         * against what was uploaded.
         */
        Schema::create('product_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solution_id')->constrained()->cascadeOnDelete();
            $table->string('version', 30);
            $table->date('released_on');
            $table->text('changelog')->nullable();
            $table->string('path');
            $table->string('file_name');
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->boolean('is_available')->default(true);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['solution_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_versions');
        Schema::dropIfExists('product_addons');
        Schema::dropIfExists('product_tiers');

        Schema::table('solutions', function (Blueprint $table) {
            $table->dropColumn(['offerings', 'demo_url', 'demo_username', 'demo_password', 'demo_notes', 'sac_code']);
        });
    }
};
