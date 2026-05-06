<?php

namespace App\Notifications\DeliveryBoy;

use App\Broadcasting\FirebaseChannel;
use App\Enums\DefaultSystemRolesEnum;
use App\Enums\NotificationTypeEnum;
use App\Models\DeliveryBoyAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class DeliveryBoySettlementCreated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected DeliveryBoyAssignment $assignment)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail', FirebaseChannel::class];
    }

    public function toMail(object $notifiable): ?MailMessage
    {
        try {
            $a = $this->assignment;
            $amount = app(\App\Services\CurrencyService::class)->getSymbol() . number_format((float)($a->total_earnings ?? 0), 2);
            $isAdmin = $this->isAdmin($notifiable);
            $riderName = $a->deliveryBoy?->user?->name ?? ('Delivery Boy #' . ($a->delivery_boy_id ?? '-'));

            $mail = (new MailMessage)
                ->subject($isAdmin ? 'New Delivery Earning Entry' : 'New Earning Added')
                ->greeting('Hello ' . ($notifiable->name ?? ''));

            if ($isAdmin) {
                $mail->line('A new earning entry has been recorded for a delivery assignment.')
                    ->line('Delivery Boy: ' . $riderName)
                    ->line('Order ID: ' . ($a->order_id ?? '-'))
                    ->line('Amount: ' . $amount)
                    ->line('Created At: ' . optional($a->created_at)->toDateTimeString());
            } else {
                $mail->line('A new earning entry has been added for your delivery assignment.')
                    ->line('Order ID: ' . ($a->order_id ?? '-'))
                    ->line('Amount: ' . $amount)
                    ->line('We will notify you when it is paid to your wallet.');
            }
            return $mail;
        } catch (\Throwable $e) {
            Log::error('DeliveryBoySettlementCreated mail failed: ' . $e->getMessage());
            return null;
        }
    }

    public function toFirebase($notifiable): array
    {
        $a = $this->assignment;
        $amount = app(\App\Services\CurrencyService::class)->getSymbol() . number_format((float)($a->total_earnings ?? 0), 2);
        $isAdmin = $this->isAdmin($notifiable);
        $riderName = $a->deliveryBoy?->user?->name ?? ('Delivery Boy #' . ($a->delivery_boy_id ?? '-'));

        return [
            'title' => $isAdmin ? 'New Delivery Earning' : 'New Earning Added',
            'body'  => $isAdmin
                ? ($riderName . ' earned ' . $amount . ' for Order #' . ($a->order_id ?? '-') . '.')
                : ('Amount ' . $amount . ' added for Order #' . ($a->order_id ?? '-') . '.'),
            'image' => null,
            'data'  => [
                'type' => NotificationTypeEnum::SETTLEMENT_CREATE(),
                'delivery_boy_assignment_id' => $a->id,
                'order_id' => $a->order_id,
                'payment_status' => (string)($a->payment_status ?? ''),
            ],
        ];
    }

    public function toArray(object $notifiable): array
    {
        $a = $this->assignment;
        return [
            'delivery_boy_assignment_id' => $a->id,
            'amount' => (float)($a->total_earnings ?? 0),
            'payment_status' => (string)($a->payment_status ?? ''),
        ];
    }

    public function toDatabase(object $notifiable): array
    {
        $a = $this->assignment;
        $amount = app(\App\Services\CurrencyService::class)->getSymbol() . number_format((float)($a->total_earnings ?? 0), 2);
        $isAdmin = $this->isAdmin($notifiable);
        $riderName = $a->deliveryBoy?->user?->name ?? ('Delivery Boy #' . ($a->delivery_boy_id ?? '-'));

        return [
            'title' => $isAdmin ? 'New Delivery Earning' : 'New Earning Added',
            'message' => $isAdmin
                ? ('New earning of ' . $amount . ' recorded for ' . $riderName . ' on Order #' . ($a->order_id ?? '-') . '.')
                : ('Earning amount ' . $amount . ' added for Order #' . ($a->order_id ?? '-') . '.'),
            'type' => NotificationTypeEnum::SETTLEMENT_CREATE(),
            'sent_to' => $isAdmin ? 'admin' : 'delivery_boy',
            'user_id' => $notifiable->id ?? null,
            'order_id' => $a->order_id ?? null,
            'metadata' => [
                'delivery_boy_assignment_id' => $a->id,
                'total_earnings' => (float)($a->total_earnings ?? 0),
            ],
        ];
    }

    protected function isAdmin(object $notifiable): bool
    {
        return method_exists($notifiable, 'hasRole')
            && $notifiable->hasRole(DefaultSystemRolesEnum::SUPER_ADMIN());
    }
}
