<?php

namespace App\Libs\libphonenumber;

/**
 * In-repo, minimal substitute for `giggsey/libphonenumber-for-php`'s
 * {@see \App\Libs\libphonenumber\PhoneNumberUtil}. Lives under `app/Libs/libphonenumber/`
 * so customers running prebuilt packages (no `composer install` on deploy)
 * can still split E.164 numbers into country code + national number.
 *
 * The API surface intentionally mirrors the upstream package so a future
 * swap to the real composer dependency requires zero caller changes:
 *
 *     $util   = \libphonenumber\PhoneNumberUtil::getInstance();
 *     $proto  = $util->parse('+919876543210');
 *     $proto->getCountryCode();     // 91
 *     $proto->getNationalNumber();  // "9876543210"
 *
 * Scope: E.164 splitting + light validation. Formatting, carrier lookup,
 * geocoding, and short-code parsing from the upstream package are NOT
 * implemented — add them only when a concrete caller needs them.
 */
class PhoneNumberUtil
{
    /** Per-process singleton, matching the upstream API. */
    private static ?self $instance = null;

    /**
     * Every ITU-T E.164 country calling code, grouped by prefix length.
     * 1-digit codes never collide with 2- or 3-digit codes (no 2-digit starts
     * with '1' or '7'), so a greedy longest-prefix match is safe without
     * carrier metadata.
     *
     * Source: ITU-T E.164 / libphonenumber country-code table.
     */
    private const CALLING_CODES_1 = [1, 7];

    private const CALLING_CODES_2 = [
        20, 27,
        30, 31, 32, 33, 34, 36, 39,
        40, 41, 43, 44, 45, 46, 47, 48, 49,
        51, 52, 53, 54, 55, 56, 57, 58,
        60, 61, 62, 63, 64, 65, 66,
        81, 82, 84, 86,
        90, 91, 92, 93, 94, 95, 98,
    ];

    private const CALLING_CODES_3 = [
        211, 212, 213, 216, 218,
        220, 221, 222, 223, 224, 225, 226, 227, 228, 229,
        230, 231, 232, 233, 234, 235, 236, 237, 238, 239,
        240, 241, 242, 243, 244, 245, 246, 247, 248, 249,
        250, 251, 252, 253, 254, 255, 256, 257, 258,
        260, 261, 262, 263, 264, 265, 266, 267, 268, 269,
        290, 291, 297, 298, 299,
        350, 351, 352, 353, 354, 355, 356, 357, 358, 359,
        370, 371, 372, 373, 374, 375, 376, 377, 378, 379,
        380, 381, 382, 383, 385, 386, 387, 389,
        420, 421, 423,
        500, 501, 502, 503, 504, 505, 506, 507, 508, 509,
        590, 591, 592, 593, 594, 595, 596, 597, 598, 599,
        670, 672, 673, 674, 675, 676, 677, 678, 679,
        680, 681, 682, 683, 685, 686, 687, 688, 689,
        690, 691, 692,
        800, 808, 850, 852, 853, 855, 856, 870, 878, 880, 881, 882, 883, 886, 888,
        960, 961, 962, 963, 964, 965, 966, 967, 968,
        970, 971, 972, 973, 974, 975, 976, 977, 979,
        992, 993, 994, 995, 996, 998,
    ];

