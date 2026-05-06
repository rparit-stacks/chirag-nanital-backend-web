@extends('vendor.installer.layouts.master')

@section('title', trans('installer_messages.requirements.title'))
@section('container')
    <h4>{{ trans('installer_messages.requirements.php') }}</h4>
    <ul class="list">
        <li class="list__item {{ $phpSupportInfo['supported'] ? 'success' : 'error' }}">PHP Version >= {{ $phpSupportInfo['minimum'] }}</li>

        @foreach($requirements['requirements'] as $extention => $enabled)
            <li class="list__item {{ $enabled ? 'success' : 'error' }}">{{ $extention }}</li>
        @endforeach
    </ul>

    @if (! empty($functions['requirements']))
        <h4>{{ trans('installer_messages.requirements.functions') }}</h4>
        <ul class="list">
            @foreach($functions['requirements'] as $function => $enabled)
                <li class="list__item {{ $enabled ? 'success' : 'error' }}">{{ $function }}()</li>
            @endforeach
        </ul>
    @endif

    @if (! empty($iniSettings['requirements']))
        <h4>{{ trans('installer_messages.requirements.ini') }}</h4>
        <ul class="list">
            @foreach($iniSettings['requirements'] as $directive => $info)
                @php $isMin = ($info['operator'] ?? '=') === '>='; @endphp
                <li class="list__item {{ $info['passed'] ? 'success' : 'error' }}">
                    {{ $directive }}
                    <small>
                        ({{ $isMin ? trans('installer_messages.requirements.minimum') : trans('installer_messages.requirements.expected') }}:
                        {{ $isMin ? '≥ ' : '' }}{{ $info['expected'] }},
                        {{ trans('installer_messages.requirements.current') }}: {{ $info['current'] !== '' ? $info['current'] : '—' }})
                    </small>
                </li>
            @endforeach
        </ul>
    @endif

    @php
        $allPassed = ! isset($requirements['errors'])
            && ! isset($functions['errors'])
            && ! isset($iniSettings['errors'])
            && $phpSupportInfo['supported'];
    @endphp

    <div class="buttons">
        @if ($allPassed)
            <a class="btn btn-primary" href="{{ route('LaravelInstaller::permissions') }}">
                {{ trans('installer_messages.next') }}
            </a>
        @else
            <a class="btn btn-primary" href="{{ route('LaravelInstaller::requirements') }}">
                {{ trans('installer_messages.requirements.checkAgain') }}
            </a>
        @endif
    </div>
@stop
