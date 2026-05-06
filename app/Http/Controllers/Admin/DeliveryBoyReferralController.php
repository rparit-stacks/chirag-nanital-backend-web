<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryBoy;
use App\Models\DeliveryBoyReferral;
use App\Models\DeliveryBoyReferralEarning;
use App\Services\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeliveryBoyReferralController extends Controller
{
    public function __construct(protected CurrencyService $currencyService) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Referrals Index
    // ─────────────────────────────────────────────────────────────────────────

    public function index(): View
    {
        $columns = [
            ['data' => 'id', 'name' => 'id', 'title' => __('labels.id')],
            ['data' => 'referrer', 'name' => 'referrer', 'title' => __('labels.referrer'), 'orderable' => false],
            ['data' => 'total_referred', 'name' => 'total_referred', 'title' => __('labels.total_referred')],
            ['data' => 'total_rewarded', 'name' => 'total_rewarded', 'title' => __('labels.total_rewarded')],
            ['data' => 'total_earned', 'name' => 'total_earned', 'title' => __('labels.total_earned')],
            ['data' => 'action', 'name' => 'action', 'title' => __('labels.action'), 'orderable' => false, 'searchable' => false],
        ];

        return view('admin.delivery-boy-referrals.index', compact('columns'));
    }

    public function datatableIndex(Request $request): JsonResponse
    {
        $draw = $request->get('draw');
        $start = $request->get('start', 0);
        $length = $request->get('length', 10);
        $search = $request->get('search')['value'] ?? '';
        $status = $request->get('status');

        // Get distinct referrers with their stats
        $query = DeliveryBoyReferral::query()
            ->select('referrer_id')
            ->with('referrer')
            ->distinct();

        if ($search) {
            $query->whereHas('referrer', fn ($q) => $q->where('full_name', 'like', "%{$search}%"));
        }

        $total = DeliveryBoyReferral::select('referrer_id')->distinct()->count();
        $filtered = $query->count();

        $referrers = $query->orderBy('referrer_id', 'desc')
            ->skip($start)->take($length)->get()
            ->pluck('referrer_id');

        $data = $referrers->map(function ($referrerId) use ($status) {
            $referrer = DeliveryBoy::find($referrerId);

            // Get referrals query for this referrer
            $referralsQuery = DeliveryBoyReferral::where('referrer_id', $referrerId);

            // Apply status filter if provided
            if ($status) {
                $referralsQuery->where('status', $status);
            }

            $totalReferred = DeliveryBoyReferral::where('referrer_id', $referrerId)->count();
            $totalRewarded = DeliveryBoyReferral::where('referrer_id', $referrerId)
                ->where('status', 'rewarded')->count();

            $totalEarned = $this->getTotalEarnedForReferrer($referrerId);

            return [
                'id' => $referrer?->id ?? $referrerId,
                'referrer' => $referrer?->full_name ?? 'N/A',
                'total_referred' => $totalReferred,
                'total_rewarded' => $totalRewarded,
                'total_earned' => $this->currencyService->format($totalEarned),
                'action' => 
                $referrer
                    ? '<a href="'.route('admin.delivery-boy-referrals.earnings', $referrerId).'" class="btn btn-sm btn-outline-primary">'.__('labels.view_referrals').'</a>'
                    : '—',
            ];
        });

        return response()->json([
            'draw' => (int) $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $data,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Referred Users Detail
    // ─────────────────────────────────────────────────────────────────────────

    public function earnings(int $referrerId): View
    {
        $referrer = DeliveryBoy::findOrFail($referrerId);
        $referrerDetail = \App\Models\User::find($referrer->user_id);

        $totalEarnedAmount = $this->getTotalEarnedForReferrer($referrerId);

        $totalEarned = $this->currencyService->format($totalEarnedAmount);

        $columns = [
            ['data' => 'id', 'name' => 'id', 'title' => __('labels.id')],
            ['data' => 'referred', 'name' => 'referred', 'title' => __('labels.referred'), 'orderable' => false],
            ['data' => 'status', 'name' => 'status', 'title' => __('labels.status')],
            ['data' => 'referral_code', 'name' => 'referral_code', 'title' => __('labels.referral_code')],
            ['data' => 'earned_amount', 'name' => 'earned_amount', 'title' => __('labels.earned_amount')],
            ['data' => 'rewarded_at', 'name' => 'rewarded_at', 'title' => __('labels.rewarded_at')],
            ['data' => 'created_at', 'name' => 'created_at', 'title' => __('labels.created_at')],
        ];

        return view('admin.delivery-boy-referrals.earnings', compact('referrer', 'referrerDetail', 'totalEarned', 'columns'));
    }

    public function datatableEarnings(Request $request, int $referrerId): JsonResponse
    {
        $draw = $request->get('draw');
        $start = $request->get('start', 0);
        $length = $request->get('length', 10);
        $search = $request->get('search')['value'] ?? '';
        $status = $request->get('status');

        $query = DeliveryBoyReferral::where('referrer_id', $referrerId)
            ->with(['referred']);

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('referral_code', 'like', "%{$search}%")
                    ->orWhereHas('referred', fn ($r) => $r->where('full_name', 'like', "%{$search}%"));
            });
        }

        $total = DeliveryBoyReferral::where('referrer_id', $referrerId)->count();
        $filtered = $query->count();

        $data = $query->latest('created_at')->skip($start)->take($length)->get()
            ->map(function ($r) {
                $statusColors = ['pending' => 'warning', 'rewarded' => 'success', 'cancelled' => 'danger'];
                $color = $statusColors[$r->status] ?? 'secondary';
                $statusBadge = "<span class=\"badge bg-{$color}-lt\">".ucfirst($r->status).'</span>';

                // Calculate earned amount for this referral
                $earnedAmount = DeliveryBoyReferralEarning::where('referral_id', $r->id)
                    ->where('beneficiary_id', $r->referrer_id)
                    ->sum('bonus_amount');

                return [
                    'id' => $r->id,
                    'referred' => $r->referred?->full_name ?? 'N/A',
                    'status' => $statusBadge,
                    'referral_code' => $r->referral_code,
                    'earned_amount' => $this->currencyService->format($earnedAmount),
                    'rewarded_at' => $r->rewarded_at?->format('Y-m-d H:i') ?? '—',
                    'created_at' => $r->created_at->format('Y-m-d'),
                ];
            });

        return response()->json([
            'draw' => (int) $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $data,
        ]);
    }

    protected function getTotalEarnedForReferrer(int $referrerId): float
    {
        return (float) DeliveryBoyReferralEarning::where('beneficiary_id', $referrerId)
            ->whereHas('referral', function ($query) use ($referrerId) {
                $query->where('referrer_id', $referrerId);
            })
            ->sum('bonus_amount');
    }
}
