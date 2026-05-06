@extends('layouts.admin.app', ['page' => $menuAdmin['app_notifications']['active'] ?? ""])

@section('title', __('labels.app_notifications'))

@section('header_data')
    @php
        $page_title = __('labels.app_notifications');
        $page_pretitle = __('labels.list');
    @endphp
@endsection

@php
    $breadcrumbs = [
        ['title' => __('labels.home'), 'url' => route('admin.dashboard')],
        ['title' => __('labels.app_notifications'), 'url' => null],
    ];
@endphp

@section('admin-content')
    <div class="row row-cards">
        @if($createPermission)
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">{{ __('labels.send_notification') }}</h3>
                            <x-breadcrumb :items="$breadcrumbs"/>
                        </div>
                    </div>
                    <div class="card-body">
                        <form class="form-submit" action="{{ route('admin.app-notifications.store') }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            <div class="row">
                                <div class="col-md-6 mb-md-0 mb-3">
                                    <label class="form-label required">{{ __('labels.audience_type') }}</label>
                                    <select class="form-select" name="audience_type" id="audience_type">
                                        @foreach($audienceTypes as $audienceType)
                                            <option
                                                value="{{ $audienceType->value }}">{{ ucfirst($audienceType->value) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6 mb-md-0 mb-3">
                                    <label class="form-label">{{ __('labels.delivery_zones') }}</label>
                                    <input type="text"
                                           class="form-control"
                                           name="zone_ids"
                                           id="select-zones"
                                           multiple
                                           placeholder="{{ __('labels.enter_zone_name') }}"
                                    />
                                    <small class="form-hint">{{ __('labels.leave_empty_for_all_zones') }}</small>
                                </div>
                            </div>
                            <hr class="my-3" />
                            <div class="row">
                                <div class="col-md-6 mb-3" id="target_type_wrapper">
                                    <label class="form-label" for="target_type">{{ __('labels.target_type') }}</label>
                                    <select class="form-select" name="target_type" id="target_type">
                                        <option value="">{{ __('labels.no_attachment') }}</option>
                                        @foreach($targetTypes as $targetType)
                                            <option
                                                value="{{ $targetType->value }}">{{ ucfirst(str_replace('_', ' ', $targetType->value)) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-12 mb-3" id="target_id_wrapper">
                                    <label class="form-label">{{ __('labels.target_reference') }}</label>
                                    <select class="form-select text-capitalize" name="target_id" id="target_id"></select>
                                </div>
                            </div>

                            <div class="row">
                                <div class="mb-3">
                                    <label class="form-label required">{{ __('labels.title') }}</label>
                                    <input type="text" class="form-control" name="title"
                                           placeholder="{{ __('labels.title') }}">
                                </div>

                            </div>

                            <div class="mb-3">
                                <label class="form-label required">{{ __('labels.message') }}</label>
                                <textarea class="form-control" rows="4" name="message"
                                          placeholder="{{ __('labels.message') }}"></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">{{ __('labels.image') }}</label>
                                <input type="file" class="form-control" name="image" accept="image/*">
                                <small class="form-hint">{{ __('labels.optional') }}</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">{{ __('labels.users') }}</label>
                                <input type="text"
                                       class="form-control"
                                       name="user_ids"
                                       id="select-users"
                                       data-type="customer"
                                       multiple
                                       placeholder="{{ __('labels.user_name') }}"
                                /><small class="form-hint">{{ __('labels.leave_empty_for_all_users') }}</small>
                            </div>

                            <div class="text-end">
                                <button type="submit"
                                        class="btn btn-primary">{{ __('labels.send_notification') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif

        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">{{ __('labels.app_notifications') }}</h3>
                    </div>
                    <div class="card-actions">
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
                <div class="card-table">
                    <div class="row w-full p-3">
                        <x-datatable id="app-notifications-table" :columns="$columns"
                                     route="{{ route('admin.app-notifications.datatable') }}"
                                     :options="['order' => [[0, 'desc']],'pageLength' => 10,]"/>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal modal-blur fade" id="viewAppNotificationModal" tabindex="-1" role="dialog" aria-hidden="true"
         data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('labels.notification_details') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('labels.title') }}</label>
                            <div class="form-control-plaintext" id="app-notification-title"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('labels.audience_type') }}</label>
                            <div class="form-control-plaintext" id="app-notification-audience"></div>
                        </div>
                    </div>
                    <div class="mb-3" id="app-notification-image-wrapper" style="display:none;">
                        <label class="form-label">{{ __('labels.image') }}</label>
                        <div>
                            <img id="app-notification-image" src="" alt="{{ __('labels.image') }}" style="max-width: 100%; height: auto;" />
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('labels.target_type') }}</label>
                            <div class="form-control-plaintext" id="app-notification-target"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('labels.created_by') }}</label>
                            <div class="form-control-plaintext" id="app-notification-created-by"></div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('labels.message') }}</label>
                        <div class="form-control-plaintext" id="app-notification-message"
                             style="white-space: pre-wrap;"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('labels.metadata') }}</label>
                        <pre class="bg-light p-3 rounded" id="app-notification-metadata"></pre>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('labels.users') }}</label>
                            <div class="form-control-plaintext" id="app-notification-users"></div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('labels.delivery_zones') }}</label>
                            <div class="form-control-plaintext" id="app-notification-zones"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                            data-bs-dismiss="modal">{{ __('labels.close') }}</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ hyperAsset('assets/js/app-notification.js') }}" defer></script>
@endpush
