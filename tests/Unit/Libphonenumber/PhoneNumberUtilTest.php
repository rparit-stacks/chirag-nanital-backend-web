<?php

use App\Libs\libphonenumber\NumberParseException;
use App\Libs\libphonenumber\PhoneNumberUtil;

beforeEach(function () {
    // Singleton: reset so each test starts from a clean slate.
    PhoneNumberUtil::resetInstance();
});

it('returns the same singleton on repeated getInstance() calls', function () {
    $a = PhoneNumberUtil::getInstance();
    $b = PhoneNumberUtil::getInstance();

    expect($a)->toBe($b);
});

it('parses a 2-digit calling code (India, +91)', function () {
    $p = PhoneNumberUtil::getInstance()->parse('+919876543210');

    expect($p->getCountryCode())->toBe(91)
        ->and($p->getNationalNumber())->toBe('9876543210')
        ->and($p->format())->toBe('+919876543210');
});

it('parses a 1-digit calling code (US/NANP, +1)', function () {
    $p = PhoneNumberUtil::getInstance()->parse('+14155552671');

    expect($p->getCountryCode())->toBe(1)
        ->and($p->getNationalNumber())->toBe('4155552671');
});

it('parses a 1-digit calling code (Russia/Kazakhstan, +7)', function () {
    $p = PhoneNumberUtil::getInstance()->parse('+79261234567');

    expect($p->getCountryCode())->toBe(7)
        ->and($p->getNationalNumber())->toBe('9261234567');
});

it('parses a 3-digit calling code (Morocco, +212)', function () {
    $p = PhoneNumberUtil::getInstance()->parse('+212661234567');

    expect($p->getCountryCode())->toBe(212)
        ->and($p->getNationalNumber())->toBe('661234567');
});

it('parses a 3-digit calling code (UAE, +971)', function () {
    $p = PhoneNumberUtil::getInstance()->parse('+971501234567');

    expect($p->getCountryCode())->toBe(971)
        ->and($p->getNationalNumber())->toBe('501234567');
});

it('accepts the 00 international prefix as an alternative to +', function () {
    $p = PhoneNumberUtil::getInstance()->parse('0044 7700 900123');

    expect($p->getCountryCode())->toBe(44)
        ->and($p->getNationalNumber())->toBe('7700900123');
});

it('strips formatting punctuation before parsing', function () {
    $p = PhoneNumberUtil::getInstance()->parse('+1 (415) 555-2671');

    expect($p->getCountryCode())->toBe(1)
        ->and($p->getNationalNumber())->toBe('4155552671');
});

it('uses the default region when no plus sign is present', function () {
    $p = PhoneNumberUtil::getInstance()->parse('9876543210', 'IN');

    expect($p->getCountryCode())->toBe(91)
        ->and($p->getNationalNumber())->toBe('9876543210');
});

it('falls back to greedy split when no plus and no region hint', function () {
    $p = PhoneNumberUtil::getInstance()->parse('919876543210');

    expect($p->getCountryCode())->toBe(91)
        ->and($p->getNationalNumber())->toBe('9876543210');
});

it('exposes getCountryCodeWithPlus for convenience', function () {
    $p = PhoneNumberUtil::getInstance()->parse('+919876543210');

    expect($p->getCountryCodeWithPlus())->toBe('+91');
});

it('resolves region → calling code via getCountryCodeForRegion', function () {
    $util = PhoneNumberUtil::getInstance();

    expect($util->getCountryCodeForRegion('IN'))->toBe(91)
        ->and($util->getCountryCodeForRegion('us'))->toBe(1)
        ->and($util->getCountryCodeForRegion('GB'))->toBe(44)
        ->and($util->getCountryCodeForRegion('ZZ'))->toBe(0);
});

it('reports a parsed E.164 number as valid', function () {
    $util = PhoneNumberUtil::getInstance();
    $p = $util->parse('+919876543210');

    expect($util->isValidNumber($p))->toBeTrue();
});

it('throws NumberParseException for empty input', function () {
    PhoneNumberUtil::getInstance()->parse('   ');
})->throws(NumberParseException::class);

it('throws NumberParseException for non-digit input', function () {
    PhoneNumberUtil::getInstance()->parse('not-a-number');
})->throws(NumberParseException::class);

it('throws NumberParseException for an unknown calling code', function () {
    // 999 is not an assigned E.164 country calling code.
    PhoneNumberUtil::getInstance()->parse('+9991234567');
})->throws(NumberParseException::class, 'Country calling code not recognized');

it('throws NumberParseException when the national number is too short', function () {
    // "+91" leaves no digits after the calling code.
    PhoneNumberUtil::getInstance()->parse('+91');
})->throws(NumberParseException::class);
