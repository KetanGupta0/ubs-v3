<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Edits to the wording of the fixed pages.
 *
 * Only edits: a field nobody changed has no row and shows its starting
 * wording from database/data/page_copy.php. Each row holds what is live and,
 * separately, a draft that only staff previewing can see until it is
 * published. A null live or draft value means "the starting wording".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_copy', function (Blueprint $table) {
            $table->id();
            $table->string('page', 40);
            $table->string('key', 80);
            $table->text('live')->nullable();
            $table->boolean('has_draft')->default(false);
            $table->text('draft')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['page', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_copy');
    }
};
