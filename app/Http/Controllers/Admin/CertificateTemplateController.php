<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Course;
use App\Services\Admin\Auditor;
use App\Services\Lms\Credentials;
use App\Support\ImageUpload;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Certificate templates: how a course's certificates look.
 *
 * A layout (CertificateTemplate::LAYOUTS), then the accent, the wording and
 * the signatory set into it, and for Classic a background. Changing one never
 * touches a certificate already issued: each records the template it was
 * issued with, and its PDF was cached then.
 */
class CertificateTemplateController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        // What CertificateTemplate::forCourse() picks: the newest active design for each course, and for the default.
        $inUse = CertificateTemplate::query()->where('is_active', true)
            ->selectRaw('max(id) as id')->groupBy('course_id')->pluck('id')->map(fn ($id) => (int) $id);

        $table = Table::for(CertificateTemplate::query()->with('course:id,title')->withCount('certificates'))
            ->searchable(['name', 'course.title'])
            ->defaultSort('-updated_at')
            ->exportName('certificate-templates')
            ->columns([
                Column::make('name', 'Template')->sortable(),
                Column::make('layout', 'Layout')->sortable(),
                Column::make('course', 'Used for'),
                Column::make('signatory', 'Signed by'),
                Column::make('is_active', 'Status')->sortable(),
                Column::make('issued', 'Certificates issued')->numeric(),
                Column::make('updated_at', 'Changed')->sortable(),
            ])
            ->filters([
                Filter::boolean('is_active', 'Active only'),
            ])
            ->transform(fn (CertificateTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'layout' => CertificateTemplate::LAYOUTS[$template->layout]['label'] ?? 'Classic',
                'course' => $template->course?->title ?? 'Every course without its own',
                'isDefault' => $template->course_id === null,
                'signatory' => $template->signatory_name ?? '—',
                'is_active' => $template->is_active,
                'inUse' => $inUse->contains($template->id),
                'issued' => $template->certificates_count,
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
            'layouts' => $this->layouts(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/certificates/TemplateForm', [
            'template' => null,
            'courses' => Course::query()->orderBy('title')->get(['id', 'title'])
                ->map(fn (Course $course) => ['value' => $course->id, 'label' => $course->title]),
            'layouts' => $this->layouts(),
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
            // Left out, a design keeps its layout (a new one is Classic).
            'layout' => ['nullable', 'string', Rule::in(array_keys(CertificateTemplate::LAYOUTS))],
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
            'layout.in' => 'Choose one of the layouts shown.',
            'background.mimes' => 'Use a JPG or PNG, A4 landscape.',
            'signature.mimes' => 'Use a PNG, ideally with a transparent background.',
        ]);

        $template->fill([
            'name' => $validated['name'],
            'layout' => $validated['layout'] ?? $template->layout ?? 'classic',
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

        // Only Classic draws a background; one kept from before stays for a return to Classic.
        if ($template->takesBackground() && ($file = $request->file('background'))) {
            // Capped at A4 landscape at 300 dpi.
            $template->background_path = ImageUpload::store($file, 'private', 'certificate-templates', 3508, 'jpg', 'background');
        }

        if ($file = $request->file('signature')) {
            $template->signature_path = ImageUpload::store($file, 'private', 'certificate-templates', 900, 'png', 'signature');
        }
    }

    /**
     * The layouts to choose from, each with a picture of the preview's sample
     * certificate in it (resources/certificates/render-thumbnails.php).
     *
     * @return array<int, array{value: string, label: string, description: string, accent: string, ink: array{fallback: string, against: array<int, string>}|null, picture: string|null}>
     */
    protected function layouts(): array
    {
        return collect(CertificateTemplate::LAYOUTS)
            ->map(fn (array $layout, string $key) => [
                'value' => $key,
                'label' => $layout['label'],
                'description' => $layout['description'],
                'accent' => $layout['accent'],
                'ink' => $layout['ink'],
                'picture' => is_file(public_path("images/certificate-layouts/{$key}.webp")) ? "/images/certificate-layouts/{$key}.webp" : null,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    protected function formArray(CertificateTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'layout' => $template->layout,
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
