<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the public site renders.
 *
 * The solutions catalogue is the centrepiece: with no live client work to show
 * yet, it is what a visitor judges us on. So it carries enough structure to
 * render a real product page, not a brochure stub.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solution_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon', 60)->nullable();
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('solutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solution_category_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->string('slug')->unique();
            $table->string('tagline');
            $table->text('summary');
            $table->longText('description')->nullable();

            // Facets the catalogue filters on.
            $table->json('industries')->nullable();
            $table->json('platforms')->nullable();
            $table->json('tech_stack')->nullable();

            // Product page content.
            $table->json('features')->nullable();
            $table->json('modules')->nullable();
            $table->json('outcomes')->nullable();
            $table->json('integrations')->nullable();

            // Indicative, not a quotation. Displayed as a band for that reason.
            $table->unsignedInteger('price_band_min')->nullable();
            $table->unsignedInteger('price_band_max')->nullable();
            $table->unsignedSmallInteger('timeline_weeks_min')->nullable();
            $table->unsignedSmallInteger('timeline_weeks_max')->nullable();

            // Drives the per product accent used across its card and page.
            $table->string('accent', 20)->default('brand');
            $table->boolean('needs_api_keys')->default(false);

            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('seo')->nullable();

            $table->timestamps();

            $table->index(['is_published', 'is_featured']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('type', 30);
            $table->string('tagline');
            $table->text('summary');
            $table->longText('description')->nullable();
            $table->json('deliverables')->nullable();
            $table->json('engagement_models')->nullable();
            $table->json('process')->nullable();
            $table->json('faqs')->nullable();
            $table->string('icon', 60)->nullable();
            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('seo')->nullable();
            $table->timestamps();
        });

        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->string('author_name');
            $table->string('author_role')->nullable();
            $table->string('company')->nullable();
            $table->text('quote');
            $table->string('avatar_path')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->text('answer');
            $table->string('group', 60)->default('general')->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('testimonials');
        Schema::dropIfExists('services');
        Schema::dropIfExists('solutions');
        Schema::dropIfExists('solution_categories');
    }
};
