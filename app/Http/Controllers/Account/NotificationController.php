<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * What the platform has told somebody, in the platform (§8: "in the app as
 * well as by mail").
 *
 * Every notification written to the database channel lands here: the bell in
 * the header shows the latest and how many are unread, and this page lists
 * them all. Opening one marks it read and goes where it points. Until
 * November 2026 they were written and never shown: the bell was a picture.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response|SymfonyResponse
    {
        $table = Table::for($request->user()->notifications()->getQuery())
            ->defaultSort('-created_at')
            ->exportName('notifications')
            ->columns([
                Column::make('title', 'What'),
                Column::make('created_at', 'When')->sortable(),
                Column::make('read', 'Read'),
            ])
            ->filters([
                Filter::select('read', ['unread' => 'Not read yet', 'read' => 'Read'], 'Read')->placeholder('All')
                    ->using(fn ($query, $value) => $value === 'unread' ? $query->whereNull('read_at') : $query->whereNotNull('read_at')),
            ])
            ->transform(fn (DatabaseNotification $notification) => self::forScreen($notification) + [
                'read' => $notification->read_at ? 'Read' : 'New',
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('Notifications', ['table' => $table->toArray($request)]);
    }

    /** The latest few, for the bell. */
    public function recent(Request $request): JsonResponse
    {
        return response()->json([
            'items' => $request->user()->notifications()->latest()->limit(8)->get()->map(fn (DatabaseNotification $notification) => self::forScreen($notification))->values(),
            'unread' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /** Open one: it is read now, and off to where it points. */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        $url = $record->data['url'] ?? null;

        return is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//')
            ? redirect($url)
            : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    /** @return array<string, mixed> */
    public static function forScreen(DatabaseNotification $notification): array
    {
        $data = $notification->data ?? [];

        return [
            'id' => $notification->id,
            'title' => $data['title'] ?? $data['subject'] ?? str($data['kind'] ?? 'Something new')->replace(['.', '_'], ' ')->ucfirst()->toString(),
            'url' => $data['url'] ?? null,
            'unread' => $notification->read_at === null,
            'created_at' => $notification->created_at->diffForHumans(),
        ];
    }
}
