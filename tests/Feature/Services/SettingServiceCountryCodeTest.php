<?php

use App\Models\Country;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the phonecode of the first country matching the currency', function () {
    Country::create([
        'name' => 'India',
        'iso3' => 'IND',
        'iso2' => 'IN',
        'phonecode' => '91',
        'currency' => 'INR',
        'currency_symbol' => '₹',
    ]);

    $service = app(SettingService::class);

    expect($service->resolveCountryCodeFromCurrency('INR'))->toBe('91');
});

it('picks the first matching country when currency maps to many', function () {
    Country::create([
        'name' => 'United States',
        'iso3' => 'USA',
        'iso2' => 'US',
        'phonecode' => '1',
        'currency' => 'USD',
        'currency_symbol' => '$',
    ]);
    Country::create([
        'name' => 'Ecuador',
        'iso3' => 'ECU',
        'iso2' => 'EC',
        'phonecode' => '593',
        'currency' => 'USD',
        'currency_symbol' => '$',
    ]);

    expect(app(SettingService::class)->resolveCountryCodeFromCurrency('USD'))->toBe('1');
});

it('returns empty string when currency is missing or unknown', function () {
    $service = app(SettingService::class);

    expect($service->resolveCountryCodeFromCurrency(null))->toBe('')
        ->and($service->resolveCountryCodeFromCurrency(''))->toBe('')
        ->and($service->resolveCountryCodeFromCurrency('ZZZ'))->toBe('');
});
