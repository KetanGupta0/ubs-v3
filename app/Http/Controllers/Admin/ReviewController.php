<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseReview;
use App\Models\Testimonial;
use App\Services\Admin\Auditor;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Moderating what students say about their courses.
 *
 * A review is read before anybody else sees it. Approving one shows it to
 * other students and, only if its author agreed, on the public course page.
 * A good one, with consent, can be turned into a homepage testimonial, which
 * still waits in Site content until somebody publishes it.
 */
class ReviewController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = $this->table();

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/reviews/Index', [
            'table' => $table->toArray($request),
            'counts' => [
                'pending' => CourseReview::query()->where('status', 'pending')->count(),
                'public' => CourseReview::query()->public()->count(),
                'average' => round((float) CourseReview::query()->where('status', 'approved')->avg('rating'), 1),
            ],
            'canModerate' => $request->user()->hasPermission('catalogue.manage'),
        ]);
    }

    public function moderate(Request $request, CourseReview $review, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'status' => ['required', Rule::in(CourseReview::STATUSES)],
        ]);

        $review->forceFill([
            'status' => $validated['status'],
            'moderated_by' => $request->user()->id,
            'moderated_at' => now(),
        ]);
        $auditor->updated($review, label: $this->describe($review));
        $review->save();

        return back()->with('success', match ($validated['status']) {
            'approved' => $review->may_publish
                ? 'Approved. It shows to students and on the public course page.'
                : 'Approved. It shows to students; its author did not agree to the public site, so it stays off it.',
            'hidden' => 'Hidden. Only staff and its author can see it.',
            default => 'Back in the queue.',
        });
    }

    public function reply(Request $request, CourseReview $review, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'reply' => ['nullable', 'string', 'max:1000'],
        ]);

        $review->forceFill([
            'reply' => $validated['reply'],
            'replied_by' => $validated['reply'] ? $request->user()->id : null,
            'replied_at' => $validated['reply'] ? now() : null,
        ]);
        $auditor->updated($review, label: $this->describe($review));
        $review->save();

        return back()->with('success', $validated['reply'] ? 'Reply saved. It shows under the review.' : 'Reply removed.');
    }

    /**
     * Copy an approved review into a homepage testimonial, as a draft.
     *
     * Only with its author's consent: a testimonial is the most public thing
     * on the site, and quoting somebody who did not agree to it is exactly the
     * kind of proof this site does not carry.
     */
    public function promote(Request $request, CourseReview $review, Auditor $auditor): RedirectResponse
    {
        if ($review->status !== 'approved' || ! $review->may_publish) {
            return back()->with('error', 'Only an approved review whose author agreed to the public site can become a testimonial.');
        }

        $review->loadMissing(['student:id,name', 'course:id,title']);

        $testimonial = Testimonial::query()->create([
            'author_name' => $review->publicName(),
            'author_role' => 'Student, '.$review->course->title,
            'quote' => Str::limit($review->body, 600),
            'rating' => $review->rating,
            'is_published' => false,
        ]);

        $auditor->created($testimonial, $testimonial->author_name);

        return back()->with('success', 'Copied to Site content as a draft testimonial. Publish it there when you are happy with it.');
    }

    protected function table(): Table
    {
        return Table::for(CourseReview::query()->with(['student:id,name,email', 'course:id,title', 'replier:id,name']))
            ->searchable(['body', 'title', 'student.name', 'course.title'])
            ->defaultSort('-created_at')
            ->exportName('reviews')
            ->columns([
                Column::make('student', 'Student'),
                Column::make('course', 'Course'),
                Column::make('rating', 'Rating')->numeric()->sortable(),
                Column::make('review', 'Review'),
                Column::make('status', 'Status')->sortable(),
                Column::make('public', 'Public site'),
                Column::make('created_at', 'Written')->sortable(),
            ])
            ->filters([
                Filter::select('status', CourseReview::STATUSES, 'Status')->placeholder('Any'),
                Filter::select('course', Course::query()->orderBy('title')->pluck('title', 'id')->all(), 'Course')
                    ->column('course_id')
                    ->placeholder('Any course'),
                Filter::select('rating', ['5' => '5 stars', '4' => '4 stars', '3' => '3 stars', '2' => '2 stars', '1' => '1 star'], 'Rating')
                    ->placeholder('Any'),
                Filter::boolean('may_publish', 'Agreed to the public site'),
            ])
            ->transform(fn (CourseReview $review) => [
                'id' => $review->id,
                'student' => $review->student?->name,
                'email' => $review->student?->email,
                'course' => $review->course?->title,
                'rating' => $review->rating,
                'title' => $review->title,
                'body' => $review->body,
                'review' => Str::limit(trim(($review->title ? $review->title.' — ' : '').$review->body), 90),
                'status' => $review->status,
                'mayPublish' => $review->may_publish,
                'public' => $review->status === 'approved' && $review->may_publish ? 'Shown' : ($review->may_publish ? 'Agreed' : 'Not agreed'),
                'reply' => $review->reply,
                'repliedBy' => $review->replier?->name,
                'created_at' => $review->created_at?->format('j M Y'),
            ]);
    }

    protected function describe(CourseReview $review): string
    {
        $review->loadMissing(['student:id,name', 'course:id,title']);

        return ($review->student?->name ?? 'A student').' on '.($review->course?->title ?? 'a course');
    }
}
