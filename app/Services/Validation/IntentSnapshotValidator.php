<?php
declare(strict_types=1);

namespace App\Services\Validation;

use App\Services\Validation\IntentHashService;

/**
 * Phase 16.7 — Intent snapshot freezing validator (structural only)
 *
 * Validates:
 * - Deterministic recomputation of intent hash (via IntentHashService)
 * - Equality against intent['intent_hash'] (if declared)
 * - Equality against each artifact metadata.intent_hash
 * - Optional embedded snapshot integrity (warning only)
 *
 * Does NOT:
 * - Mutate DB
 * - Validate legal sufficiency
 * - Validate jurisdiction correctness
 */
final class IntentSnapshotValidator
{
    private IntentHashService $intentHashService;

    public function __construct(
        ?IntentHashService $intentHashService = null
    ) {
        $this->intentHashService =
            $intentHashService ?? new IntentHashService();
    }

    /**
     * @param array $intent    Canonical frozen intent snapshot
     * @param array $artifacts Indexed artifact list (validated envelopes)
     */
    public function validate(array $intent, array $artifacts): array
    {
        $errors   = [];
        $warnings = [];

        if (!is_array($intent) || empty($intent)) {
            return $this->result(
                ['INTENT_SNAPSHOT_REQUIRED'],
                [],
                null
            );
        }

        $computed = $this->intentHashService->compute($intent);

        if (!is_string($computed) || $computed === '') {
            return $this->result(
                ['INTENT_HASH_RECOMPUTE_FAILED'],
                [],
                null
            );
        }

        /* ------------------------------------------------------------
         * 1️⃣ Intent-level hash check
         * ------------------------------------------------------------ */

        $declared = $intent['intent_hash'] ?? null;

        if (!is_string($declared) || $declared === '') {
            $warnings[] = 'INTENT_HASH_MISSING_IN_INTENT_SNAPSHOT';
        } elseif (!hash_equals($declared, $computed)) {
            $errors[] = 'INTENT_HASH_MISMATCH_INTENT_RECOMPUTE';
        }

        /* ------------------------------------------------------------
         * 2️⃣ Artifact-level hash binding check
         * ------------------------------------------------------------ */

        foreach ($artifacts as $artifact) {

            if (!is_array($artifact)) {
                continue;
            }

            $type = $artifact['artifact_type'] ?? 'UNKNOWN';
            $tag  = strtoupper((string)$type);

            $meta = $artifact['metadata'] ?? null;

            if (!is_array($meta)) {
                $errors[] =
                    'METADATA_REQUIRED_FOR_INTENT_SNAPSHOT_' . $tag;
                continue;
            }

            $artifactHash = $meta['intent_hash'] ?? null;

            if (!is_string($artifactHash) || $artifactHash === '') {
                $errors[] =
                    'INTENT_HASH_REQUIRED_FOR_ARTIFACT_' . $tag;
                continue;
            }

            if (!hash_equals($artifactHash, $computed)) {
                $errors[] =
                    'INTENT_HASH_MISMATCH_ARTIFACT_' . $tag;
            }

            /* --------------------------------------------------------
             * 3️⃣ Optional embedded snapshot integrity (warning only)
             * -------------------------------------------------------- */

            if (
                isset($meta['generated_from_intent']) &&
                is_array($meta['generated_from_intent'])
            ) {
                $embeddedHash =
                    $this->intentHashService->compute(
                        $meta['generated_from_intent']
                    );

                if (
                    is_string($embeddedHash) &&
                    !hash_equals($embeddedHash, $computed)
                ) {
                    $warnings[] =
                        'EMBEDDED_INTENT_SNAPSHOT_HASH_MISMATCH_' . $tag;
                }
            }
        }

        return $this->result($errors, $warnings, $computed);
    }

    private function result(
        array $errors,
        array $warnings,
        ?string $computed
    ): array {
        return [
            'structural_status'    => empty($errors) ? 'valid' : 'invalid',
            'validation_errors'    => array_values($errors),
            'validation_warnings'  => array_values($warnings),
            'computed_intent_hash' => $computed,
        ];
    }
}
