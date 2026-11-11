<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What search engines and link previews show for the site's fixed pages
 * (home, about, contact and the rest), editable without a deploy.
 *
 * Empty fields fall back to the wording the page itself sets, so a row with
 * nothing in it changes nothing. Pages written in the panel and catalogue
 * items carry their own search fields and are not listed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('path', 191)->unique();
            $table->string('label', 120);
            $table->string('title', 160)->nullable();
            $table->string('description', 200)->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('noindex')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_overrides');
    }
};
