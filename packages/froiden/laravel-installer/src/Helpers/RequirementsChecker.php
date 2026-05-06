<?php

namespace Froiden\LaravelInstaller\Helpers;

class RequirementsChecker
{

    private $_minPhpVersion = '7.1.0';

    /**
     * Check for the server requirements.
     *
     * @param array $requirements
     * @return array
     */
    public function check(array $requirements)
    {
        $results = [];

        foreach($requirements as $requirement)
        {
            $results['requirements'][$requirement] = true;

            if(!extension_loaded($requirement))
            {
                $results['requirements'][$requirement] = false;

                $results['errors'] = true;
            }
        }

        return $results;
    }

    /**
     * Check for required PHP functions (must exist and not be listed in disable_functions).
     *
     * @param array $functions
     * @return array{requirements: array<string, bool>, errors?: bool}
     */
    public function checkFunctions(array $functions)
    {
        $results  = ['requirements' => []];
        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));

        foreach ($functions as $function) {
            $enabled = function_exists($function) && ! in_array($function, $disabled, true);
            $results['requirements'][$function] = $enabled;

            if (! $enabled) {
                $results['errors'] = true;
            }
        }

        return $results;
    }

    /**
     * Check php.ini directives against expected values.
     * - 'On'/'Off' (or 1/0/yes/no/true/false) → boolean equality.
     * - Byte/size strings ('64M', '1G') or plain integers → treated as a MINIMUM (current >= expected).
     * - Anything else → case-insensitive exact string match.
     *
     * @param array<string, mixed> $settings
     * @return array{requirements: array<string, array{current: string, expected: string, passed: bool, operator: string}>, errors?: bool}
     */
    public function checkIniSettings(array $settings)
    {
        $results = ['requirements' => []];

        foreach ($settings as $directive => $expected) {
            $current = ini_get($directive);
            [$passed, $operator] = $this->iniMatches($current, $expected);

            $results['requirements'][$directive] = [
                'current'  => $current === false ? '' : (string) $current,
                'expected' => (string) $expected,
                'passed'   => $passed,
                'operator' => $operator,
            ];

            if (! $passed) {
                $results['errors'] = true;
            }
        }

        return $results;
    }

    /**
     * Compare a php.ini current value to an expected value.
     * Returns [bool passed, string operator] where operator is '=' for exact/bool
     * checks and '>=' for size/numeric minimums.
     *
     * @return array{0: bool, 1: string}
     */
    private function iniMatches($current, $expected): array
    {
        if ($current === false) {
            return [false, '='];
        }

        $expectedStr = strtolower(trim((string) $expected));
        $currentStr  = strtolower(trim((string) $current));

        $booleanExpectations = ['on', 'off', '1', '0', 'true', 'false', 'yes', 'no'];
        if (in_array($expectedStr, $booleanExpectations, true)) {
            $truthy = ['1', 'on', 'true', 'yes'];
            return [
                in_array($expectedStr, $truthy, true) === in_array($currentStr, $truthy, true),
                '=',
            ];
        }

        // Size/numeric minimum compare (e.g. post_max_size, upload_max_filesize, max_file_uploads).
        $expectedBytes = $this->parseBytes($expected);
        $currentBytes  = $this->parseBytes($current);
        if ($expectedBytes !== null && $currentBytes !== null) {
            return [$currentBytes >= $expectedBytes, '>='];
        }

        return [$currentStr === $expectedStr, '='];
    }

    /**
     * Convert a php.ini byte/size string (e.g. '64M', '1G', '1024', '-1') to bytes.
     * Returns null when the value does not parse. '-1' is treated as unlimited.
     */
    private function parseBytes($value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if ($value === '-1') {
            return PHP_INT_MAX;
        }

        if (! preg_match('/^(\d+(?:\.\d+)?)\s*([KMGkmg]?)B?$/', $value, $m)) {
            return null;
        }

        $num      = (float) $m[1];
        $suffix   = strtolower($m[2] ?? '');
        $multiplier = match ($suffix) {
            'g'     => 1024 ** 3,
            'm'     => 1024 ** 2,
            'k'     => 1024,
            default => 1,
        };

        return (int) ($num * $multiplier);
    }

    public function checkPHPversion(string $minPhpVersion = null)
    {
        $minVersionPhp = $minPhpVersion;
        $currentPhpVersion = $this->getPhpVersionInfo();
        $supported = false;
        if ($minPhpVersion == null) {
            $minVersionPhp = $this->getMinPhpVersion();
        }
        if (version_compare($currentPhpVersion['version'], $minVersionPhp) >= 0) {
            $supported = true;
        }
        $phpStatus = [
            'full' => $currentPhpVersion['full'],
            'current' => $currentPhpVersion['version'],
            'minimum' => $minVersionPhp,
            'supported' => $supported
        ];
        return $phpStatus;
    }
    /**
     * Get current Php version information
     *
     * @return array
     */
    private static function getPhpVersionInfo()
    {
        $currentVersionFull = PHP_VERSION;
        preg_match("#^\d+(\.\d+)*#", $currentVersionFull, $filtered);
        $currentVersion = $filtered[0];
        return [
            'full' => $currentVersionFull,
            'version' => $currentVersion
        ];
    }
    /**
     * Get minimum PHP version ID.
     *
     * @return string _minPhpVersion
     */
    protected function getMinPhpVersion()
    {
        return $this->_minPhpVersion;
    }
}