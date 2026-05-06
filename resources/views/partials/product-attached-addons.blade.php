@if(!empty($variantAddons))
    <div class="col-12 mt-3">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ __('labels.attached_addons') }}</h3>
            </div>
            <div class="card-body">
                @foreach($variantAddons as $variant)
                    <div class="mb-4">
                        <h4>{{ __('labels.variant_name') . ' : ' . $variant['variant_title'] }}</h4>

                        @foreach($variant['stores'] as $store)
                            <div class="mt-3">
                                <strong>{{ __('labels.store') }}:</strong>
                                <span class="text-capitalize">{{ $store['store_name'] }}</span>
                            </div>

                            @foreach($store['groups'] as $group)
                                <div class="mt-2">
                                    <div class="d-flex align-items-center flex-wrap gap-2">
                                        <span class="fw-medium">{{ $group['group_title'] }}</span>

                                        @if(!empty($group['selection_type']))
                                            <span class="badge bg-blue-lt text-capitalize">{{ $group['selection_type'] }}</span>
                                        @endif

                                        @if($group['is_required'])
                                            <span class="badge bg-red-lt">{{ __('labels.addon_required_badge') }}</span>
                                        @else
                                            <span class="badge bg-secondary-lt">{{ __('labels.addon_optional_badge') }}</span>
                                        @endif
                                    </div>

                                    <div class="table-responsive mt-2">
                                        <table class="table table-vcenter card-table">
                                            <thead>
                                                <tr>
                                                    <th>{{ __('labels.addon_item_title') }}</th>
                                                    <th>{{ __('labels.addon_item_indicator') }}</th>
                                                    <th>{{ __('labels.price') }}</th>
                                                    <th>{{ __('labels.stock') }}</th>
                                                    <th>{{ __('labels.status') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($group['items'] as $item)
                                                    <tr>
                                                        <td>{{ $item['item_title'] }}</td>
                                                        <td>
                                                            @if(!empty($item['indicator']))
                                                                <span class="badge bg-secondary-lt text-capitalize">{{ $item['indicator'] }}</span>
                                                            @else
                                                                <span class="text-muted">—</span>
                                                            @endif
                                                        </td>
                                                        <td>{{ $systemSettings['currencySymbol'] . number_format((float) $item['price'], 2) }}</td>
                                                        <td>
                                                            @if($item['stock'] === null)
                                                                <span class="text-muted">—</span>
                                                            @else
                                                                {{ $item['stock'] }}
                                                            @endif
                                                        </td>
                                                        <td>
                                                            @if($item['is_available'])
                                                                <span class="badge bg-green-lt">{{ __('labels.active') ?? 'Active' }}</span>
                                                            @else
                                                                <span class="badge bg-red-lt">{{ __('labels.inactive') ?? 'Inactive' }}</span>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endforeach
                        @endforeach
                    </div>
                    @if(!$loop->last)
                        <hr>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
@endif
