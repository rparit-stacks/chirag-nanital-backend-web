@extends('layouts.admin.app', ['page' => $menuAdmin['delivery_boy_management']['active'] ?? "", 'sub_page' => $menuAdmin['delivery_boy_management']['route']['db_referrals']['sub_active'] ?? ""])

@section('title', __('labels.db_refer_and_earn'))

@section('header_data')
    @php
        $page_title = __('labels.db_refer_and_earn');
        $page_pretitle = __('labels.admin') . ' ' . __('labels.delivery_boys');
    @endphp
@endsection

@php
    $breadcrumbs = [
        ['title' => __('labels.home'), 'url' => route('admin.dashboard')],
        ['title' => __('labels.delivery_boys'), 'url' => '#'],
        ['title' => __('labels.db_refer_and_earn'), 'url' => null],
    ];
@endphp

@section('admin-content')
    <div class="row row-cards">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">{{ __('labels.db_refer_and_earn') }}</h3>
                        <x-breadcrumb :items="$breadcrumbs" />
                    </div>
                    <div class="card-actions">
                        <div class="row g-2">
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
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row w-full">
                        <x-datatable id="admin-db-referrals-table" :columns="$columns"
                            route="{{ route('admin.delivery-boy-referrals.datatable') }}" :options="['order' => [[0, 'desc']], 'pageLength' => 10]" />
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ hyperAsset('assets/js/delivery-boy-referrals.js') }}" defer></script>
@endpush