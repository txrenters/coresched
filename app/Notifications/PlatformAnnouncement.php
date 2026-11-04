<?php

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Notifications\Notification;

/**
 * A super admin's announcement, delivered to the notification bell only.
 *
 * No email: the people it reaches use the app, and an announcement about,
 * say, a new address is exactly the kind of mail that reads as phishing.
 */
class PlatformAnnouncement extends Notification
{
    public function __construct(public Announcement $announcement) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Build the stored representation shown in the notification panel, in
     * the same shape as the booking notifications.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'announcement',
            'title' => $this->announcement->title,
            'body' => $this->announcement->body,
            'announcementId' => $this->announcement->id,
            'bookingUid' => null,
            'whenLabel' => null,
            'teamSlug' => null,
        ];
    }
}
