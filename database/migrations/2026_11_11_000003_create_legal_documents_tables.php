<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legal documents and every version of them that was ever published.
 *
 * The privacy policy and terms are linked from the footer of every public
 * page, so their first versions are written here, with the tables, rather
 * than by a seeder somebody could forget to run on a deploy: the footer would
 * point at two 404s. Plain inserts, not models, so a later change to a model
 * cannot change what this migration does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('title', 120);
            $table->timestamps();
        });

        Schema::create('legal_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->string('intro', 500)->nullable();
            $table->mediumText('body');
            $table->string('change_note', 300)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['legal_document_id', 'version']);
            $table->index(['legal_document_id', 'status']);
        });

        $documents = [
            'privacy' => 'Privacy policy',
            'terms' => 'Terms of service',
            'refunds' => 'Refund and cancellation policy',
        ];
        $firstVersions = require database_path('data/legal.php');
        $now = now();

        foreach ($documents as $key => $title) {
            $id = DB::table('legal_documents')->insertGetId([
                'key' => $key, 'title' => $title, 'created_at' => $now, 'updated_at' => $now,
            ]);

            if (! isset($firstVersions[$key])) {
                continue;
            }

            DB::table('legal_document_versions')->insert([
                'legal_document_id' => $id,
                'version' => 1,
                'status' => 'published',
                'intro' => $firstVersions[$key]['intro'],
                'body' => collect($firstVersions[$key]['sections'])
                    ->map(fn (array $section) => "## {$section['heading']}\n\n{$section['body']}")
                    ->implode("\n\n"),
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_document_versions');
        Schema::dropIfExists('legal_documents');
    }
};