    /**
     * ISO-3166 alpha-2 region → calling code. Used when the caller passes a
     * `$defaultRegion` and the raw number has no leading '+'.
     *
     * Intentionally broad (not exhaustive to every territory). Add entries
     * here when a new region is needed rather than inventing constants.
     */
    private const REGION_TO_CALLING_CODE = [
        'AD' => 376, 'AE' => 971, 'AF' => 93,  'AG' => 1,   'AI' => 1,
        'AL' => 355, 'AM' => 374, 'AO' => 244, 'AR' => 54,  'AS' => 1,
        'AT' => 43,  'AU' => 61,  'AW' => 297, 'AX' => 358, 'AZ' => 994,
        'BA' => 387, 'BB' => 1,   'BD' => 880, 'BE' => 32,  'BF' => 226,
        'BG' => 359, 'BH' => 973, 'BI' => 257, 'BJ' => 229, 'BL' => 590,
        'BM' => 1,   'BN' => 673, 'BO' => 591, 'BQ' => 599, 'BR' => 55,
        'BS' => 1,   'BT' => 975, 'BW' => 267, 'BY' => 375, 'BZ' => 501,
        'CA' => 1,   'CC' => 61,  'CD' => 243, 'CF' => 236, 'CG' => 242,
        'CH' => 41,  'CI' => 225, 'CK' => 682, 'CL' => 56,  'CM' => 237,
        'CN' => 86,  'CO' => 57,  'CR' => 506, 'CU' => 53,  'CV' => 238,
        'CW' => 599, 'CX' => 61,  'CY' => 357, 'CZ' => 420,
        'DE' => 49,  'DJ' => 253, 'DK' => 45,  'DM' => 1,   'DO' => 1,
        'DZ' => 213,
        'EC' => 593, 'EE' => 372, 'EG' => 20,  'EH' => 212, 'ER' => 291,
        'ES' => 34,  'ET' => 251,
        'FI' => 358, 'FJ' => 679, 'FK' => 500, 'FM' => 691, 'FO' => 298,
        'FR' => 33,
        'GA' => 241, 'GB' => 44,  'GD' => 1,   'GE' => 995, 'GF' => 594,
        'GG' => 44,  'GH' => 233, 'GI' => 350, 'GL' => 299, 'GM' => 220,
        'GN' => 224, 'GP' => 590, 'GQ' => 240, 'GR' => 30,  'GT' => 502,
        'GU' => 1,   'GW' => 245, 'GY' => 592,
        'HK' => 852, 'HN' => 504, 'HR' => 385, 'HT' => 509, 'HU' => 36,
        'ID' => 62,  'IE' => 353, 'IL' => 972, 'IM' => 44,  'IN' => 91,
        'IO' => 246, 'IQ' => 964, 'IR' => 98,  'IS' => 354, 'IT' => 39,
        'JE' => 44,  'JM' => 1,   'JO' => 962, 'JP' => 81,
        'KE' => 254, 'KG' => 996, 'KH' => 855, 'KI' => 686, 'KM' => 269,
        'KN' => 1,   'KP' => 850, 'KR' => 82,  'KW' => 965, 'KY' => 1,
        'KZ' => 7,
        'LA' => 856, 'LB' => 961, 'LC' => 1,   'LI' => 423, 'LK' => 94,
        'LR' => 231, 'LS' => 266, 'LT' => 370, 'LU' => 352, 'LV' => 371,
        'LY' => 218,
        'MA' => 212, 'MC' => 377, 'MD' => 373, 'ME' => 382, 'MF' => 590,
        'MG' => 261, 'MH' => 692, 'MK' => 389, 'ML' => 223, 'MM' => 95,
        'MN' => 976, 'MO' => 853, 'MP' => 1,   'MQ' => 596, 'MR' => 222,
        'MS' => 1,   'MT' => 356, 'MU' => 230, 'MV' => 960, 'MW' => 265,
        'MX' => 52,  'MY' => 60,  'MZ' => 258,
        'NA' => 264, 'NC' => 687, 'NE' => 227, 'NF' => 672, 'NG' => 234,
        'NI' => 505, 'NL' => 31,  'NO' => 47,  'NP' => 977, 'NR' => 674,
        'NU' => 683, 'NZ' => 64,
        'OM' => 968,
        'PA' => 507, 'PE' => 51,  'PF' => 689, 'PG' => 675, 'PH' => 63,
        'PK' => 92,  'PL' => 48,  'PM' => 508, 'PR' => 1,   'PS' => 970,
        'PT' => 351, 'PW' => 680, 'PY' => 595,
        'QA' => 974,
        'RE' => 262, 'RO' => 40,  'RS' => 381, 'RU' => 7,   'RW' => 250,
        'SA' => 966, 'SB' => 677, 'SC' => 248, 'SD' => 249, 'SE' => 46,
        'SG' => 65,  'SH' => 290, 'SI' => 386, 'SJ' => 47,  'SK' => 421,
        'SL' => 232, 'SM' => 378, 'SN' => 221, 'SO' => 252, 'SR' => 597,
        'SS' => 211, 'ST' => 239, 'SV' => 503, 'SX' => 1,   'SY' => 963,
        'SZ' => 268,
        'TC' => 1,   'TD' => 235, 'TG' => 228, 'TH' => 66,  'TJ' => 992,
        'TK' => 690, 'TL' => 670, 'TM' => 993, 'TN' => 216, 'TO' => 676,
        'TR' => 90,  'TT' => 1,   'TV' => 688, 'TW' => 886, 'TZ' => 255,
        'UA' => 380, 'UG' => 256, 'US' => 1,   'UY' => 598, 'UZ' => 998,
        'VA' => 379, 'VC' => 1,   'VE' => 58,  'VG' => 1,   'VI' => 1,
        'VN' => 84,  'VU' => 678,
        'WF' => 681, 'WS' => 685,
        'XK' => 383,
        'YE' => 967, 'YT' => 262,
        'ZA' => 27,  'ZM' => 260, 'ZW' => 263,
    ];

    /** Min/max digits in an E.164 national number, per the ITU spec. */
    private const MIN_LENGTH_FOR_NSN = 2;

    private const MAX_LENGTH_FOR_NSN = 17;

    /** Total E.164 digits (country code + NSN) capped at 15 per spec. */
    private const MAX_LENGTH_COUNTRY_CODE = 3;

    private const MAX_LENGTH_E164 = 15;

