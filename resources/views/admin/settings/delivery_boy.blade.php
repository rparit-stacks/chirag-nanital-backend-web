@php use App\Enums\PoliciesEnum; @endphp
@extends('layouts.admin.app', ['page' => $menuAdmin['settings']['active'] ?? "", 'sub_page' => $menuAdmin['settings']['route']['delivery_boy']['sub_active'] ?? "" ])

@section('title', __('labels.delivery_boy_settings'))

@section('header_data')
    @php
        $page_title = __('labels.delivery_boy_settings');
        $page_pretitle = __('labels.admin') . " " . __('labels.settings');
    @endphp
@endsection

@php
    $breadcrumbs = [
        ['title' => __('labels.home'), 'url' => route('admin.dashboard')],
        ['title' => __('labels.settings'), 'url' => route('admin.settings.index')],
        ['title' => __('labels.delivery_boy_settings'), 'url' => null],
    ];
@endphp

@section('admin-content')
    <div class="page-header d-print-none">
        <div class="row g-2 align-items-center">
            <div class="col">
                <h2 class="page-title">{{ __('labels.delivery_boy_settings') }}</h2>
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
                            <a class="nav-link" href="#pills-policies">{{ __('labels.delivery_boy_policies') }}</a>
                            <a class="nav-link" href="#pills-refer-earn">{{ __('labels.db_refer_and_earn') }}</a>
                        </nav>
                    </div>
                </div>
                <div class="col-sm" data-bs-spy="scroll" data-bs-target="#pills" data-bs-offset="0">
                    <div class="row row-cards">
                        <div class="col-12">
                            <form action="{{ route('admin.settings.store') }}" class="form-submit" method="post">
                                @csrf
                                <input type="hidden" name="type" value="delivery_boy">
                                <div class="card mb-4" id="pills-policies">
                                    <div class="card-header">
                                        <h4 class="card-title">{{ __('labels.delivery_boy_policies') }}</h4>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label
                                                class="form-label">{{ __('labels.delivery_boy_terms_condition') }}
                                                <a href="{{ route('policies.show', PoliciesEnum::DELIVERY_TERMS()) }}"
                                                   target="_blank">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                         viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                         class="icon icon-tabler icons-tabler-outline icon-tabler-eye">
                                                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                                        <path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/>
                                                        <path
                                                            d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6"/>
                                                    </svg>
                                                </a></label>
                                            <textarea class="hugerte-mytextarea" name="termsCondition" rows="8"
                                                      placeholder="{{ __('labels.delivery_boy_terms_condition_placeholder') }}">{{ $settings['termsCondition'] ?? '' }}</textarea>
                                        </div>
                                        <div class="mb-3">
                                            <label
                                                class="form-label">{{ __('labels.delivery_boy_privacy_policy') }}
                                                <a href="{{ route('policies.show', PoliciesEnum::DELIVERY_PRIVACY()) }}"
                                                   target="_blank">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                         viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                                         class="icon icon-tabler icons-tabler-outline icon-tabler-eye">
                                                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                                        <path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/>
                                                        <path
                                                            d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6"/>
                                                    </svg>
                                                </a></label>
                                            <textarea class="hugerte-mytextarea" name="privacyPolicy" rows="8"
                                                      placeholder="{{ __('labels.delivery_boy_privacy_policy_placeholder') }}">{{ $settings['privacyPolicy'] ?? '' }}</textarea>
                                        </div>
                                    </div>
                                </div>

                                {{-- Refer & Earn card --}}
                                <div class="card mb-4" id="pills-refer-earn">
                                    <div class="card-header">
                                        <h4 class="card-title">{{ __('labels.db_refer_and_earn') }}</h4>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-4">
                                            <label class="row">
                                                <span class="col">
                                                    <span class="form-label">{{ __('labels.refer_earn_status') }}</span>
                                                    <small class="form-hint d-block">{{ __('labels.db_refer_earn_status_hint') }}</small>
                                                </span>
                                                <span class="col-auto">
                                                    <label class="form-check form-check-single form-switch">
                                                        <input class="form-check-input" type="checkbox"
                                                               id="deliveryBoyReferEarnStatus"
                                                               name="deliveryBoyReferEarnStatus"
                                                               value="1"
                                                               {{ (isset($settings['deliveryBoyReferEarnStatus']) && $settings['deliveryBoyReferEarnStatus']) ? 'checked' : '' }}>
                                                    </label>
                                                </span>
                                            </label>
                                        </div>
                                        <div id="db-refer-earn-fields" class="{{ (isset($settings['deliveryBoyReferEarnStatus']) && $settings['deliveryBoyReferEarnStatus']) ? '' : 'd-none' }}">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label" for="deliveryBoyReferEarnBonusReferral">
                                                        {{ __('labels.db_bonus_referral') }}
                                                        <small class="text-muted">({{ __('labels.db_referrer_hint') }})</small>
                                                    </label>
                                                    {{-- @dd(getCurrencySymbol()) --}}
                                                    <div class="input-group">
                                                        <span class="input-group-text">{{ getCurrencySymbol() }}</span>
                                                        <input type="number" step="0.01" min="0"
                                                               class="form-control"
                                                               name="deliveryBoyReferEarnBonusReferral"
                                                               id="deliveryBoyReferEarnBonusReferral"
                                                               value="{{ $settings['deliveryBoyReferEarnBonusReferral'] ?? '0' }}">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label" for="deliveryBoyReferEarnBonusReferee">
                                                        {{ __('labels.db_bonus_referee') }}
                                                        <small class="text-muted">({{ __('labels.db_referee_hint') }})</small>
                                                    </label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">{{ getCurrencySymbol() }}</span>
                                                        <input type="number" step="0.01" min="0"
                                                               class="form-control"
                                                               name="deliveryBoyReferEarnBonusReferee"
                                                               id="deliveryBoyReferEarnBonusReferee"
                                                               value="{{ $settings['deliveryBoyReferEarnBonusReferee'] ?? '0' }}">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="card-footer text-end">
                                    <div class="d-flex">
                                        @can('updateSetting', [\App\Models\Setting::class, 'delivery_boy'])
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

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const toggle = document.getElementById('deliveryBoyReferEarnStatus');
        const fields = document.getElementById('db-refer-earn-fields');
        if (toggle && fields) {
            toggle.addEventListener('change', function () {
                fields.classList.toggle('d-none', !this.checked);
            });
        }
    });
</script>
@endpush
