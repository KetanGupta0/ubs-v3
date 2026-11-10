<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\User;
use App\Services\Admin\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A notice for one student.
 *
 * It lives with every other announcement, addressed to them alone, so it
 * shows on their dashboard and their announcements page like any other and
 * nobody else can see it. Sent from the student's admin profile.
 */
class StudentNoticeController extends Controller
{
    public function store(Request $request, User $user, Auditor $auditor): RedirectResponse
    {
        abort_unless($user->role === Role::Student, 404);

        $validated = $this->validatedInput($request, [
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'is_pinned' => ['boolean'],
        ]);

        $notice = Announcement::query()->create([
            'user_id' => $user->id,
            'course_id' => null,
            'batch_id' => null,
            'author_id' => $request->user()->id,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'audience' => 'student',
            'is_pinned' => $validated['is_pinned'],
            'published_at' => now(),
        ]);

        $auditor->action('notice.sent', $notice, ['to' => $user->name, 'title' => $notice->title], $user->name);

        return back()->with('success', "Sent to {$user->name}. It is on their dashboard now.");
    }

    public function destroy(User $user, Announcement $notice, Auditor $auditor): RedirectResponse
    {
        abort_unless($notice->audience === 'student' && $notice->user_id === $user->id, 404);

        $auditor->deleted($notice, $notice->title);
        $notice->delete();

        return back()->with('success', 'Notice withdrawn.');
    }
}
