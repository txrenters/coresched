<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\PlatformAnnouncement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Announcements a super admin sends to everyone on the platform.
 */
class AnnouncementController extends Controller
{
    /**
     * How long a banner may be asked to show, in days.
     *
     * @var list<int>
     */
    public const BANNER_DAYS = [1, 3, 7, 14];

    /**
     * List the announcements sent so far, newest first.
     */
    public function index(): Response
    {
        Gate::authorize('accessAdminConsole');

        return Inertia::render('admin/Announcements', [
            'announcements' => Announcement::query()
                ->with('author:id,name')
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (Announcement $announcement) => [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'body' => $announcement->body,
                    'recipientsCount' => $announcement->recipients_count,
                    'authorName' => $announcement->author?->name,
                    'sentAt' => $announcement->created_at?->toDayDateTimeString(),
                    'bannerLive' => $announcement->hasLiveBanner(),
                    'bannerUntil' => $announcement->banner_until?->toDayDateTimeString(),
                ]),
            'recipientsCount' => User::query()->realUsers()->count(),
            'bannerDays' => self::BANNER_DAYS,
        ]);
    }

    /**
     * Send an announcement to every real user's notification bell, and show
     * it as a banner when a duration is chosen.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('accessAdminConsole');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
            'banner_days' => ['nullable', 'integer', Rule::in(self::BANNER_DAYS)],
        ]);

        $recipients = User::query()->realUsers()->get();

        $announcement = Announcement::create([
            'title' => $validated['title'],
            'body' => $validated['body'],
            'banner_until' => filled($validated['banner_days'] ?? null)
                ? now()->addDays((int) $validated['banner_days'])
                : null,
            'recipients_count' => $recipients->count(),
            'created_by' => $request->user()->id,
        ]);

        Notification::send($recipients, new PlatformAnnouncement($announcement));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('Announcement sent to :count person.|Announcement sent to :count people.', $recipients->count()),
        ]);

        return back();
    }

    /**
     * Take an announcement's banner down early. The bell notifications stay.
     */
    public function endBanner(Announcement $announcement): RedirectResponse
    {
        Gate::authorize('accessAdminConsole');

        if ($announcement->hasLiveBanner()) {
            $announcement->update(['banner_until' => now()]);
        }

        return back();
    }
}
