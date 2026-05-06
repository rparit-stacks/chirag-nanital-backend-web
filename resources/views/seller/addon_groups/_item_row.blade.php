@php
    /** @var int|string $index */
    /** @var \App\Models\AddonItem|null $item */
    /** @var array $indicators */
    /** @var array $statuses */
    $idValue          = $item->id ?? '';
    $titleValue       = $item->title ?? '';
    $priceValue       = $item->price ?? '';
    $costValue        = $item->cost ?? '';
    $indicatorValue   = $item?->indicator?->value ?? '';
    $availableValue   = $item ? (bool) $item->is_available : true;
    $sortOrderValue   = $item->sort_order ?? '';
    $statusValue      = $item?->status?->value ?? 'active';
@endphp

<div class="addon-item-row card card-sm">
    <div class="card-body py-3">
        <div class="row g-3 align-items-end">
            <div class="col-auto">
                <span class="addon-item-handle">{{ is_numeric($index) ? ($index + 1) : '#' }}</span>
            </div>

            <div class="col-12 col-md">
                <label class="form-label small text-muted mb-1">{{ __('labels.addon_item_title') }}</label>
                <input type="hidden" name="items[{{ $index }}][id]" value="{{ $idValue }}">
                <input type="text" class="form-control" name="items[{{ $index }}][title]"
                       placeholder="{{ __('labels.addon_item_title_placeholder') }}"
                       value="{{ $titleValue }}" maxlength="255" required>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">{{ __('labels.price') }}</label>
                <div class="input-group input-group-flat">
                    <input type="number" step="0.01" min="0" class="form-control"
                           name="items[{{ $index }}][price]" value="{{ $priceValue }}" required>
                </div>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">{{ __('labels.cost') }}</label>
                <input type="number" step="0.01" min="0" class="form-control"
                       name="items[{{ $index }}][cost]" value="{{ $costValue }}">
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">{{ __('labels.addon_item_indicator') }}</label>
                <select class="form-select" name="items[{{ $index }}][indicator]">
                    <option value="">—</option>
                    @foreach($indicators as $indicator)
                        @php
                            $label = $indicator->value === 'veg'
                                ? __('labels.addon_item_indicator_veg')
                                : __('labels.addon_item_indicator_non_veg');
                        @endphp
                        <option value="{{ $indicator->value }}" @selected($indicatorValue === $indicator->value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">{{ __('labels.status') }}</label>
                <select class="form-select" name="items[{{ $index }}][status]" required>
                    @foreach($statuses as $status)
                        <option value="{{ $status->value }}" @selected($statusValue === $status->value)>
                            {{ ucfirst($status->value) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-md-auto">
                <label class="form-check form-switch mt-md-4 mb-0">
                    <input class="form-check-input" type="checkbox"
                           name="items[{{ $index }}][is_available]" value="1" @checked($availableValue)>
                    <span class="form-check-label">{{ __('labels.addon_item_availability') }}</span>
                </label>
            </div>

            <div class="col-auto ms-auto">
                <button type="button" class="btn btn-icon btn-outline-danger addon-item-remove"
                        title="{{ __('labels.remove') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <path d="M4 7l16 0"/>
                        <path d="M10 11l0 6"/>
                        <path d="M14 11l0 6"/>
                        <path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12"/>
                        <path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3"/>
                    </svg>
                </button>
            </div>

            <input type="hidden" name="items[{{ $index }}][sort_order]" value="{{ $sortOrderValue !== '' ? $sortOrderValue : (is_numeric($index) ? $index : 0) }}">
        </div>
    </div>
</div>
