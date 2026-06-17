<?php
declare(strict_types=1);

namespace App\Services\Validation;

/**
 * Canonical Intent Hash Service
 *
 * Single hashing authority for:
 *  - FilingIntentAssembler
 *  - IntentSnapshotValidator
 *
 * Guarantees deterministic hashing by:
 *  - Removing volatile / non-canonical keys
 *  - Sorting associative arrays
 *  - Preserving numeric array order
 *  - Using stable JSON encoding flags
 */
final class IntentHashService
{
    public function compute(array $intent): ?string
    {
        try {

            $snapshot = $this->stripVolatileKeys($intent);

            $canonical = $this->canonicalize($snapshot);

            $json = json_encode(
                $canonical,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
            );

            if (!is_string($json) || $json === '') {
                return null;
            }

            return hash('sha256', $json);

        } catch (\Throwable) {
            return null;
        }
    }

    /* ============================================================
     * Remove non-deterministic / explanatory fields
     * ============================================================ */

    private function stripVolatileKeys(array $intent): array
    {
        unset(
            $intent['intent_hash'],
            $intent['warnings'],
            $intent['explain'],
            $intent['debug'],
            $intent['_meta'],
            $intent['_debug'],
            $intent['assembled_at'],
            $intent['generated_at']
        );

        return $intent;
    }

    /* ============================================================
     * Deterministic canonicalization
     * ============================================================ */

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($this->isAssoc($value)) {
            ksort($value);
            foreach ($value as $k => $child) {
                $value[$k] = $this->canonicalize($child);
            }
            return $value;
        }

        $out = [];
        foreach ($value as $child) {
            $out[] = $this->canonicalize($child);
        }

        return $out;
    }

    private function isAssoc(array $array): bool
    {
        $i = 0;
        foreach ($array as $k => $_) {
            if ($k !== $i) {
                return true;
            }
            $i++;
        }
        return false;
    }
}
