<?php

namespace App\Notifications;

use App\Broadcasting\FirebaseChannel;
use App\Enums\DefaultSystemRolesEnum;
use App\Enums\NotificationTypeEnum;
use App\Enums\Order\OrderItemStatusEnum;
use App\Enums\Order\OrderStatusEnum;
use App\Models\SellerOrderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderStatusUpdated extends Notification implements ShouldQueue
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
        return ['database', FirebaseChannel::class, 'mail'];
    }

    /**
     * Firebase push payload.
     */
    public function toFirebase(object $notifiable): array
    {
        return $this->buildPayload($notifiable);
    }

    /**
     * Mail representation.
     */
    public function toMail(object $notifiable): ?MailMessage
    {
        try {
            $payload = $this->buildPayload($notifiable);
            $order = $this->event->orderItem->order;

            $mail = (new MailMessage)
                ->subject($payload['title'])
                ->greeting('Hello ' . ($notifiable->name ?? '') . '!')
                ->line($payload['body']);

            if ($orderId = $order->id ?? null) {
                $url = $this->isSeller($notifiable)
                    ? url('seller/orders/' . $orderId)
                    : url('orders/' . $orderId);
                $mail->action('View Order', $url);
            }

            return $mail;
        } catch (\Throwable $e) {
            Log::error('OrderStatusUpdated mail failed: ' . $e->getMessage(), [
                'notifiable_id' => $notifiable->id ?? null,
                'notification' => static::class,
            ]);

            return null;
        }
    }

    /**
     * Persisted database notification row.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $payload = $this->buildPayload($notifiable);
        $order = $this->event->orderItem->order;
        $data = $payload['data'];

        return [
            'title' => $payload['title'],
            'message' => $payload['body'],
            'type' => $data['type'] ?? NotificationTypeEnum::ORDER_UPDATE(),
            'sent_to' => $this->isSeller($notifiable) ? 'seller' : 'customer',
            'user_id' => $notifiable->id ?? null,
            'store_id' => null,
            'order_id' => $data['order_id'] ?? ($order->id ?? null),
            'metadata' => array_merge($data, [
                'order_id' => $order->id ?? null,
                'order_slug' => $order->slug ?? null,
                'seller_order_id' => $this->resolveSellerOrderId(),
            ]),
        ];
    }

    /**
     * Legacy array shape (kept for broadcast compatibility).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $order = $this->event->orderItem->order;

        return [
            'order_id' => $order->id ?? null,
            'order_slug' => $order->slug ?? null,
            'status' => ucfirst(Str::replace('_', ' ', $order->status ?? '')),
        ];
    }

    /**
     * Build the title/body/image/data block for the given recipient.
     * Single source of truth for every channel.
     *
     * @return array{title:string,body:string,image:?string,data:array<string,mixed>}
     */
    public function buildPayload(object $notifiable): array
    {
        $isSeller = $this->isSeller($notifiable);
        $audience = $isSeller ? 'seller' : 'customer';
        $orderItem = $this->event->orderItem;
        $order = $orderItem->order;
        $image = $orderItem->product->main_image ?? null;
        $orderId = $order->id ?? '-';
        $newStatus = $this->event->newStatus;

        // Item delivered
        if ((string) $newStatus === OrderItemStatusEnum::DELIVERED()) {
            return [
                'title' => 'Order Delivered: ' . $orderItem->title,
                'body' => $audience === 'seller'
                    ? 'The order item "' . $orderItem->title . '" from order #' . $orderId . ' has been delivered to the customer.'
                    : 'Your order item "' . $orderItem->title . '" has been successfully delivered. We hope you enjoy your purchase!',
                'image' => $image,
                'data' => [
                    'order_slug' => $order->slug ?? null,
                    'order_id' => $orderItem->order_id ?? ($order->id ?? null),
                    'status' => $orderItem->status,
                    'type' => NotificationTypeEnum::DELIVERY(),
                ],
            ];
        }

        // Item cancelled
        if ((string) $newStatus === OrderItemStatusEnum::CANCELLED()) {
            return [
                'title' => 'Order Item Cancelled: ' . $orderItem->title,
                'body' => $audience === 'seller'
                    ? 'The order item "' . $orderItem->title . '" from order #' . $orderId . ' has been cancelled by the customer.'
                    : 'Your order item "' . $orderItem->title . '" has been cancelled successfully.',
                'image' => $image,
                'data' => [
                    'order_slug' => $order->slug ?? null,
                    'order_id' => $orderItem->order_id ?? ($order->id ?? null),
                    'status' => $orderItem->status,
                    'type' => NotificationTypeEnum::ORDER_UPDATE(),
                ],
            ];
        }

        // Delivery partner assigned at the order level
        if ($order && (string) ($order->status ?? '') === OrderStatusEnum::ASSIGNED()) {
            return [
                'title' => 'Delivery Partner Assigned',
                'body' => $audience === 'seller'
                    ? 'A delivery partner has been assigned for Order #' . $orderId . '.'
                    : 'A delivery partner has been assigned for your order #' . $orderId . '.',
                'image' => $image,
                'data' => [
                    'order_slug' => $order->slug ?? null,
                    'order_id' => $orderItem->order_id ?? ($order->id ?? null),
                    'status' => $order->status ?? '',
                    'type' => NotificationTypeEnum::ORDER_UPDATE(),
                ],
            ];
        }

        // Default order-level update
        $status = ucfirst(Str::replace('_', ' ', $order->status ?? ''));

        return [
            'title' => 'Order Status Updated',
            'body' => $audience === 'seller'
                ? 'Order #' . $orderId . ' is now ' . $status . '.'
                : 'Your order #' . $orderId . ' is now ' . $status . '.',
            'image' => $image,
            'data' => [
                'order_slug' => $order->slug ?? null,
                'order_id' => $order->id ?? null,
                'status' => $status,
                'type' => NotificationTypeEnum::ORDER_UPDATE(),
            ],
        ];
    }

    private function isSeller(object $notifiable): bool
    {
        return method_exists($notifiable, 'hasRole')
            && $notifiable->hasRole(DefaultSystemRolesEnum::SELLER());
    }

    private function resolveSellerOrderId(): ?int
    {
        try {
            return SellerOrderItem::where('order_item_id', $this->event->orderItem->id)
                ->value('seller_order_id');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
