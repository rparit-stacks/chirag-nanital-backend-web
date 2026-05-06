@extends('layouts.seller.app', [
    'page' => $menuSeller['products']['active'] ?? '',
    'sub_page' => $menuSeller['products']['route']['store_addon_items']['sub_active'] ?? '',
])

@section('title', __('labels.bulk_add_addon_items'))

@section('header_data')
    @php
        $page_title = __('labels.bulk_add_addon_items');
        $page_pretitle = __('labels.seller') . ' ' . __('labels.store_addon_items');
    @endphp
@endsection

@php
    $breadcrumbs = [
        ['title' => __('labels.home'), 'url' => route('seller.dashboard')],
        ['title' => __('labels.store_addon_items'), 'url' => route('seller.store-addon-items.index')],
        ['title' => __('labels.bulk_add_addon_items'), 'url' => ''],
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/store-addon-items.css') }}">
@endpush

@section('seller-content')
    <div class="row row-cards">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">{{ __('labels.bulk_add_addon_items') }}</h3>
                        <x-breadcrumb :items="$breadcrumbs"/>
                    </div>
                    <div class="card-actions">
                        <a href="{{ route('seller.store-addon-items.index') }}" class="btn btn-outline-secondary">
                            {{ __('labels.cancel') }}
                        </a>
                    </div>
                </div>

                <form id="sai-bulk-form"
                      class="form-submit"
                      action="{{ route('seller.store-addon-items.bulk-store') }}"
                      method="POST"
                      data-state-matrix-url="{{ route('seller.store-addon-items.state-matrix') }}"
                      data-items-for-group-base="{{ url('/seller/store-addon-items/lookup/groups') }}"
                      data-status-all-label="{{ __('labels.updates_existing_inventory') }}"
                      data-status-some-template="{{ __('labels.store_addon_status_some_template') }}"
                      novalidate>
                    @csrf

                    {{-- Section 1 — Stores -------------------------------------------------- --}}
                    <div class="card-body border-bottom">
                        <div class="row g-3 align-items-start">
                            <div class="col-lg-4">
                                <h4 class="card-title mb-1">{{ __('labels.store_addon_pick_stores') }}</h4>
                                <p class="text-muted mb-0">{{ __('labels.store_addon_pick_stores_help') }}</p>
                            </div>
                            <div class="col-lg-8">
                                @if($stores->isEmpty())
                                    <div class="empty">
                                        <p class="empty-title">{{ __('labels.store_addon_no_stores_title') }}</p>
                                        <p class="empty-subtitle text-muted">
                                            {{ __('labels.store_addon_no_stores_body') }}
                                        </p>
                                        <div class="empty-action">
                                            <a href="{{ route('seller.stores.index') }}" class="btn btn-primary">
                                                {{ __('labels.stores') }}
                                            </a>
                                        </div>
                                    </div>
                                @else
                                    <div class="d-flex justify-content-between mb-2">
                                        <div>
                                            <span class="text-muted small">
                                                <span id="sai-store-count">0</span>
                                                / {{ $stores->count() }}
                                                {{ __('labels.stores_selected') }}
                                            </span>
                                        </div>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-secondary" id="sai-select-all-stores">
                                                {{ __('labels.select_all') }}
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary" id="sai-clear-stores">
                                                {{ __('labels.clear') }}
                                            </button>
                                        </div>
                                    </div>
                                    <div class="sai-stores-grid">
                                        @foreach($stores as $store)
                                            <label class="form-selectgroup-item sai-store-chip">
                                                <input type="checkbox"
                                                       name="store_ids[]"
                                                       value="{{ $store->id }}"
                                                       class="form-selectgroup-input sai-store-checkbox">
                                                <span class="form-selectgroup-label">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                                                         viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                         class="icon me-1">
                                                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                                        <path d="M3 21l18 0"/>
                                                        <path d="M3 7v1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1h-18l2 -4h14l2 4"/>
                                                        <path d="M5 21l0 -10.15"/>
                                                        <path d="M19 21l0 -10.15"/>
                                                        <path d="M9 21v-4a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v4"/>
                                                    </svg>
                                                    {{ $store->name }}
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Section 2 — Addon item rows --------------------------------------- --}}
                    <div class="card-body border-bottom">
                        <div class="row g-3 align-items-start">
                            <div class="col-lg-4">
                                <h4 class="card-title mb-1">{{ __('labels.store_addon_pick_items') }}</h4>
                                <p class="text-muted mb-0">{{ __('labels.store_addon_pick_items_help') }}</p>
                            </div>
                            <div class="col-lg-8">
                                <div id="sai-rows-table" class="sai-rows-wrapper">
                                    <div class="table-responsive">
                                        <table class="table table-vcenter mb-0" id="sai-rows-grid">
                                            <thead>
                                            <tr>
                                                <th style="width: 22%;">{{ __('labels.addon_group') }}</th>
                                                <th style="width: 24%;">{{ __('labels.addon_item') }}</th>
                                                <th style="width: 13%;">{{ __('labels.price') }}</th>
                                                <th style="width: 12%;">{{ __('labels.cost') }}</th>
                                                <th style="width: 10%;">{{ __('labels.stock') }}</th>
                                                <th style="width: 11%;" class="text-center">{{ __('labels.is_available') }}</th>
                                                <th style="width: 8%;" class="text-end"></th>
                                            </tr>
                                            </thead>
                                            <tbody id="sai-rows"></tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm" id="sai-add-row">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                             stroke-linecap="round" stroke-linejoin="round" class="icon me-1">
                                            <path d="M12 5l0 14"/>
                                            <path d="M5 12l14 0"/>
                                        </svg>
                                        {{ __('labels.add_addon_item_row') }}
                                    </button>
                                    <span class="text-muted small"
                                          id="sai-impact-hint"
                                          data-empty-label="{{ __('labels.store_addon_impact_hint_empty') }}"
                                          data-template-label="{{ __('labels.store_addon_impact_hint_template') }}">
                                        {{ __('labels.store_addon_impact_hint_empty') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div class="text-muted small">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                 stroke-linejoin="round" class="icon me-1 text-info">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                <path d="M12 9v4"/>
                                <path d="M12 16v.01"/>
                                <path d="M12 3c7.2 0 9 1.8 9 9s-1.8 9 -9 9s-9 -1.8 -9 -9s1.8 -9 9 -9z"/>
                            </svg>
                            {{ __('labels.store_addon_upsert_footnote') }}
                        </div>
                        <div class="d-flex gap-2">
                            <a href="{{ route('seller.store-addon-items.index') }}" class="btn btn-outline-secondary">
                                {{ __('labels.cancel') }}
                            </a>
                            <button type="submit" class="btn btn-primary" id="sai-save-btn" @if($stores->isEmpty()) disabled @endif>
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                     stroke-linejoin="round" class="icon icon-2">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                    <path d="M14 4l0 4l-6 0l0 -4"/>
                                    <path d="M5 4h11l3 3v11a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2"/>
                                </svg>
                                {{ __('labels.save') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Row template — cloned per addon item row. __INDEX__ is replaced by JS. --}}
    <template id="sai-row-template">
        <tr class="sai-row">
            <td>
                <select class="form-select form-select-sm sai-row-group" aria-label="{{ __('labels.addon_group') }}">
                    <option value="">{{ __('labels.select') }}</option>
                    @foreach($addonGroups as $group)
                        <option value="{{ $group->id }}">{{ $group->title }}</option>
                    @endforeach
                </select>
            </td>
            <td>
                <select class="form-select form-select-sm sai-row-item"
                        name="items[__INDEX__][addon_item_id]"
                        aria-label="{{ __('labels.addon_item') }}">
                    <option value="">{{ __('labels.select') }}</option>
                </select>
                <div class="sai-row-status small mt-1" hidden>
                    <span class="badge bg-info-lt text-info">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                             stroke-linejoin="round" class="me-1">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                            <path d="M12 9v4"/>
                            <path d="M12 16v.01"/>
                            <path d="M12 3c7.2 0 9 1.8 9 9s-1.8 9 -9 9s-9 -1.8 -9 -9s1.8 -9 9 -9z"/>
                        </svg>
                        <span class="sai-row-status-text">{{ __('labels.updates_existing_inventory') }}</span>
                    </span>
                </div>
            </td>
            <td>
                <input type="number" step="0.01" min="0"
                       class="form-control form-control-sm sai-row-price"
                       name="items[__INDEX__][price]"
                       placeholder="0.00"
                       aria-label="{{ __('labels.price') }}">
            </td>
            <td>
                <input type="number" step="0.01" min="0"
                       class="form-control form-control-sm sai-row-cost"
                       name="items[__INDEX__][cost]"
                       placeholder="0.00"
                       aria-label="{{ __('labels.cost') }}">
            </td>
            <td>
                <input type="number" step="1" min="0"
                       class="form-control form-control-sm sai-row-stock"
                       name="items[__INDEX__][stock]"
                       value="0"
                       aria-label="{{ __('labels.stock') }}">
            </td>
            <td class="text-center">
                <label class="form-check form-switch m-0 d-inline-flex align-items-center">
                    <input class="form-check-input sai-row-available" type="checkbox"
                           name="items[__INDEX__][is_available]" value="1" checked
                           aria-label="{{ __('labels.is_available') }}">
                </label>
            </td>
            <td class="text-end">
                <button type="button" class="btn btn-icon btn-outline-danger btn-sm sai-remove-row"
                        title="{{ __('labels.remove') }}"
                        aria-label="{{ __('labels.remove') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                         stroke-linejoin="round">
                        <path d="M4 7l16 0"/>
                        <path d="M10 11l0 6"/>
                        <path d="M14 11l0 6"/>
                        <path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/>
                        <path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3"/>
                    </svg>
                </button>
            </td>
        </tr>
    </template>

    {{-- Empty state row (shown when there are no addon-item rows yet). --}}
    <template id="sai-empty-row">
        <tr class="sai-empty">
            <td colspan="7" class="text-center text-muted py-4">
                <div class="d-flex flex-column align-items-center gap-1">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                         stroke-linejoin="round" class="text-muted">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <path d="M9 11l3 3l8 -8"/>
                        <path d="M20 12v6a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h9"/>
                    </svg>
                    <div>{{ __('labels.store_addon_rows_empty') }}</div>
                </div>
            </td>
        </tr>
    </template>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/store-addon-items-bulk.js') }}" defer></script>
@endpush
