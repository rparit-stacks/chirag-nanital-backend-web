<?php

namespace App\Listeners\DeliveryBoy;

use App\Enums\DeliveryBoy\DeliveryBoyVerificationStatusEnum;
use App\Events\DeliveryBoy\DeliveryBoyVerificationStatusUpdated;
use App\Services\DeliveryBoyReferralService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class HandleDeliveryBoyReferralOnVerification
{
    public function __construct(protected DeliveryBoyReferralService $referralService)
    {
    }

    public function handle(DeliveryBoyVerificationStatusUpdated $event): void
    {
        $newStatus = $event->newStatus;
        $deliveryBoy = $event->deliveryBoy;

        Log::info("DeliveryBoyReferral: event fired for DB #{$deliveryBoy->id}, newStatus={$newStatus}");

        try {
            if ($newStatus === DeliveryBoyVerificationStatusEnum::VERIFIED()) {
                Log::info("DeliveryBoyReferral: processing verification payout for DB #{$deliveryBoy->id}");
                $this->referralService->handleVerification($deliveryBoy);
            } elseif ($newStatus === DeliveryBoyVerificationStatusEnum::REJECTED()) {
                Log::info("DeliveryBoyReferral: processing rejection for DB #{$deliveryBoy->id}");
                $this->referralService->handleRejection($deliveryBoy);
            }
        } catch (\Throwable $e) {
            Log::error("DeliveryBoyReferral: failed for DB #{$deliveryBoy->id} — " . $e->getMessage());
            throw $e; // re-throw so the queue marks the job as failed & retries
        }
    }

    public function failed(DeliveryBoyVerificationStatusUpdated $event, \Throwable $exception): void
    {
        Log::error(
            "DeliveryBoyReferral: job permanently failed for DB #{$event->deliveryBoy->id} — " .
            $exception->getMessage()
        );
    }
}
