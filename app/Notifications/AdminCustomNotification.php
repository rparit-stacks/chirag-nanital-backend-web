<?php

namespace App\Notifications;

use App\Broadcasting\FirebaseChannel;
use App\Models\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AdminCustomNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected AppNotification $appNotification) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', FirebaseChannel::class];
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->appNotification->title,
            'message' => $this->appNotification->message,
            'type' => $this->appNotification->target_type?->value,
            'sent_to' => $this->appNotification->audience_type->value,
            'user_id' => $notifiable->id ?? null,
            'metadata' => $this->appNotification['metadata'],
        ];
    }

    /**
     * Get the Firebase representation of the notification.
     */
    public function toFirebase($notifiable): array
    {
        $metadata = $this->appNotification['metadata'] ?? [];

        return [
            'title' => $this->appNotification->title,
            'body' => $this->appNotification->message,
            'image' => $this->appNotification->notification_image ?? null,
            'data' => array_merge([
                'notification_id' => $this->id,
                'type' => $this->appNotification->target_type?->value,
            ], $metadata),
        ];
    }
}
