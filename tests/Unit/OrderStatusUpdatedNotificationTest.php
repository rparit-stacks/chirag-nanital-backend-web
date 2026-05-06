<?php

use App\Enums\DefaultSystemRolesEnum;
use App\Enums\NotificationTypeEnum;
use App\Enums\Order\OrderItemStatusEnum;
use App\Enums\Order\OrderStatusEnum;
use App\Notifications\OrderStatusUpdated;

function buildOrderStatusEvent(string $newStatus, string $orderStatus, string $itemStatus): object
{
    $product = (object) ['main_image' => 'https://example.test/img.jpg'];
    $order = (object) [
        'id' => 123,
        'slug' => 'ord-123',
        'status' => $orderStatus,
    ];
    $orderItem = (object) [
        'id' => 9,
        'title' => 'Sample Product',
        'status' => $itemStatus,
        'order_id' => 123,
        'order' => $order,
        'product' => $product,
    ];

    return (object) [
        'orderItem' => $orderItem,
        'oldStatus' => OrderItemStatusEnum::AWAITING_STORE_RESPONSE(),
        'newStatus' => $newStatus,
    ];
}

function customerNotifiable(): object
{
    return new class {
        public int $id = 7;
        public string $name = 'Customer';
        public function hasRole($role): bool
        {
            return false;
        }
    };
}

function sellerNotifiable(): object
{
    return new class {
        public int $id = 11;
        public string $name = 'Seller';
        public function hasRole($role): bool
        {
            return (string) $role === DefaultSystemRolesEnum::SELLER();
        }
    };
}

test('item cancellation notification reports cancelled item even when order status is awaiting_store_response', function () {
    $event = buildOrderStatusEvent(
        newStatus: OrderItemStatusEnum::CANCELLED(),
        orderStatus: OrderStatusEnum::AWAITING_STORE_RESPONSE(),
        itemStatus: OrderItemStatusEnum::CANCELLED(),
    );

    $notification = new OrderStatusUpdated($event);

    $customerPayload = $notification->buildPayload(customerNotifiable());
    expect($customerPayload['title'])->toContain('Cancelled')
        ->and($customerPayload['body'])->toContain('cancelled')
        ->and($customerPayload['body'])->not->toContain('awaiting')
        ->and($customerPayload['data']['status'])->toBe(OrderItemStatusEnum::CANCELLED())
        ->and($customerPayload['data']['type'])->toBe(NotificationTypeEnum::ORDER_UPDATE());

    $sellerPayload = $notification->buildPayload(sellerNotifiable());
    expect($sellerPayload['title'])->toContain('Cancelled')
        ->and($sellerPayload['body'])->toContain('cancelled')
        ->and($sellerPayload['body'])->toContain('#123')
        ->and($sellerPayload['body'])->not->toContain('awaiting')
        ->and($sellerPayload['data']['status'])->toBe(OrderItemStatusEnum::CANCELLED());
});

test('delivered branch still wins over default order-status copy', function () {
    $event = buildOrderStatusEvent(
        newStatus: OrderItemStatusEnum::DELIVERED(),
        orderStatus: OrderStatusEnum::AWAITING_STORE_RESPONSE(),
        itemStatus: OrderItemStatusEnum::DELIVERED(),
    );

    $payload = (new OrderStatusUpdated($event))->buildPayload(customerNotifiable());
    expect($payload['title'])->toContain('Delivered')
        ->and($payload['data']['type'])->toBe(NotificationTypeEnum::DELIVERY());
});

test('assigned order status produces delivery-partner-assigned copy for both audiences', function () {
    $event = buildOrderStatusEvent(
        newStatus: OrderStatusEnum::ASSIGNED(),
        orderStatus: OrderStatusEnum::ASSIGNED(),
        itemStatus: OrderItemStatusEnum::ACCEPTED(),
    );

    $notification = new OrderStatusUpdated($event);

    $customer = $notification->buildPayload(customerNotifiable());
    expect($customer['title'])->toBe('Delivery Partner Assigned')
        ->and($customer['body'])->toContain('your order #123');

    $seller = $notification->buildPayload(sellerNotifiable());
    expect($seller['title'])->toBe('Delivery Partner Assigned')
        ->and($seller['body'])->toContain('Order #123');
});
