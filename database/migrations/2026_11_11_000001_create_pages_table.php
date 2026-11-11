<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Free standing pages on the public site, written in the admin panel.
 *
 * A page is a list of blocks (heading, text, image, call to action, FAQ),
 * not a box of HTML: a block can only ever render as itself, so nothing
 * typed into the panel can break the layout or put a script on the site.
 * Served at /pages/{slug}, which is also where v2 served its custom pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->string('slug', 180)->unique();
            $table->string('summary', 300)->nullable();
            $table->json('blocks')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->string('seo_title', 160)->nullable();
            $table->string('seo_description', 200)->nullable();
            $table->boolean('is_indexable')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
