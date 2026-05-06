<?php

namespace Tests\Feature;
use App\Models\User;
use App\Models\OrderItemReturn;
use App\Notifications\OrderItemReturnRequested;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OrderItemReturnNotificationTest extends TestCase
{
    public function test_return_request_notification_is_sent()
    {

    }
}
