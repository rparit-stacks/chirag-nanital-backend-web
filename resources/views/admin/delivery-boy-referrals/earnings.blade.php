@extends('layouts.admin.app', ['page' => $menuAdmin['delivery_boy_management']['active'] ?? "", 'sub_page' => $menuAdmin['delivery_boy_management']['route']['db_referrals']['sub_active'] ?? ""])

@section('title', __('labels.referral_earnings'))

@section('header_data')
    @php
        $page_title = __('labels.referral_earnings');
        $page_pretitle = __('labels.admin') . ' ' . __('labels.delivery_boys');
    @endphp
@endsection

@php
    $breadcrumbs = [
        ['title' => __('labels.home'), 'url' => route('admin.dashboard')],
        ['title' => __('labels.db_refer_and_earn'), 'url' => route('admin.delivery-boy-referrals.index')],
        ['title' => $referrer->full_name ?? 'N/A', 'url' => null],
    ];
@endphp

@section('admin-content')
    <div class="row row-cards">
        <div class="col-12 col-lg-9">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">{{ __('labels.referrals') }}</h3>
                        <x-breadcrumb :items="$breadcrumbs" />
                    </div>
                    <div class="card-actions">
                        <div class="row g-2">
                            <div class="col-auto">
                                <select id="statusFilter" class="form-select">
                                    <option value="">{{ __('labels.all_statuses') }}</option>
                                    <option value="pending">{{ __('labels.pending') }}</option>
                                    <option value="rewarded">{{ __('labels.rewarded') }}</option>
                                    <option value="cancelled">{{ __('labels.cancelled') }}</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button id="refresh" class="btn btn-outline-primary">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="icon icon-tabler icon-tabler-refresh">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                        <path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
                                        <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
                                    </svg>
                                    {{ __('labels.refresh') }}
                                </button>
                            </div>
                            <div class="col-auto">
                                <a href="{{ route('admin.delivery-boy-referrals.index') }}"
                                    class="btn btn-outline-secondary">
                                    {{ __('labels.back') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row w-full">
                        <x-datatable id="admin-db-referrals-referred-table" :columns="$columns"
                            route="{{ route('admin.delivery-boy-referrals.earnings.datatable', $referrer->id) }}"
                            :options="['order' => [[0, 'desc']], 'pageLength' => 10]" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-12 col-lg-3">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title mb-4">{{ __('labels.referrer') }}</h4>

                    <div class="mb-4">
                        <p class="text-secondary mb-1">{{ __('labels.name') }}</p>
                        <h5 class="mb-0">{{ $referrer->full_name ?? 'N/A' }}</h5>
                    </div>

                    <div class="mb-4">
                        <p class="text-secondary mb-1">{{ __('labels.phone') }}</p>
                        <h5 class="mb-0">{{ $referrerDetail->mobile ?? 'N/A' }}</h5>
                    </div>

                    <div class="mb-4">
                        <p class="text-secondary mb-1">{{ __('labels.email') }}</p>
                        <h5 class="mb-0 text-break">{{ $referrerDetail->email ?? 'N/A' }}</h5>
                    </div>

                    <hr class="my-3">

                    <div class="mb-4">
                        <p class="text-secondary mb-1">{{ __('labels.total_earned') }}</p>
                        <h3 class="mb-0 text-success">{{ $totalEarned ?? '$0.00' }}</h3>
                    </div>

                    <hr class="my-3">
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ hyperAsset('assets/js/delivery-boy-referral-earnings.js') }}" defer></script>
@endpush