@extends('layouts.seller.app', [
    'page' => $menuSeller['products']['active'] ?? '',
    'sub_page' => $menuSeller['products']['route']['addon_groups']['sub_active'] ?? '',
])
@section('title', __('labels.addon_groups'))

@section('header_data')
    @php
        $page_title = __('labels.addon_groups');
        $page_pretitle = __('labels.seller') . " " . __('labels.addon_groups');
    @endphp
@endsection

@php
    $breadcrumbs = [
        ['title' => __('labels.home'), 'url' => route('seller.dashboard')],
        ['title' => __('labels.addon_groups'), 'url' => '']
    ];
@endphp

@section('seller-content')
    <div class="row row-cards">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">{{ __('labels.addon_groups') }}</h3>
                        <x-breadcrumb :items="$breadcrumbs"/>
                    </div>
                    <div class="card-actions">
                        <div class="row g-2">
                            @if($createPermission ?? false)
                                <div class="col-auto">
                                    <a href="{{ route('seller.addon-groups.create') }}" class="btn btn-primary">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                                             fill="none" stroke="currentColor" stroke-width="2"
                                             stroke-linecap="round" stroke-linejoin="round" class="icon icon-2">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                            <path d="M12 5l0 14"/>
                                            <path d="M5 12l14 0"/>
                                        </svg>
                                        {{ __('labels.addon_group_form_title_create') }}
                                    </a>
                                </div>
                            @endif
                            <div class="col-auto">
                                <button class="btn btn-outline-primary" id="refresh">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                         stroke-linecap="round" stroke-linejoin="round"
                                         class="icon icon-tabler icons-tabler-outline icon-tabler-refresh">
                                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                        <path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4"/>
                                        <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4"/>
                                    </svg>
                                    {{ __('labels.refresh') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body border-bottom py-3 text-muted">
                    {{ __('labels.addon_group_intro') }}
                </div>
                <div class="card-table">
                    <div class="row w-full p-3">
                        <x-datatable id="addon-groups-table" :columns="$columns"
                                     route="{{ route('seller.addon-groups.datatable') }}"
                                     :options="['order' => [[0, 'desc']],'pageLength' => 10,]"/>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ hyperAsset('assets/js/addon-groups.js') }}" defer></script>
@endpush
