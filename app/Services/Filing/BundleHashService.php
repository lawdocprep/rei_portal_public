<?php
declare(strict_types=1);

namespace App\Services\Filing;

use RuntimeException;

/**
 * Phase 19 — BundleHashService
 *
 * Contract:
 * bundle_hash = SHA-256 over canonical JSON payload:
 * {
 *   "schema_version": 1,
 *   "intent_hash": "...",
 *   "artifacts": [...],      // canonicalized + deterministically sorted
 *   "exhibits": [...],       // canonicalized + deterministically sorted
 *   "version_hashes": [...]  // canonicalized + deterministically sorted
 * }
 *
 * Excluded from hash:
 * - structural_validation_json
 * - bundle id/version, timestamps, tenant/case ids, freeze flags
 */
final class BundleHashService
{
    public const SCHEMA_VERSION = 1;

    /**
     * Build canonical payload + compute hash.
     *
     * @param string $intentHash
     * @param array  $structuredArtifactsJson   The decoded JSON (array) you persist in filing_bundles.structured_artifacts_json
     * @param array  $exhibitLinkageC14nJson    The decoded JSON (array) you persist in filing_bundles.exhibit_linkage_c14n_json
     * @param array  $versionHashesC14nJson     The decoded JSON (array) you persist in filing_bundles.version_hashes_c14n_json
     *
     * @return array{schema_version:int, canonical_payload:array, canonical_json:string, bundle_hash:string}
     */
    public function compute(
        string $intentHash,
        array $structuredArtifactsJson,
        array $exhibitLinkageC14nJson,
        array $versionHashesC14nJson
    ): array {
        $artifacts = $this->canonicalizeArtifacts($structuredArtifactsJson);
        $exhibits  = $this->canonicalizeExhibits($exhibitLinkageC14nJson);
        $versions  = $this->canonicalizeVersionHashes($versionHashesC14nJson);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'intent_hash'    => $intentHash,
            'artifacts'      => $artifacts,
            'exhibits'       => $exhibits,
            'version_hashes' => $versions,
        ];

        // Recursively sort keys for complete determinism.
        $payload = $this->ksortRecursive($payload);

