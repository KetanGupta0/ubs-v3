<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Premium certificate layouts.
 *
 * A design now names the layout it is set into (CertificateTemplate::LAYOUTS):
 * Classic, the one there has always been, or one of four drawn whole with
 * gold ornaments and their own typefaces. Every existing design stays Classic.
 *
 * A design in each premium layout is added ready to use but switched off, so
 * nothing issued from now on changes until somebody previews one and turns it
 * on under Learning, Certificate designs. Each is a default (no course), with
 * no signatory: the owner adds the name, title and signature.
 */
return new class extends Migration
{
    /** @var array<int, array<string, string>> */
    protected array $designs = [
        ['name' => 'Regal', 'layout' => 'regal', 'heading' => 'Certificate of Completion', 'body_line' => 'has successfully completed', 'accent' => '#1e2a4a'],
        ['name' => 'Midnight', 'layout' => 'midnight', 'heading' => 'Certificate of Achievement', 'body_line' => 'has successfully completed', 'accent' => '#0e1630'],
        ['name' => 'Heritage', 'layout' => 'heritage', 'heading' => 'Certificate of Completion', 'body_line' => 'is awarded this certificate for completing', 'accent' => '#6d1f2c'],
        ['name' => 'Laurel', 'layout' => 'laurel', 'heading' => 'Certificate of Achievement', 'body_line' => 'has successfully completed', 'accent' => '#173a5e'],
    ];

    public function up(): void
    {
        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->string('layout', 20)->default('classic')->after('name');
        });

        $now = now();

        foreach ($this->designs as $design) {
            $exists = DB::table('certificate_templates')
                ->where('layout', $design['layout'])
                ->where('name', $design['name'])
                ->exists();

            if (! $exists) {
                DB::table('certificate_templates')->insert([
                    ...$design,
                    'course_id' => null,
                    'show_grade' => true,
                    'show_qr' => true,
                    'is_active' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // A design somebody's certificate was issued with stays, as Classic.
        foreach ($this->designs as $design) {
            DB::table('certificate_templates')
                ->where('layout', $design['layout'])
                ->where('name', $design['name'])
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('certificates')
                    ->whereColumn('certificates.certificate_template_id', 'certificate_templates.id'))
                ->delete();
        }

        Schema::table('certificate_templates', fn (Blueprint $table) => $table->dropColumn('layout'));
    }
};
