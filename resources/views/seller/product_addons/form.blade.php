@extends('layouts.seller.app', [
    'page' => $menuSeller['products']['active'] ?? '',
    'sub_page' => $menuSeller['products']['route']['product_addons']['sub_active'] ?? '',
])


@php
    $isEdit = ($mode ?? 'create') === 'edit';
    $formTitle = $isEdit
        ? __('labels.product_addon_form_title_edit')
        : __('labels.product_addon_form_title_create');
    $action = $isEdit
        ? route('seller.product-addons.update', [$variant->id ?? '', $group->id ?? ''])
        : route('seller.product-addons.store');
@endphp

@section('title', $formTitle)

@section('header_data')
    @php
        $page_title = $formTitle;
        $page_pretitle = __('labels.seller') . ' ' . __('labels.addons_menu');
    @endphp
@endsection

@php
    $breadcrumbs = [
        ['title' => __('labels.home'), 'url' => route('seller.dashboard')],
        ['title' => __('labels.product_addons'), 'url' => route('seller.product-addons.index')],
        ['title' => $formTitle, 'url' => ''],
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/product-addons.css') }}">
@endpush

@section('seller-content')
    <div class="row row-cards">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">{{ $formTitle }}</h3>
                        <x-breadcrumb :items="$breadcrumbs"/>
                    </div>
                    <div class="card-actions">
                        <a href="{{ route('seller.product-addons.index') }}" class="btn btn-outline-secondary">
                            {{ __('labels.cancel') }}
                        </a>
                    </div>
                </div>

                <form id="product-addon-form" action="{{ $action }}" method="POST" class="form-submit" novalidate>
                    @csrf

                    @if($isEdit)
                        {{-- Edit mode: hidden keys + read-only summary --}}
                        <input type="hidden" name="product_variant_id" id="pa-variant-id" value="{{ $variant->id ?? '' }}">
                        <input type="hidden" name="addon_group_id"     id="pa-group-id"   value="{{ $group->id ?? '' }}">

                        <div class="card-body border-bottom">
                            <div class="picker-summary-card">
                                <div>
                                    <div class="label">{{ __('labels.product') }}</div>
                                    <div class="value">{{ $variant->product->title ?? '—' }}</div>
                                </div>
                                <div>
                                    <div class="label">{{ __('labels.variant') }}</div>
                                    <div class="value">{{ $variant->title ?? ('#' . $variant->id) }}</div>
                                </div>
                                <div>
                                    <div class="label">{{ __('labels.addon_group') }}</div>
                                    <div class="value">{{ $group->title }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="card-body"
                             data-matrix-base="{{ url('/seller/product-addons/matrix') }}">
                            <div id="pa-matrix-root"></div>
                        </div>
                    @else
                        {{-- Create / multi-attach mode --}}
                        <div class="card-body border-bottom">
                            <div class="row g-3">
                                <div class="col-12">
                                    <h4 class="card-title mb-1">{{ __('labels.product_addon_pick_targets') }}</h4>
                                    <p class="text-muted mb-0">{{ __('labels.product_addon_multi_intro') }}</p>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label required" for="pa-products-multi">
                                        {{ __('labels.product_addon_pick_products') }}
                                    </label>
                                    <select class="form-select" id="pa-products-multi" multiple></select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label required" for="pa-variants-multi">
                                        {{ __('labels.product_addon_pick_variants') }}
                                    </label>
                                    <select class="form-select" id="pa-variants-multi" multiple></select>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label required" for="pa-groups-multi">
                                        {{ __('labels.product_addon_pick_groups') }}
                                    </label>
                                    <select class="form-select" id="pa-groups-multi" multiple></select>
                                </div>
                            </div>
                        </div>

                        <div class="card-body"
                             data-matrix-base="{{ url('/seller/product-addons/matrix') }}"
                             data-lookup-products="{{ route('seller.product-addons.lookup.products') }}"
                             data-lookup-groups="{{ route('seller.product-addons.lookup.addon-groups') }}"
                             data-lookup-variants-bulk="{{ route('seller.product-addons.lookup.variants-bulk') }}"
                             data-apply-label="{{ __('labels.product_addon_apply_to_store') }}"
                             data-no-stores-label="{{ __('labels.product_addon_no_stores') }}"
                             data-empty-state-label="{{ __('labels.product_addon_empty_state_multi') }}">

                            <div id="pa-empty-state" class="text-muted text-center py-5">
                                {{ __('labels.product_addon_empty_state_multi') }}
                            </div>

                            <div id="pa-attachments-root" class="d-none"></div>
                        </div>
                    @endif

                    <div class="card-footer d-flex align-items-center justify-content-between">
                        <a href="{{ route('seller.product-addons.index') }}" class="btn btn-outline-secondary">
                            {{ __('labels.cancel') }}
                        </a>
                        <button type="submit" class="btn btn-primary" id="pa-save-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                 class="icon icon-2">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                <path d="M14 4l0 4l-6 0l0 -4"/>
                                <path d="M5 4h11l3 3v11a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2"/>
                            </svg>
                            {{ __('labels.product_addon_save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Edit mode: server-provided initial payload (consumed by product-addons.js) --}}
    @if($isEdit)
        @php
            $initialPayload = [
                'variant' => [
                    'id'    => $variant->id,
                    'title' => $variant->title ?? ('#' . $variant->id),
                ],
                'group' => [
                    'id'    => $group->id,
                    'title' => $group->title,
                ],
                'stores' => $stores->map(function ($s) {
                    return [
                        'id'    => $s->id,
                        'title' => $s->name ?? ('Store #' . $s->id),
                    ];
                })->values(),
                'items' => $items->map(function ($i) {
                    return [
                        'id'    => $i->id,
                        'title' => $i->title,
                        'price' => (float) $i->price,
                        'cost'  => $i->cost !== null ? (float) $i->cost : null,
                    ];
                })->values(),
                'existing'  => $existing->values(),
                'inventory' => ($inventory ?? collect())->values(),
            ];
        @endphp
        <script id="pa-initial-payload" type="application/json">{!! json_encode($initialPayload) !!}</script>
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/product-addons.js') }}" defer></script>
@endpush