    private function __construct()
    {
    }

    /** Process-wide singleton — matches upstream usage pattern. */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /** Reset the singleton — only intended for tests. */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Parse a phone number string into a {@see PhoneNumber} value object.
     *
     * Accepts:
     *  - E.164 with leading '+', e.g. "+919876543210".
     *  - Digits-only with a `$defaultRegion` hint, e.g. ("9876543210", "IN").
     *  - Human-punctuated input — any non-digit (other than the optional
     *    leading '+') is stripped before parsing.
     *
     * @throws NumberParseException on empty/short/long/unknown-calling-code input.
     */
    public function parse(string $numberToParse, ?string $defaultRegion = null): PhoneNumber
    {
        $rawInput = $numberToParse;
        $trimmed = trim($numberToParse);

        if ($trimmed === '') {
            throw new NumberParseException(
                NumberParseException::NOT_A_NUMBER,
                'The phone number supplied was empty.'
            );
        }

        $hasPlus = str_starts_with($trimmed, '+') || str_starts_with($trimmed, '00');
        // Normalize "00" international prefix into a '+' so both forms parse.
        if (str_starts_with($trimmed, '00')) {
            $trimmed = '+' . substr($trimmed, 2);
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '' || ! ctype_digit($digits)) {
            throw new NumberParseException(
                NumberParseException::NOT_A_NUMBER,
                'The string supplied did not seem to be a phone number.'
            );
        }

        if ($hasPlus) {
            [$callingCode, $nationalNumber] = $this->splitByCallingCode($digits);
        } else {
            $region = $defaultRegion !== null ? strtoupper($defaultRegion) : null;
            if ($region !== null && $region !== 'ZZ' && isset(self::REGION_TO_CALLING_CODE[$region])) {
                $callingCode = self::REGION_TO_CALLING_CODE[$region];
                $nationalNumber = $digits;
            } else {
                // No '+' and no usable region hint — still attempt a greedy
                // split so callers that pass international-formatted digits
                // (e.g. "919876543210") get a sensible result.
                [$callingCode, $nationalNumber] = $this->splitByCallingCode($digits);
            }
        }

        if (strlen($nationalNumber) < self::MIN_LENGTH_FOR_NSN) {
            throw new NumberParseException(
                NumberParseException::TOO_SHORT_NSN,
                'The string supplied is too short to be a phone number.'
            );
        }
        if (strlen($nationalNumber) > self::MAX_LENGTH_FOR_NSN) {
            throw new NumberParseException(
                NumberParseException::TOO_LONG,
                'The string supplied is too long to be a phone number.'
            );
        }

        return (new PhoneNumber())
            ->setCountryCode($callingCode)
            ->setNationalNumber($nationalNumber)
            ->setRawInput($rawInput);
    }

    /**
     * Best-effort validity check: total E.164 length between 4 and 15 digits,
     * national number within per-spec bounds. Intentionally conservative — it
     * does NOT consult per-country numbering plans (that requires the full
     * upstream metadata bundle).
     */
    public function isValidNumber(PhoneNumber $number): bool
    {
        $cc = (string) $number->getCountryCode();
        $nsn = $number->getNationalNumber();

        if ($cc === '0' || $cc === '') {
            return false;
        }
        if (strlen($nsn) < self::MIN_LENGTH_FOR_NSN) {
            return false;
        }

        $total = strlen($cc) + strlen($nsn);

        return $total >= 4 && $total <= self::MAX_LENGTH_E164;
    }

    /** Exposed for callers that know only the region and want the dialing code. */
    public function getCountryCodeForRegion(string $region): int
    {
        return self::REGION_TO_CALLING_CODE[strtoupper($region)] ?? 0;
    }

    /**
     * Greedy longest-prefix country-code split. Valid 1-digit codes (1, 7)
     * never overlap with any 2- or 3-digit code, so the check order is
     * deterministic.
     *
     * @return array{0:int,1:string}  [calling code, national number]
     */
    private function splitByCallingCode(string $digits): array
    {
        $d1 = (int) substr($digits, 0, 1);
        if (in_array($d1, self::CALLING_CODES_1, true)) {
            return [$d1, (string) substr($digits, 1)];
        }

        if (strlen($digits) >= 2) {
            $d2 = (int) substr($digits, 0, 2);
            if (in_array($d2, self::CALLING_CODES_2, true)) {
                return [$d2, (string) substr($digits, 2)];
            }
        }

        if (strlen($digits) >= 3) {
            $d3 = (int) substr($digits, 0, 3);
            if (in_array($d3, self::CALLING_CODES_3, true)) {
                return [$d3, (string) substr($digits, 3)];
            }
        }

        throw new NumberParseException(
            NumberParseException::INVALID_COUNTRY_CODE,
            'Country calling code not recognized in "' . $digits . '".'
        );
    }
}
