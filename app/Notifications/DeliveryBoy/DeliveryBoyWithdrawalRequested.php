<?php

namespace App\Notifications\DeliveryBoy;

use App\Broadcasting\FirebaseChannel;
use App\Enums\DefaultSystemRolesEnum;
use App\Enums\NotificationTypeEnum;
use App\Events\DeliveryBoy\WithdrawalRequestCreated;
use App\Services\CurrencyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class DeliveryBoyWithdrawalRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected WithdrawalRequestCreated $event)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail', FirebaseChannel::class];
    }

    public function toMail(object $notifiable): ?MailMessage
    {
        try {
            $req = $this->event->withdrawalRequest;
            $amount = app(CurrencyService::class)->getSymbol() . number_format((float)$req->amount, 2);
            $isAdmin = $this->isAdmin($notifiable);
            $riderName = $req->user?->name ?? ('Delivery Boy #' . ($req->user_id ?? '-'));

            $mail = (new MailMessage)
                ->subject($isAdmin ? 'New Delivery Boy Withdrawal Request' : 'Withdrawal Request Submitted')
                ->greeting('Hello ' . ($notifiable->name ?? ''));

            if ($isAdmin) {
                $mail->line('A delivery boy has submitted a withdrawal request.')
                    ->line('Delivery Boy: ' . $riderName)
                    ->line('Amount: ' . $amount)
                    ->line('Status: ' . ucfirst((string)$req->status))
                    ->line('Requested At: ' . optional($req->created_at)->toDateTimeString());
            } else {
                $mail->line('Your withdrawal request has been submitted successfully.')
                    ->line('Amount: ' . $amount)
                    ->line('Status: ' . ucfirst((string)$req->status))
                    ->line('We will update you once it is processed.');
            }
            return $mail;
        } catch (\Throwable $e) {
            Log::error('DeliveryBoyWithdrawalRequested mail failed: ' . $e->getMessage());
            return null;
        }
    }

    public function toFirebase($notifiable): array
    {
        $req = $this->event->withdrawalRequest;
        $amount = app(CurrencyService::class)->getSymbol() . number_format((float)$req->amount, 2);
        $isAdmin = $this->isAdmin($notifiable);
        $riderName = $req->user?->name ?? ('Delivery Boy #' . ($req->user_id ?? '-'));

        return [
            'title' => $isAdmin ? 'New Delivery Withdrawal Request' : 'Withdrawal Request Submitted',
            'body'  => $isAdmin
                ? ($riderName . ' requested withdrawal of ' . $amount . '.')
                : ('Your withdrawal of ' . $amount . ' has been submitted.'),
            'image' => null,
            'data'  => [
                'type' => NotificationTypeEnum::WITHDRAWAL_REQUEST(),
                'withdrawal_request_id' => $req->id,
                'status' => (string)$req->status,
            ],
        ];
    }

    public function toArray(object $notifiable): array
    {
        $req = $this->event->withdrawalRequest;
        return [
            'withdrawal_request_id' => $req->id,
            'amount' => (float)$req->amount,
            'status' => (string)$req->status,
        ];
    }

    public function toDatabase(object $notifiable): array
    {
        $req = $this->event->withdrawalRequest;
        $amount = app(CurrencyService::class)->getSymbol() . number_format((float)$req->amount, 2);
        $isAdmin = $this->isAdmin($notifiable);
        $riderName = $req->user?->name ?? ('Delivery Boy #' . ($req->user_id ?? '-'));

        return [
            'title' => $isAdmin ? 'New Delivery Withdrawal Request' : 'Withdrawal Request Submitted',
            'message' => $isAdmin
                ? ('Withdrawal request from ' . $riderName . ' for ' . $amount . '.')
                : ('Your withdrawal request of ' . $amount . ' has been submitted.'),
            'type' => NotificationTypeEnum::WITHDRAWAL_REQUEST(),
            'sent_to' => $isAdmin ? 'admin' : 'delivery_boy',
            'user_id' => $notifiable->id ?? null,
            'order_id' => null,
            'metadata' => [
                'withdrawal_request_id' => $req->id,
                'status' => (string)$req->status,
                'delivery_boy_name' => $riderName,
            ],
        ];
    }

    protected function isAdmin(object $notifiable): bool
    {
        return method_exists($notifiable, 'hasRole')
            && $notifiable->hasRole(DefaultSystemRolesEnum::SUPER_ADMIN());
    }
}
