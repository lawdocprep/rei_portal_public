<?php
declare(strict_types=1);

namespace App\Services\Validation\Canonicalization;

final class CourtHeadingCanonicalizer
{
    /**
     * Canonicalizes court_heading block into deterministic structure.
     *
     * Rules:
     * - Trim whitespace
     * - Collapse internal whitespace
     * - Uppercase
     * - Missing values normalize to empty string
     */
    public static function normalize(array $court): array
    {
        return [
            'state'      => self::canonScalar($court['state'] ?? null),
            'county'     => self::canonScalar($court['county'] ?? null),
            'court_type' => self::canonScalar($court['court_type'] ?? null),
            'precinct'   => self::canonScalar($court['precinct'] ?? null),
        ];
    }

    private static function canonScalar(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $value = (string)preg_replace('/\s+/', ' ', $value);

        return mb_strtoupper($value);
    }
}
