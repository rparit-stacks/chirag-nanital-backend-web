<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\ReferralEarning;
use App\Services\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReferralController extends Controller
{
    protected CurrencyService $currencyService;

    public function __construct(CurrencyService $currencyService)
    {
        $this->currencyService = $currencyService;
    }

    /**
     * Referrals overview page (list of all referral relationships).
     */
    public function index(): View
    {
        $columns = [
            ['data' => 'id', 'name' => 'id', 'title' => __('labels.id')],
            ['data' => 'referrer', 'name' => 'referrer', 'title' => __('labels.referrer')],
            ['data' => 'referred', 'name' => 'referred', 'title' => __('labels.referred')],
            ['data' => 'referral_code', 'name' => 'referral_code', 'title' => __('labels.referral_code')],
            ['data' => 'status', 'name' => 'status', 'title' => __('labels.status')],
            ['data' => 'total_earned', 'name' => 'total_earned', 'title' => __('labels.total_earned'), 'orderable' => false, 'searchable' => false],
            ['data' => 'created_at', 'name' => 'created_at', 'title' => __('labels.created_at')],
        ];
        $currencySymbol = $this->currencyService->getSymbol();
        return view('admin.referrals.index', compact('columns', 'currencySymbol'));
    }

    /**
     * Datatable for the referrals index page.
     */
    public function datatable(Request $request): JsonResponse
    {
        $draw = $request->get('draw');
        $start = (int) $request->get('start', 0);
        $length = (int) $request->get('length', 10);
        $searchValue = $request->get('search')['value'] ?? '';
        $statusFilter = $request->get('status', '');

        $orderColumnIndex = $request->get('order')[0]['column'] ?? 0;
        $orderDirection = $request->get('order')[0]['dir'] ?? 'desc';
        $columns = ['id', 'referrer_id', 'referred_id', 'referral_code', 'status', null, 'created_at'];
        $orderColumn = $columns[$orderColumnIndex] ?? 'id';

        $query = Referral::query()->with(['referrer', 'referred']);

        if (!empty($statusFilter)) {
            $query->where('status', $statusFilter);
        }

        $totalRecords = $query->count();

        if (!empty($searchValue)) {
            $query->where(function ($q) use ($searchValue) {
                $q->where('id', 'like', "%{$searchValue}%")
                    ->orWhere('referral_code', 'like', "%{$searchValue}%")
                    ->orWhereHas('referrer', fn($u) => $u->where('name', 'like', "%{$searchValue}%"))
                    ->orWhereHas('referred', fn($u) => $u->where('name', 'like', "%{$searchValue}%"));
            });
        }

        $filteredRecords = $query->count();

        $data = $query
            ->when($orderColumn, fn($q) => $q->orderBy($orderColumn, $orderDirection))
            ->skip($start)
            ->take($length)
            ->get()
            ->map(function (Referral $r) {
                $totalEarned = ReferralEarning::where('referral_id', $r->id)
                    ->where('beneficiary_type', 'referrer')
                    ->where('status', 'success')
                    ->sum('earned_amount');

                $statusBadge = match ($r->status) {
                    'pending' => '<span class="badge bg-secondary-lt">' . __('labels.pending') . '</span>',
                    'active' => '<span class="badge bg-info-lt">' . __('labels.active') . '</span>',
                    'completed' => '<span class="badge bg-success-lt">' . __('labels.completed') . '</span>',
                    default => ucfirst($r->status),
                };

                return [
                    'id' => $r->id,
                    'referrer' => $r->referrer?->name ?? 'N/A',
                    'referred' => $r->referred?->name ?? 'N/A',
                    'referral_code' => '<code>' . $r->referral_code . '</code>',
                    'status' => $statusBadge,
                    'total_earned' => $this->currencyService->format($totalEarned),
                    'created_at' => $r->created_at?->format('Y-m-d H:i:s'),
                ];
            });

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $filteredRecords,
            'data' => $data,
        ]);
    }

    /**
     * Referral earnings page (all individual earning rows).
     */
    public function earnings(): View
    {
        $columns = [
            ['data' => 'id', 'name' => 'id', 'title' => __('labels.id')],
            ['data' => 'beneficiary', 'name' => 'beneficiary', 'title' => __('labels.beneficiary'), 'orderable' => false],
            ['data' => 'beneficiary_type', 'name' => 'beneficiary_type', 'title' => __('labels.type')],
            ['data' => 'order_id', 'name' => 'order_id', 'title' => __('labels.order')],
            ['data' => 'bonus_method', 'name' => 'bonus_method', 'title' => __('labels.bonus_method')],
            ['data' => 'order_amount', 'name' => 'order_amount', 'title' => __('labels.order_amount')],
            ['data' => 'earned_amount', 'name' => 'earned_amount', 'title' => __('labels.earned_amount')],
            ['data' => 'status', 'name' => 'status', 'title' => __('labels.status')],
            ['data' => 'settled_at', 'name' => 'settled_at', 'title' => __('labels.settled_at')],
        ];
        return view('admin.referrals.earnings', compact('columns'));
    }

    /**
     * Datatable for the referral earnings page.
     */
    public function earningsDatatable(Request $request): JsonResponse
    {
        $draw = $request->get('draw');
        $start = (int) $request->get('start', 0);
        $length = (int) $request->get('length', 10);
        $searchValue = $request->get('search')['value'] ?? '';
        $statusFilter = $request->get('status_filter', '');

        $orderColumnIndex = $request->get('order')[0]['column'] ?? 0;
        $orderDirection = $request->get('order')[0]['dir'] ?? 'desc';
        $columns = ['id', null, 'beneficiary_type', 'order_id', 'bonus_method', 'order_amount', 'earned_amount', 'status', 'settled_at'];
        $orderColumn = $columns[$orderColumnIndex] ?? 'id';

        $query = ReferralEarning::query()->with(['beneficiary', 'order', 'referral']);

        if (!empty($statusFilter)) {
            $query->where('status', $statusFilter);
        }

        $totalRecords = $query->count();

        if (!empty($searchValue)) {
            $query->where(function ($q) use ($searchValue) {
                $q->where('id', 'like', "%{$searchValue}%")
                    ->orWhere('order_id', 'like', "%{$searchValue}%")
                    ->orWhereHas('beneficiary', fn($u) => $u->where('name', 'like', "%{$searchValue}%"));
            });
        }

        $filteredRecords = $query->count();

        $data = $query
            ->when($orderColumn, fn($q) => $q->orderBy($orderColumn, $orderDirection))
            ->skip($start)
            ->take($length)
            ->get()
            ->map(function (ReferralEarning $e) {
                $statusBadge = match ($e->status) {
                    'pending' => '<span class="badge bg-warning-lt">' . __('labels.pending') . '</span>',
                    'success' => '<span class="badge bg-success-lt">' . __('labels.success') . '</span>',
                    default => ucfirst($e->status),
                };

                $typeBadge = $e->beneficiary_type === 'referrer'
                    ? '<span class="badge text-bg-azure">' . __('labels.referrer') . '</span>'
                    : '<span class="badge text-bg-indigo">' . __('labels.referee') . '</span>';

                return [
                    'id' => $e->id,
                    'beneficiary' => $e->beneficiary?->name ?? 'N/A',
                    'beneficiary_type' => $typeBadge,
                    'order_id' => '#' . ($e->order?->id ?? 'N/A'),
                    'bonus_method' => ucfirst($e->bonus_method) . ' (' . $e->bonus_value . ($e->bonus_method === 'percentage' ? '%' : '') . ')',
                    'order_amount' => $this->currencyService->format($e->order_amount ?? 0),
                    'earned_amount' => $this->currencyService->format($e->earned_amount),
                    'status' => $statusBadge,
                    'settled_at' => $e->settled_at?->format('Y-m-d H:i') ?? '—',
                ];
            });

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $filteredRecords,
            'data' => $data,
        ]);
    }
}
