<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\LessonDoubt;
use App\Models\LessonDoubtReply;
use App\Services\Lms\Doubts;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Students' questions on lessons, for trainers and the team to answer.
 *
 * The queue opens on what is waiting, oldest activity first, because the
 * question somebody asked yesterday matters more than the one asked a minute
 * ago.
 */
class DoubtController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(LessonDoubt::query()->with('lesson:id,title', 'course:id,title', 'batch:id,name', 'asker:id,name')->withCount('replies'))
            ->searchable(['body'])
            ->defaultSort('last_activity_at')
            ->exportName('questions')
            ->columns([
                Column::make('question', 'Question'),
                Column::make('lesson', 'Lesson'),
                Column::make('batch', 'Batch'),
                Column::make('asker', 'Asked by'),
                Column::make('status', 'Status')->sortable(),
                Column::make('replies_count', 'Replies')->numeric()->sortable(),
                Column::make('last_activity_at', 'Last activity')->sortable(),
            ])
            ->filters([
                Filter::select('status', LessonDoubt::STATUSES, 'Status')->placeholder('Any'),
                Filter::select('course_id', Course::query()->whereIn('id', LessonDoubt::query()->select('course_id'))->orderBy('title')->pluck('title', 'id')->all(), 'Course')->placeholder('Any course'),
            ])
            ->transform(fn (LessonDoubt $doubt) => [
                'id' => $doubt->id,
                'question' => str($doubt->body)->limit(120)->toString(),
                'lesson' => $doubt->lesson?->title,
                'course' => $doubt->course?->title,
                'batch' => $doubt->batch?->name ?? 'No batch',
                'asker' => $doubt->asker?->name,
                'status' => $doubt->status,
                'statusLabel' => LessonDoubt::STATUSES[$doubt->status] ?? $doubt->status,
                'replies_count' => $doubt->replies_count,
                'last_activity_at' => $doubt->last_activity_at->diffForHumans(),
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/doubts/Index', [
            'table' => $table->toArray($request),
            'waiting' => LessonDoubt::query()->where('status', 'open')->count(),
        ]);
    }

    public function show(LessonDoubt $doubt): Response
    {
        $doubt->load('lesson:id,title,course_id', 'course:id,title', 'batch:id,name', 'asker:id,name,email', 'replies.author:id,name,role');

        return Inertia::render('admin/doubts/Show', [
            'doubt' => [
                'id' => $doubt->id,
                'body' => $doubt->body,
                'status' => $doubt->status,
                'statusLabel' => LessonDoubt::STATUSES[$doubt->status] ?? $doubt->status,
                'lesson' => $doubt->lesson?->title,
                'course' => $doubt->course?->title,
                'batch' => $doubt->batch?->name,
                'asker' => $doubt->asker?->name,
                'askerId' => $doubt->user_id,
                'at' => $doubt->created_at->format('j M Y, g:i a'),
                'replies' => $doubt->replies->map(fn (LessonDoubtReply $reply) => [
                    'id' => $reply->id,
                    'author' => $reply->author?->name,
                    'fromStaff' => $reply->from_staff,
                    'body' => $reply->body,
                    'at' => $reply->created_at->format('j M Y, g:i a'),
                ]),
            ],
        ]);
    }

    public function reply(Request $request, LessonDoubt $doubt, Doubts $doubts): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:'.LessonDoubt::MAX_LENGTH],
        ]);

        abort_if($doubt->status === 'hidden', 422, 'Put the question back before answering it.');

        $doubts->answer($doubt, $request->user(), $validated['body']);

        return back()->with('success', 'Answered. The student has been told, and the batch can read it.');
    }

    public function visibility(Request $request, LessonDoubt $doubt, Doubts $doubts): RedirectResponse
    {
        $hide = $request->boolean('hidden');
        $doubts->setHidden($doubt, $request->user(), $hide);

        return back()->with('success', $hide ? 'Taken down. The batch no longer sees it.' : 'Back up for the batch.');
    }
}
