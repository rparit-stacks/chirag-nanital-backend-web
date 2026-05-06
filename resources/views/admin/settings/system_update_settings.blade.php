@extends('layouts.admin.app', ['page' => $menuAdmin['settings']['active'] ?? "", 'sub_page' => $menuAdmin['settings']['route']['system_update_settings']['sub_active'] ?? "" ])

@section('title', __('labels.system_update_settings'))

@section('header_data')
    @php
        $page_title = __('labels.system_update_settings');
        $page_pretitle = __('labels.admin') . " " . __('labels.settings');
    @endphp
@endsection

@php
    $breadcrumbs = [
        ['title' => __('labels.home'), 'url' => route('admin.dashboard')],
        ['title' => __('labels.settings'), 'url' => route('admin.settings.index')],
        ['title' => __('labels.system_update_settings'), 'url' => null],
    ];
@endphp

@section('admin-content')
    <div class="page-header d-print-none">
        <div class="row g-2 align-items-center">
            <div class="col">
                <h2 class="page-title">{{ __('labels.system_update_settings') ?? 'System Update Settings' }}</h2>
                <x-breadcrumb :items="$breadcrumbs"/>
            </div>
        </div>
    </div>
    <div class="page-body">
        <div class="container-xl">
            <div class="row g-5">
                <div class="col-sm-2 d-none d-lg-block">
                    <div class="sticky-top">
                        <h3>{{ __('labels.menu') }}</h3>
                        <nav class="nav nav-vertical nav-pills" id="pills">
                            <a class="nav-link" href="#pills-message">{{ __('labels.system_update_messages') }}</a>
                        </nav>
                    </div>
                </div>
                <div class="col-sm" data-bs-spy="scroll" data-bs-target="#pills" data-bs-offset="0">
                    <div class="row row-cards">
                        <div class="col-12">
                            <form action="{{ route('admin.settings.store') }}" class="form-submit" method="post">
                                @csrf
                                <input type="hidden" name="type" value="system_update_settings">

                                <div class="card mb-4" id="pills-message">
                                    <div class="card-header">
                                        <h4 class="card-title">{{ __('labels.system_update_messages') }}</h4>
                                    </div>
                                    <div class="card-body">

                                        <!-- Customer App -->
                                        <h4 class="mt-3 mb-3 text-primary">{{ __('labels.customer_app') }}</h4>

                                        <div class="mb-3">
                                            <label class="form-label">{{ __('labels.force_update_message') }}</label>
                                            <input type="text" class="form-control" name="customerForceUpdateMessage"
                                                   placeholder="{{ __('labels.force_update_message_placeholder') }}"
                                                   value="{{ $settings['customerForceUpdateMessage'] ?? '' }}"/>
                                        </div>

                                        <div class="mb-4">
                                            <label class="form-label">{{ __('labels.soft_update_message') }}</label>
                                            <input type="text" class="form-control" name="customerSoftUpdateMessage"
                                                   placeholder="{{ __('labels.soft_update_message_placeholder') }}"
                                                   value="{{ $settings['customerSoftUpdateMessage'] ?? '' }}"/>
                                        </div>

                                        <!-- Rider App -->
                                        <h4 class="mt-4 mb-3 text-primary">{{ __('labels.rider_app') }}</h4>

                                        <div class="mb-3">
                                            <label class="form-label">{{ __('labels.force_update_message') }}</label>
                                            <input type="text" class="form-control" name="riderForceUpdateMessage"
                                                   placeholder="{{ __('labels.force_update_message_placeholder') }}"
                                                   value="{{ $settings['riderForceUpdateMessage'] ?? '' }}"/>
                                        </div>

                                        <div class="mb-4">
                                            <label class="form-label">{{ __('labels.soft_update_message') }}</label>
                                            <input type="text" class="form-control" name="riderSoftUpdateMessage"
                                                   placeholder="{{ __('labels.soft_update_message_placeholder') }}"
                                                   value="{{ $settings['riderSoftUpdateMessage'] ?? '' }}"/>
                                        </div>

                                        <!-- Seller App -->
                                        <h4 class="mt-4 mb-3 text-primary">{{ __('labels.seller_app') }}</h4>

                                        <div class="mb-3">
                                            <label class="form-label">{{ __('labels.force_update_message') }}</label>
                                            <input type="text" class="form-control" name="sellerForceUpdateMessage"
                                                   placeholder="{{ __('labels.force_update_message_placeholder') }}"
                                                   value="{{ $settings['sellerForceUpdateMessage'] ?? '' }}"/>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">{{ __('labels.soft_update_message') }}</label>
                                            <input type="text" class="form-control" name="sellerSoftUpdateMessage"
                                                   placeholder="{{ __('labels.soft_update_message_placeholder') }}"
                                                   value="{{ $settings['sellerSoftUpdateMessage'] ?? '' }}"/>
                                        </div>

                                        <!-- Customer Web -->
                                        <h4 class="mt-4 mb-3 text-primary">{{ __('labels.customer_web') }}</h4>

                                        <div class="mb-3">
                                            <label class="form-label">{{ __('labels.force_update_message') }}</label>
                                            <input type="text" class="form-control" name="webForceUpdateMessage"
                                                   placeholder="{{ __('labels.force_update_message_placeholder') }}"
                                                   value="{{ $settings['webForceUpdateMessage'] ?? '' }}"/>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">{{ __('labels.soft_update_message') }}</label>
                                            <input type="text" class="form-control" name="webSoftUpdateMessage"
                                                   placeholder="{{ __('labels.soft_update_message_placeholder') }}"
                                                   value="{{ $settings['webSoftUpdateMessage'] ?? '' }}"/>
                                        </div>

                                    </div>
                                </div>
                                <div class="card-footer text-end">
                                    <div class="d-flex">
                                        @can('updateSetting', [\App\Models\Setting::class, 'system_update_settings'])
                                            <button type="submit"
                                                    class="btn btn-primary ms-auto">{{ __('labels.submit') }}</button>
                                        @endcan
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
