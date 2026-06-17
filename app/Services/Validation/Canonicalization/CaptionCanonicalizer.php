<?php
declare(strict_types=1);

namespace App\Services\Validation\Canonicalization;

final class CaptionCanonicalizer
{
    /**
     * Canonicalizes caption into deterministic structure
     * for structural comparison across artifacts.
     *
     * Phase 16 structure:
     *  - plaintiffs[]: array of structured party objects
     *  - defendants[]: array of structured party objects
     */
    public static function normalize(array $caption): array
    {
        return [
            'plaintiffs'  => self::normalizePartyList($caption['plaintiffs'] ?? []),
            'defendants'  => self::normalizePartyList($caption['defendants'] ?? []),
            'case_number' => self::canonScalar($caption['case_number'] ?? null),
        ];
    }

    /**
     * Normalize structured party list.
     *
     * Accepts:
     * [
     *   ['name' => 'John Smith', ...],
     *   ...
     * ]
     */
    private static function normalizePartyList(mixed $parties): array
    {
        if (!is_array($parties)) {
            return [];
        }

        $out = [];

        foreach ($parties as $party) {

            if (!is_array($party)) {
                continue;
            }

            $name = $party['name'] ?? null;

            if (!is_string($name)) {
                continue;
            }

            $name = trim($name);
            if ($name === '') {
                continue;
            }

            // Collapse whitespace
            $name = (string)preg_replace('/\s+/', ' ', $name);

            // Uppercase for canonical comparison
            $name = mb_strtoupper($name);

            $out[] = $name;
        }

        sort($out);

        return $out;
    }

    private static function canonScalar(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);
        $value = (string)preg_replace('/\s+/', ' ', $value);

        return mb_strtoupper($value);
    }
}