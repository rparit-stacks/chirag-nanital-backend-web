<?php

namespace App\Libs\libphonenumber;

use RuntimeException;

/**
 * Thrown by {@see PhoneNumberUtil::parse()} when the input can not be
 * interpreted as a phone number. Mirrors the error-type constants exposed
 * by the upstream `giggsey/libphonenumber-for-php` package so callers that
 * `catch (NumberParseException $e)` behave identically.
 */
class NumberParseException extends RuntimeException
{
    public const INVALID_COUNTRY_CODE = 1;

    public const NOT_A_NUMBER = 2;

    public const TOO_SHORT_AFTER_IDD = 3;

    public const TOO_SHORT_NSN = 4;

    public const TOO_LONG = 5;

    private int $errorType;

    public function __construct(int $errorType, string $message)
    {
        parent::__construct($message);

        $this->errorType = $errorType;
    }

    public function getErrorType(): int
    {
        return $this->errorType;
    }
}
