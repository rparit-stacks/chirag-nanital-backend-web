<?php

namespace App\Console\Commands;

use App\Services\ReferralService;
use Illuminate\Console\Command;

class SettleReferralEarnings extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'referral:settle';

    /**
     * The console command description.
     */
    protected $description = 'Settle pending referral earnings whose return window has expired and credit beneficiaries wallets';

    protected ReferralService $referralService;

    public function __construct(ReferralService $referralService)
    {
        parent::__construct();
        $this->referralService = $referralService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('=== Referral Earnings Settlement ===');
        $this->info('Processing pending earnings eligible for settlement...');

        $result = $this->referralService->settleEligibleEarnings();

        $this->info("✓ Settled: {$result['settled']}");

        if ($result['errors'] > 0) {
            $this->error("✗ Errors: {$result['errors']} (see laravel.log for details)");
        }

        $this->info('=== Done ===');

        return $result['errors'] > 0 ? 1 : 0;
    }
}
