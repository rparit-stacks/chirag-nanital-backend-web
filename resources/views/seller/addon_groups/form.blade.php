@extends('layouts.seller.app', [
    'page' => $menuSeller['products']['active'] ?? '',
    'sub_page' => $menuSeller['products']['route']['addon_groups']['sub_active'] ?? '',
])

@php
    $isEdit = ! empty($group);
    $formTitle = $isEdit
        ? __('labels.addon_group_form_title_edit')
        : __('labels.addon_group_form_title_create');
    $action = $isEdit
        ? route('seller.addon-groups.update', $group->id ?? "")
        : route('seller.addon-groups.store');
    $existingItems = $isEdit ? $group->items : collect();
@endphp

@section('title', $formTitle)

@section('header_data')
    @php
        $page_title = $formTitle;
        $page_pretitle = __('labels.seller') . ' ' . __('labels.addon_groups');
    @endphp
@endsection

@php
    $breadcrumbs = [
        ['title' => __('labels.home'), 'url' => route('seller.dashboard')],
        ['title' => __('labels.addon_groups'), 'url' => route('seller.addon-groups.index')],
        ['title' => $formTitle, 'url' => ''],
    ];
@endphp

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/addon-groups.css') }}">
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
                        <a href="{{ route('seller.addon-groups.index') }}" class="btn btn-outline-secondary">
                            {{ __('labels.cancel') }}
                        </a>
                    </div>
                </div>

                <form id="addon-group-form" action="{{ $action }}" method="POST" class="form-submit" novalidate>
                    @csrf

                    {{-- =================== GROUP BASICS =================== --}}
                    <div class="card-body border-bottom">
                        <div class="row g-3">
                            <div class="col-12">
                                <h4 class="card-title mb-1">{{ __('labels.addon_group_basics') }}</h4>
                                <p class="text-muted mb-0">{{ __('labels.addon_group_intro') }}</p>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label required" for="addon-title">
                                    {{ __('labels.addon_group_title') }}
                                </label>
                                <input type="text" class="form-control" id="addon-title" name="title"
                                       placeholder="{{ __('labels.addon_group_title_placeholder') }}"
                                       value="{{ old('title', $group->title ?? '') }}"
                                       maxlength="255" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label required" for="addon-status">{{ __('labels.status') }}</label>
                                <select class="form-select" id="addon-status" name="status" required>
                                    @foreach($statuses as $status)
                                        <option value="{{ $status->value }}"
                                            @selected(old('status', $group->status?->value ?? 'active') === $status->value)>
                                            {{ ucfirst($status->value) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label required d-block">{{ __('labels.addon_selection_type') }}</label>
                                <div class="row g-2">
                                    @foreach($selectionTypes as $type)
                                        @php
                                            $checked = old('selection_type', $group->selection_type?->value ?? 'single') === $type->value;
                                            $label = $type->value === 'single'
                                                ? __('labels.addon_selection_single')
                                                : __('labels.addon_selection_multiple');
                                        @endphp
                                        <div class="col-md-6">
                                            <label class="selection-type-card {{ $checked ? 'is-selected' : '' }}">
                                                <div class="form-check">
                                                    <input class="form-check-input selection-type-input" type="radio"
                                                           name="selection_type" value="{{ $type->value }}" {{ $checked ? 'checked' : '' }}>
                                                    <span class="form-check-label fw-medium">{{ $label }}</span>
                                                </div>
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-check form-switch mt-3">
                                    <input class="form-check-input" type="checkbox" name="is_required" value="1"
                                        @checked(old('is_required', $group->is_required ?? false))>
                                    <span class="form-check-label">
                                        {{ __('labels.addon_is_required') }}
                                        <span class="d-block text-muted small">{{ __('labels.addon_is_required_help') }}</span>
                                    </span>
                                </label>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label" for="addon-sort-order">{{ __('labels.sort_order') }}</label>
                                <input type="number" min="0" class="form-control" id="addon-sort-order"
                                       name="sort_order" value="{{ old('sort_order', $group->sort_order ?? 0) }}">
                            </div>
                        </div>
                    </div>

                    {{-- =================== ITEMS SECTION =================== --}}
                    <div class="card-body">
                        <div class="d-flex align-items-start justify-content-between mb-3">
                            <div>
                                <h4 class="card-title mb-1">{{ __('labels.addon_group_items_section') }}</h4>
                                <p class="text-muted mb-0">{{ __('labels.addon_group_items_help') }}</p>
                            </div>
                            <button type="button" class="btn btn-outline-primary" id="addon-add-item">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                     class="icon icon-2">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                    <path d="M12 5l0 14"/>
                                    <path d="M5 12l14 0"/>
                                </svg>
                                {{ __('labels.addon_group_add_item') }}
                            </button>
                        </div>

                        <div id="addon-empty-state" class="addon-empty-state mb-3" style="display:none;">
                            {{ __('labels.addon_group_no_items_yet') }}
                        </div>

                        <div id="addon-items-wrapper" class="d-flex flex-column gap-2">
                            @forelse($existingItems as $idx => $item)
                                @include('seller.addon_groups._item_row', [
                                    'index'      => $idx,
                                    'item'       => $item,
                                    'indicators' => $indicators,
                                    'statuses'   => $statuses,
                                ])
                            @empty
                                @include('seller.addon_groups._item_row', [
                                    'index'      => 0,
                                    'item'       => null,
                                    'indicators' => $indicators,
                                    'statuses'   => $statuses,
                                ])
                            @endforelse
                        </div>
                    </div>

                    <div class="card-footer d-flex align-items-center justify-content-between">
                        <a href="{{ route('seller.addon-groups.index') }}" class="btn btn-outline-secondary">
                            {{ __('labels.cancel') }}
                        </a>
                        <button type="submit" class="btn btn-primary" id="addon-save-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                 class="icon icon-2">
                                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                <path d="M14 4l0 4l-6 0l0 -4"/>
                                <path d="M5 4h11l3 3v11a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2"/>
                                <path d="M14 14m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0"/>
                            </svg>
                            {{ __('labels.addon_group_save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Hidden template for new item rows --}}
    <template id="addon-item-template">
        @include('seller.addon_groups._item_row', [
            'index'      => '__INDEX__',
            'item'       => null,
            'indicators' => $indicators,
            'statuses'   => $statuses,
        ])
    </template>
@endsection

@push('scripts')
    <script src="{{ hyperAsset('assets/js/addon-groups.js') }}" defer></script>
@endpush
