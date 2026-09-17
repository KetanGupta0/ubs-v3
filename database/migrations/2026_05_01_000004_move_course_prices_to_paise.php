<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Course prices become paise, like every other amount.
 *
 * They were whole rupees in Phase 2, when nothing charged for them. Phase 5
 * takes money for a course through the same ledger the client side uses, and
 * that ledger is in paise. Two units meeting at the point where money changes
 * hands is a bug waiting for its first customer, so the odd one out moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->unsignedBigInteger('price')->default(0)->change();
            $table->unsignedBigInteger('sale_price')->nullable()->change();

            // Who teaches it, for the trainer's own dashboard.
            $table->foreignId('lead_trainer_id')->nullable()->after('is_published')->constrained('users')->nullOnDelete();

            // A pass mark for the course as a whole, used on the report card.
            $table->unsignedTinyInteger('pass_percent')->default(50)->after('lead_trainer_id');
            $table->unsignedTinyInteger('minimum_attendance')->default(75)->after('pass_percent');
            $table->boolean('issues_certificate')->default(true)->after('minimum_attendance');
        });

        DB::table('courses')->update([
            'price' => DB::raw('price * 100'),
            'sale_price' => DB::raw('sale_price * 100'),
        ]);

        Schema::table('batches', function (Blueprint $table) {
            // Where the cohort meets, when every session shares one room.
            $table->string('meet_link')->nullable()->after('timezone');
            $table->foreignId('college_id')->nullable()->after('trainer_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('courses')->update([
            'price' => DB::raw('price / 100'),
            'sale_price' => DB::raw('sale_price / 100'),
        ]);

        Schema::table('batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('college_id');
            $table->dropColumn('meet_link');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_trainer_id');
            $table->dropColumn(['pass_percent', 'minimum_attendance', 'issues_certificate']);
            $table->unsignedInteger('price')->default(0)->change();
            $table->unsignedInteger('sale_price')->nullable()->change();
        });
    }
};
