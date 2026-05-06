@extends('layouts.admin.guest')

@section('title')
    @switch($status ?? 'invalid')
        @case('verified') {{ __('labels.email_verified_successfully') }} @break
        @case('already')  {{ __('labels.email_already_verified') }}    @break
        @default          {{ __('labels.invalid_verification_link') }}
    @endswitch
@endsection

@section('content')
    <div>
        <div class="page page-center">
            <div class="container container-tight py-4">
                <div class="text-center mb-4">
                    <a href="{{ url('/') }}" class="navbar-brand navbar-brand-autodark">
                        @if(!empty($systemSettings['logo']))
                            <img src="{{ $systemSettings['logo'] }}"
                                 alt="{{ $systemSettings['appName'] ?? config('app.name') }}"
                                 width="150px">
                        @else
                            <span class="h1">{{ $systemSettings['appName'] ?? config('app.name') }}</span>
                        @endif
                    </a>
                </div>

                <div class="card card-md">
                    <div class="card-body text-center py-5">
                        @switch($status ?? 'invalid')
                            @case('verified')
                                <div class="mb-3">
                                    <span class="avatar avatar-xl bg-success-lt rounded-circle">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24"
                                             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                             stroke-linejoin="round" class="text-success">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                            <path d="M5 12l5 5l10 -10"/>
                                        </svg>
                                    </span>
                                </div>
                                <h2 class="h2 mb-2">{{ __('labels.email_verified_successfully') }}</h2>
                                <p class="text-secondary mb-0">
                                    {{ __('labels.email_verified_body') }}
                                    @if(!empty($email))
                                        <br><strong class="text-body">{{ $email }}</strong>
                                    @endif
                                </p>
                                @break

                            @case('already')
                                <div class="mb-3">
                                    <span class="avatar avatar-xl bg-success-lt rounded-circle">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24"
                                             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                             stroke-linejoin="round" class="text-success">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                            <path d="M5 12l5 5l10 -10"/>
                                        </svg>
                                    </span>
                                </div>
                                <h2 class="h2 mb-2">{{ __('labels.email_already_verified') }}</h2>
                                @if(!empty($email))
                                    <p class="text-secondary mb-0">
                                        <strong class="text-body">{{ $email }}</strong>
                                    </p>
                                @endif
                                @break

                            @default
                                <div class="mb-3">
                                    <span class="avatar avatar-xl bg-danger-lt rounded-circle">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24"
                                             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                             stroke-linejoin="round" class="text-danger">
                                            <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                                            <circle cx="12" cy="12" r="9"/>
                                            <line x1="12" y1="8" x2="12" y2="12"/>
                                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                                        </svg>
                                    </span>
                                </div>
                                <h2 class="h2 mb-2">{{ __('labels.invalid_verification_link') }}</h2>
                                <p class="text-secondary mb-0">{{ __('labels.invalid_verification_link_body') }}</p>
                        @endswitch
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
