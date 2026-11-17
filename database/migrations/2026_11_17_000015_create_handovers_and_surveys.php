<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Handover (CL15) and satisfaction (CL18).
 *
 * A delivered project's handover pack: where the code is, how to deploy it,
 * what was licensed, training, the warranty, and where access was shared,
 * never the access itself (X6). The client acknowledges each revision, and
 * is asked a short survey; a quote becomes a public testimonial only with
 * their written consent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('repository')->nullable();
            $table->text('deployment_guide')->nullable();
            $table->text('environment_notes')->nullable();
            $table->text('credentials_note')->nullable();
            $table->json('licences')->nullable();
            $table->json('videos')->nullable();
            $table->date('warranty_ends_on')->nullable();
            // What the client was shown at the last publish: staff edit the
            // fields above freely, and the client sees this until the next.
            $table->json('published_content')->nullable();
            $table->unsignedSmallInteger('revision')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('acknowledged_revision')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acknowledged_ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('satisfaction_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('went_well')->nullable();
            $table->text('could_improve')->nullable();
            // A quote, and the written consent to publish it as credited.
            $table->text('quote')->nullable();
            $table->boolean('consent_to_publish')->default(false);
            $table->string('credit_as', 160)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->foreignId('testimonial_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->text('satisfaction_comment')->nullable()->after('satisfaction');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn('satisfaction_comment');
        });

        Schema::dropIfExists('satisfaction_surveys');
        Schema::dropIfExists('handovers');
    }
};
