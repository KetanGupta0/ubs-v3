<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Old addresses that still have to work: v2's links, bookmarks and search
 * results, sent on permanently to where the page lives now.
 *
 * Only addresses nothing in v3 answers reach this table, through the fallback
 * route, so a redirect can never hide a real page. The importer adds one per
 * v2 course mapped onto a v3 course; the fixed ones are written here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path', 300)->unique();
            $table->string('to_path', 300);
            $table->string('reason', 120)->nullable();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamps();
        });

        $now = now();

        foreach ([
            '/privacy-policy' => '/legal/privacy',
            '/terms' => '/legal/terms',
            '/terms-and-conditions' => '/legal/terms',
            '/refund-policy' => '/legal/refunds',
        ] as $from => $to) {
            DB::table('redirects')->insert(['from_path' => $from, 'to_path' => $to, 'reason' => 'Old site address', 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};
