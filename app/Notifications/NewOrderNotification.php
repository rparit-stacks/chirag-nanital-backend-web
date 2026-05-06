<?php

namespace App\Notifications;

use App\Broadcasting\FirebaseChannel;
use App\Enums\DefaultSystemRolesEnum;
use App\Enums\NotificationTypeEnum;
use App\Services\CurrencyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NewOrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $event;

    public function __construct($event)
    {
        $this->event = $event;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Firebase before mail so push still fires if SMTP errors.
        return ['database', FirebaseChannel::class, 'mail'];
    }

    public function toMail(object $notifiable): ?MailMessage
    {
        try {
            $payload = $this->buildPayload($notifiable);
            $order = $this->event->order;

            return (new MailMessage)
                ->subject($payload['title'])
                ->greeting('Hello ' . ($notifiable->name ?? ''))
                ->line($payload['body'])
                ->line('Order Total: ' . app(CurrencyService::class)->getSymbol() . number_format((float) ($order->final_total ?? 0), 2))
                ->line('Status: ' . ucfirst(Str::replace('_', ' ', $order->status ?? '')));
        } catch (\Throwable $e) {
            Log::error('NewOrderNotification mail failed: ' . $e->getMessage());

            return null;
        }
    }

    public function toFirebase(object $notifiable): array
    {
        return $this->buildPayload($notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $order = $this->event->order;

        return [
            'order_id' => $order->id ?? null,
            'order_slug' => $order->slug ?? null,
            'status' => ucfirst(Str::replace('_', ' ', $order->status ?? '')),
            'total' => (float) ($order->final_total ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $payload = $this->buildPayload($notifiable);
        $order = $this->event->order;
        $isSeller = $this->isSeller($notifiable);
        $data = $payload['data'];

        return [
            'title' => $payload['title'],
            'message' => $payload['body'],
            'type' => NotificationTypeEnum::NEW_ORDER(),
            'sent_to' => $isSeller ? 'seller' : 'customer',
            'user_id' => $notifiable->id ?? null,
            'store_id' => $isSeller ? ($order->sellerOrders->first()->seller_id ?? null) : null,
            'order_id' => $order->id ?? null,
            'metadata' => array_merge($data, [
                'total' => (float) ($order->final_total ?? 0),
            ]),
        ];
    }

    /**
     * Single source of truth for title/body/image/data per audience.
     *
     * @return array{title:string,body:string,image:?string,data:array<string,mixed>}
     */
    public function buildPayload(object $notifiable): array
    {
        $order = $this->event->order;
        $isSeller = $this->isSeller($notifiable);
        $orderId = $order->id ?? '-';
        $image = $order->items->first()->product->main_image ?? null;
        $firstSellerOrderId = null;
        try {
            $firstSellerOrderId = $order->sellerOrders->first()->id ?? null;
        } catch (\Throwable $e) {
            // no-op
        }

        $data = [
            'order_slug' => $order->slug ?? null,
            'order_id' => $order->id ?? null,
            'status' => ucfirst(Str::replace('_', ' ', $order->status ?? '')),
            'type' => NotificationTypeEnum::ORDER(),
            'seller_order_id' => $firstSellerOrderId,
        ];

        if ($isSeller) {
            return [
                'title' => 'New Order Received 🎉',
                'body' => 'You have received a new order (Order #' . $orderId . '). Please review and confirm it at your earliest convenience.',
                'image' => $image,
                'data' => $data,
            ];
        }

        return [
            'title' => 'Order Placed Successfully 🎉',
            'body' => 'Thank you for your order! Your order #' . $orderId . ' has been placed successfully. We’ll notify you once it’s confirmed by the seller.',
            'image' => $image,
            'data' => $data,
        ];
    }

    private function isSeller(object $notifiable): bool
    {
        return method_exists($notifiable, 'hasRole')
            && $notifiable->hasRole(DefaultSystemRolesEnum::SELLER());
    }
}
