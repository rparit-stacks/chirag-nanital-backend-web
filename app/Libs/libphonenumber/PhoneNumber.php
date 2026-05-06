<?php

namespace App\Libs\libphonenumber;

/**
 * Value object returned by {@see PhoneNumberUtil::parse()}. Mirrors the
 * shape of `\libphonenumber\PhoneNumber` from `giggsey/libphonenumber-for-php`
 * for the surface this codebase relies on (country code + national number).
 *
 * Shipped in-repo under `app/Libs/libphonenumber/` so deployments that can
 * not run `composer install` on the customer still get the dependency.
 */
class PhoneNumber
{
    private int $countryCode = 0;

    private string $nationalNumber = '';

    private string $rawInput = '';

    public function setCountryCode(int $countryCode): self
    {
        $this->countryCode = $countryCode;

        return $this;
    }

    public function setNationalNumber(string $nationalNumber): self
    {
        $this->nationalNumber = $nationalNumber;

        return $this;
    }

    public function setRawInput(string $rawInput): self
    {
        $this->rawInput = $rawInput;

        return $this;
    }

    /** Calling code without the leading '+', e.g. 91 for India. */
    public function getCountryCode(): int
    {
        return $this->countryCode;
    }

    /** Country code including the leading '+', e.g. "+91". */
    public function getCountryCodeWithPlus(): string
    {
        return '+' . $this->countryCode;
    }

    /** National (subscriber) number as a digit-only string, no leading zeros stripped. */
    public function getNationalNumber(): string
    {
        return $this->nationalNumber;
    }

    public function getRawInput(): string
    {
        return $this->rawInput;
    }

    /** E.164 representation: "+<countryCode><nationalNumber>". */
    public function format(): string
    {
        return '+' . $this->countryCode . $this->nationalNumber;
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
