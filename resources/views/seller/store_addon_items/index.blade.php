@extends('layouts.seller.app', [
    'page' => $menuSeller['products']['active'] ?? '',
    'sub_page' => $menuSeller['products']['route']['store_addon_items']['sub_active'] ?? '',
])
@section('title', __('labels.store_addon_items'))

@section('header_data')
    @php
        $page_title = __('labels.store_addon_items');
        $page_pretitle = __('labels.seller') . " " . __('labels.store_addon_items');
    @endphp
@endsection

@php
    $breadcrumbs = [
        ['title' => __('labels.home'), 'url' => route('seller.dashboard')],
        ['title' => __('labels.store_addon_items'), 'url' => ''],
    ];
@endphp

@section('seller-content')
    <div class="row row-cards">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">{{ __('labels.store_addon_items') }}</h3>
                        <x-breadcrumb :items="$breadcrumbs"/>
                    </div>
                    <div class="card-actions">
                        <div class="row g-2">
                            @if($createPermission ?? false)
                                <div class="col-auto">
                                    <a href="{{ route('seller.store-addon-items.create') }}"
                                       class="btn btn-outline-primary">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                             stroke-linecap="round" stroke-linejoin="round" class="icon icon-2">
                                            <path d="M12 5l0 14"/>
                                            <path d="M5 12l14 0"/>
                                        </svg>
                                        {{ __('labels.store_addon_item_create') }}
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

                <div class="card-body border-bottom py-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label mb-1">{{ __('labels.addon_group_filter') }}</label>
                            <select id="filter-addon-group" class="form-select">
                                <option value="">{{ __('labels.all') }}</option>
                                @foreach($addonGroups as $group)
                                    <option value="{{ $group->id }}">{{ $group->title }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label mb-1">{{ __('labels.store_filter') }}</label>
                            <select id="filter-store" class="form-select">
                                <option value="">{{ __('labels.all') }}</option>
                                @foreach($stores as $store)
                                    <option value="{{ $store->id }}">{{ $store->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="button" id="reset-filters" class="btn btn-outline-secondary w-100">
                                {{ __('labels.reset') }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card-table">
                    <div class="row w-full p-3">
                        <x-datatable id="store-addon-items-table" :columns="$columns"
                                     route="{{ route('seller.store-addon-items.datatable') }}"
                                     :options="['order' => [[0, 'desc']],'pageLength' => 10,]"/>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($editPermission ?? false)
        {{-- Single-row edit modal (reused by row Edit buttons). --}}
        <div class="modal modal-blur fade"
             id="store-addon-item-modal"
             tabindex="-1"
             role="dialog"
             aria-hidden="true"
             data-bs-backdrop="static">
            <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                <div class="modal-content">
                    <form class="form-submit"
                          action="{{ route('seller.store-addon-items.store') }}"
                          method="POST">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title">{{ __('labels.store_addon_item_edit') }}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">{{ __('labels.store') }}</label>
                                    <select class="form-select" name="store_id" id="store-select">
                                        <option value="">{{ __('labels.select') }}</option>
                                        @foreach($stores as $store)
                                            <option value="{{ $store->id }}">{{ $store->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label required">{{ __('labels.addon_group') }}</label>
                                    <select class="form-select" id="addon-group-select">
                                        <option value="">{{ __('labels.select') }}</option>
                                        @foreach($addonGroups as $group)
                                            <option value="{{ $group->id }}">{{ $group->title }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label required">{{ __('labels.addon_item') }}</label>
                                    <select class="form-select" name="addon_item_id" id="addon-item-select">
                                        <option value="">{{ __('labels.select') }}</option>
                                    </select>
                                </div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label required">{{ __('labels.price') }}</label>
                                    <input type="number" step="0.01" min="0" class="form-control"
                                           name="price" placeholder="0.00">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">{{ __('labels.cost') }}</label>
                                    <input type="number" step="0.01" min="0" class="form-control"
                                           name="cost" placeholder="0.00">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">{{ __('labels.stock') }}</label>
                                    <input type="number" step="1" min="0" class="form-control"
                                           name="stock" value="0">
                                </div>
                                <div class="col-md-6 mb-3 form-check form-switch ms-2">
                                    <input class="form-check-input" type="checkbox" name="is_available"
                                           id="is-available-switch" value="1" checked>
                                    <label class="form-check-label" for="is-available-switch">
                                        {{ __('labels.is_available') }}
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <a href="#" class="btn" data-bs-dismiss="modal">{{ __('labels.cancel') }}</a>
                            <button type="submit" class="btn btn-primary">
                                {{ __('labels.save') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

@endsection

@push('scripts')
    <script src="{{ asset('assets/js/store-addon-items.js') }}" defer></script>
@endpush
