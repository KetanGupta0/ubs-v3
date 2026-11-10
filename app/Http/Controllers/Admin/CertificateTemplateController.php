<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Course;
use App\Services\Admin\Auditor;
use App\Services\Lms\Credentials;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Certificate templates: how a course's certificates look.
 *
 * A background, an accent, the wording and the signatory, set into one fixed
 * layout. Changing one never touches a certificate already issued: each
 * records the template it was issued with, and its PDF was cached then.
 */
class CertificateTemplateController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(CertificateTemplate::query()->with('course:id,title'))
            ->searchable(['name', 'course.title'])
            ->defaultSort('-updated_at')
            ->exportName('certificate-templates')
            ->columns([
                Column::make('name', 'Template')->sortable(),
                Column::make('course', 'Used for'),
                Column::make('signatory', 'Signed by'),
                Column::make('is_active', 'Active')->sortable(),
                Column::make('issued', 'Certificates issued')->numeric(),
                Column::make('updated_at', 'Changed')->sortable(),
            ])
            ->filters([
                Filter::boolean('is_active', 'Active only'),
            ])
            ->transform(fn (CertificateTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'course' => $template->course?->title ?? 'Every course without its own',
                'isDefault' => $template->course_id === null,
                'signatory' => $template->signatory_name ?? '—',
                'is_active' => $template->is_active,
                'issued' => Certificate::query()->where('certificate_template_id', $template->id)->count(),
                'hasBackground' => $template->background_path !== null,
                'updated_at' => $template->updated_at?->diffForHumans(),
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/certificates/Templates', [
            'table' => $table->toArray($request),
            'courses' => Course::query()->orderBy('title')->get(['id', 'title'])
                ->map(fn (Course $course) => ['value' => $course->id, 'label' => $course->title]),
        ]);
    }

    public function edit(CertificateTemplate $template): Response
    {
        return Inertia::render('admin/certificates/TemplateForm', [
            'template' => $this->formArray($template),
            'courses' => Course::query()->orderBy('title')->get(['id', 'title'])
                ->map(fn (Course $course) => ['value' => $course->id, 'label' => $course->title]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/certificates/TemplateForm', [
            'template' => null,
            'courses' => Course::query()->orderBy('title')->get(['id', 'title'])
                ->map(fn (Course $course) => ['value' => $course->id, 'label' => $course->title]),
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $template = new CertificateTemplate(['created_by' => $request->user()->id]);
        $this->fill($request, $template);
        $template->save();
        $auditor->created($template, $template->name);

        return redirect()
            ->route('admin.certificate-templates.edit', $template)
            ->with('success', 'Template saved. Open the preview to check it before a certificate is issued with it.');
    }

    public function update(Request $request, CertificateTemplate $template, Auditor $auditor): RedirectResponse
    {
        $this->fill($request, $template);
        $auditor->updated($template, label: $template->name);
        $template->save();

        return back()->with('success', 'Template saved. Certificates already issued keep the look they were issued with.');
    }

    public function destroy(CertificateTemplate $template, Auditor $auditor): RedirectResponse
    {
        $auditor->deleted($template, $template->name);
        $template->delete();

        return redirect()
            ->route('admin.certificate-templates.index')
            ->with('success', 'Template removed. Certificates issued with it are unchanged.');
    }

    /** The template drawn with made up details. Nothing is saved or issued. */
    public function preview(CertificateTemplate $template, Credentials $credentials): HttpResponse
    {
        return response($credentials->previewCertificate($template), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="certificate-preview.pdf"',
        ]);
    }

    protected function fill(Request $request, CertificateTemplate $template): void
    {
        $validated = $this->validatedInput($request, [
            'name' => ['required', 'string', 'max:120'],
            'course_id' => ['nullable', 'integer', Rule::exists('courses', 'id')],
            'heading' => ['required', 'string', 'max:80'],
            'body_line' => ['required', 'string', 'max:120'],
            'signatory_name' => ['nullable', 'string', 'max:120'],
            'signatory_title' => ['nullable', 'string', 'max:120'],
            'accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'show_grade' => ['boolean'],
            'show_qr' => ['boolean'],
            'is_active' => ['boolean'],
            'background' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
            'signature' => ['nullable', 'file', 'mimes:png', 'max:1024'],
            'remove_background' => ['boolean'],
            'remove_signature' => ['boolean'],
        ], [
            'accent.regex' => 'Use a colour like #4f46e5.',
            'background.mimes' => 'Use a JPG or PNG, A4 landscape.',
            'signature.mimes' => 'Use a PNG, ideally with a transparent background.',
        ]);

        $template->fill([
            'name' => $validated['name'],
            'course_id' => $validated['course_id'],
            'heading' => $validated['heading'],
            'body_line' => $validated['body_line'],
            'signatory_name' => $validated['signatory_name'],
            'signatory_title' => $validated['signatory_title'],
            'accent' => strtolower($validated['accent']),
            'show_grade' => $this->boolInput($request, 'show_grade', true),
            'show_qr' => $this->boolInput($request, 'show_qr', true),
            'is_active' => $this->boolInput($request, 'is_active', true),
        ]);

        if ($validated['remove_background']) {
            $template->background_path = null;
        }

        if ($validated['remove_signature']) {
            $template->signature_path = null;
        }

        if ($file = $request->file('background')) {
            $template->background_path = $this->storeImage($file, 'jpg', 3508);
        }

        if ($file = $request->file('signature')) {
            $template->signature_path = $this->storeImage($file, 'png', 900);
        }
    }

    /**
     * Re-encode an uploaded image through GD and keep it on the private disk.
     *
     * Re-encoding drops whatever metadata came with the file and caps its
     * size, so a 40 MB photo does not end up inside every certificate. A
     * background is capped at A4 landscape at 300 dpi.
     */
    protected function storeImage(UploadedFile $file, string $as, int $maxWidth): string
    {
        $image = match ($file->getMimeType()) {
            'image/png' => @imagecreatefrompng($file->getRealPath()),
            'image/jpeg' => @imagecreatefromjpeg($file->getRealPath()),
            default => false,
        };

        abort_unless($image, 422, 'That image could not be read.');

        if (imagesx($image) > $maxWidth) {
            $image = imagescale($image, $maxWidth);
        }

        ob_start();
        if ($as === 'png') {
            imagesavealpha($image, true);
            imagepng($image, null, 7);
        } else {
            imagejpeg($image, null, 85);
        }
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $path = 'certificate-templates/'.Str::uuid().'.'.$as;
        Storage::disk('private')->put($path, $bytes);

        return $path;
    }

    /** @return array<string, mixed> */
    protected function formArray(CertificateTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'course_id' => $template->course_id,
            'heading' => $template->heading,
            'body_line' => $template->body_line,
            'signatory_name' => $template->signatory_name,
            'signatory_title' => $template->signatory_title,
            'accent' => $template->accent,
            'show_grade' => $template->show_grade,
            'show_qr' => $template->show_qr,
            'is_active' => $template->is_active,
            'hasBackground' => $template->background_path !== null,
            'hasSignature' => $template->signature_path !== null,
            'issued' => Certificate::query()->where('certificate_template_id', $template->id)->count(),
        ];
    }
}