        // Deterministic JSON encoding (no pretty print, no random spacing).
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('BUNDLE_HASH_JSON_ENCODE_FAILED');
        }

        $hash = hash('sha256', $json);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('BUNDLE_HASH_COMPUTE_FAILED');
        }

        return [
            'schema_version'    => self::SCHEMA_VERSION,
            'canonical_payload' => $payload,
            'canonical_json'    => $json,
            'bundle_hash'       => $hash,
        ];
    }

    /**
     * Verify stored hash matches recomputed hash from canonical inputs.
     */
    public function verify(
        string $expectedBundleHash,
        string $intentHash,
        array $structuredArtifactsJson,
        array $exhibitLinkageC14nJson,
        array $versionHashesC14nJson
    ): bool {
        $computed = $this->compute(
            $intentHash,
            $structuredArtifactsJson,
            $exhibitLinkageC14nJson,
            $versionHashesC14nJson
        );

        return hash_equals($expectedBundleHash, $computed['bundle_hash']);
    }

    /* ============================================================
     * Canonicalizers
     * ============================================================ */

    /**
     * Canonicalize structured artifacts.
     *
     * Expected input shape: whatever you store in structured_artifacts_json,
     * commonly either:
     * - ["artifacts" => [ ...envelopes... ]]
     * - or directly [ ...envelopes... ]
     *
     * Output: list of envelopes (arrays), deterministically sorted by artifact_type then stable tiebreakers.
     */
    private function canonicalizeArtifacts(array $structuredArtifactsJson): array
    {
        $list = $structuredArtifactsJson;

        // Accept either { artifacts: [...] } or raw [...]
        if (isset($structuredArtifactsJson['artifacts']) && is_array($structuredArtifactsJson['artifacts'])) {
            $list = $structuredArtifactsJson['artifacts'];
        }

        if (!is_array($list)) {
            throw new RuntimeException('BUNDLE_HASH_INVALID_ARTIFACTS_SHAPE');
        }

        // Normalize: ensure each artifact is an array; recursively sort keys.
        $normalized = [];
        foreach ($list as $a) {
            if (!is_array($a)) {
                continue;
            }
            $normalized[] = $this->ksortRecursive($a);
        }

        // Deterministic sort:
        // 1) artifact_type asc
        // 2) form_type / template / name (if present) asc
        // 3) json_encode fallback asc
        usort($normalized, function (array $x, array $y): int {
            $ax = (string)($x['artifact_type'] ?? '');
            $ay = (string)($y['artifact_type'] ?? '');
            if ($ax !== $ay) {
                return $ax <=> $ay;
            }

            $tx = (string)($x['form_type'] ?? $x['template'] ?? $x['name'] ?? '');
            $ty = (string)($y['form_type'] ?? $y['template'] ?? $y['name'] ?? '');
            if ($tx !== $ty) {
                return $tx <=> $ty;
            }

            $jx = json_encode($x, JSON_UNESCAPED_SLASHES) ?: '';
            $jy = json_encode($y, JSON_UNESCAPED_SLASHES) ?: '';
            return $jx <=> $jy;
        });

        return $normalized;
    }

    /**
     * Canonicalize exhibit linkage rows.
     *
     * Expected input: list of linkage rows, already "c14n" from your ExhibitLinkageService
     * but we still enforce sorting + key ordering.
     *
     * Each row SHOULD include:
     * - artifact_type
     * - exhibit_key (or exhibit_code)
     * - case_document_id
     * - version_id (or final_version_id)
     * - content_hash
     */
    private function canonicalizeExhibits(array $exhibitLinkageC14nJson): array
    {
        $list = $exhibitLinkageC14nJson;

        if (!is_array($list)) {
            throw new RuntimeException('BUNDLE_HASH_INVALID_EXHIBITS_SHAPE');
        }

        $normalized = [];
        foreach ($list as $row) {
            if (!is_array($row)) continue;
            $normalized[] = $this->ksortRecursive($row);
        }

        usort($normalized, function (array $x, array $y): int {
            $ax = (string)($x['artifact_type'] ?? '');
            $ay = (string)($y['artifact_type'] ?? '');
            if ($ax !== $ay) return $ax <=> $ay;

            // Required first (true before false)
            $rx = !empty($x['required']);
            $ry = !empty($y['required']);
            if ($rx !== $ry) return $rx ? -1 : 1;

            $ex = (string)($x['exhibit_key'] ?? '');
            $ey = (string)($y['exhibit_key'] ?? '');
            if ($ex !== $ey) return $ex <=> $ey;

            $hx = (string)($x['content_hash'] ?? '');
            $hy = (string)($y['content_hash'] ?? '');
            if ($hx !== $hy) return $hx <=> $hy;

            $dx = (string)($x['source_case_document_id'] ?? '');
            $dy = (string)($y['source_case_document_id'] ?? '');
            if ($dx !== $dy) return $dx <=> $dy;

            $vx = (string)($x['source_version_id'] ?? '');
            $vy = (string)($y['source_version_id'] ?? '');
            return $vx <=> $vy;
        });

        return $normalized;
    }

    private function canonicalizeVersionHashes(array $versionHashesC14nJson): array
    {
        $list = $versionHashesC14nJson;

        if (!is_array($list)) {
            throw new RuntimeException('BUNDLE_HASH_INVALID_VERSION_HASHES_SHAPE');
        }

        $normalized = [];
        foreach ($list as $row) {
            if (!is_array($row)) continue;
            $normalized[] = $this->ksortRecursive($row);
        }

        usort($normalized, function (array $x, array $y): int {
            $dx = (string)($x['case_document_id'] ?? '');
            $dy = (string)($y['case_document_id'] ?? '');
            if ($dx !== $dy) return $dx <=> $dy;

            $vx = (string)($x['version_id'] ?? '');
            $vy = (string)($y['version_id'] ?? '');
            if ($vx !== $vy) return $vx <=> $vy;

            $hx = (string)($x['content_hash'] ?? '');
            $hy = (string)($y['content_hash'] ?? '');
            return $hx <=> $hy;
        });

        return $normalized;
    }

    /* ============================================================
     * Deterministic helpers
     * ============================================================ */

    private function isAssoc(array $arr): bool
    {
        $i = 0;
        foreach (array_keys($arr) as $k) {
            if ($k !== $i) return true;
            $i++;
        }
        return false;
    }

    /**
     * Recursively sort keys in associative arrays.
     * Preserves list ordering (lists are sorted by explicit comparators elsewhere).
     */
    private function ksortRecursive(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }

        // If list, recurse but do not reorder here.
        if (!$this->isAssoc($v)) {
            $out = [];
            foreach ($v as $item) {
                $out[] = $this->ksortRecursive($item);
            }
            return $out;
        }

        // Assoc: sort keys
        ksort($v);
        foreach ($v as $k => $item) {
            $v[$k] = $this->ksortRecursive($item);
        }
        return $v;
    }
}