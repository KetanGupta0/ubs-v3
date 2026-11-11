<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The public site's menus: the header and the two footer columns.
 *
 * Filled here with the links the layout used to carry in code, so a deploy
 * never shows a site with no way around it. Plain inserts, not models, so a
 * later change to a model cannot change what this migration does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_menus', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('title', 60);
            $table->timestamps();
        });

        Schema::create('site_menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_menu_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40);
            $table->string('href', 300);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['site_menu_id', 'sort_order']);
        });

        $menus = [
            'header' => ['Main menu', [
                ['Solutions', '/solutions'],
                ['Services', '/services'],
                ['Internships', '/internships'],
                ['Training', '/training'],
                ['About', '/about'],
            ]],
            'footer_company' => ['Company', [
                ['Solutions', '/solutions'],
                ['Services', '/services'],
                ['Internships', '/internships'],
                ['Training', '/training'],
                ['For colleges', '/for-colleges'],
                ['Process', '/process'],
                ['Contact', '/contact'],
            ]],
            'footer_account' => ['Account', [
                ['Sign in', '/login'],
                ['Student sign up', '/register'],
                ['FAQ', '/faq'],
            ]],
        ];

        $now = now();

        foreach ($menus as $key => [$title, $items]) {
            $id = DB::table('site_menus')->insertGetId(['key' => $key, 'title' => $title, 'created_at' => $now, 'updated_at' => $now]);

            foreach ($items as $index => [$label, $href]) {
                DB::table('site_menu_items')->insert([
                    'site_menu_id' => $id,
                    'label' => $label,
                    'href' => $href,
                    'sort_order' => $index + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_menu_items');
        Schema::dropIfExists('site_menus');
    }
};
