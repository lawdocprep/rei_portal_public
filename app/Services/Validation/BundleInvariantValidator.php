<?php
declare(strict_types=1);

namespace App\Services\Validation;

final class BundleInvariantValidator
{
    private IntentSnapshotValidator $intentSnapshotValidator;
    private ExhibitInvariantValidator $exhibitValidator;

    public function __construct()
    {
        $this->intentSnapshotValidator = new IntentSnapshotValidator();
        $this->exhibitValidator        = new ExhibitInvariantValidator();
    }

    /**
     * VALIDATION CONTRACT (Phase 16.7+ object pipeline aligned)
     *
     * @param array $intent
     * @param array $artifacts
     */
    public function validate(array $intent, array $artifacts): array
    {
        if (empty($artifacts) || !is_array($artifacts)) {
            return $this->result(['EMPTY_ARTIFACT_BUNDLE'], []);
        }

        [$byType, $indexErrors] = $this->indexArtifactsByType($artifacts);

        if (!empty($indexErrors)) {
            return $this->result($indexErrors, []);
        }

        $actionType = $this->extractActionType($byType);

        if ($actionType === null) {
            return $this->result(['BUNDLE_MISSING_ACTION_TYPE'], []);
        }

        $requiredArtifacts = ArtifactPolicyRegistry::getRequiredArtifacts($actionType);

        if (!is_array($requiredArtifacts)) {
            return $this->result(['NO_REQUIRED_ARTIFACT_POLICY_FOR_ACTION_TYPE'], []);
        }

        $errors   = [];
        $warnings = [];

        /* ------------------------------------------------------------
         * Required artifact enforcement
         * ------------------------------------------------------------ */
        foreach ($requiredArtifacts as $reqType) {
            if (!isset($byType[$reqType])) {
                $errors[] = 'MISSING_REQUIRED_ARTIFACT_' . strtoupper($reqType);
            }
        }

        foreach (array_keys($byType) as $presentType) {
            if (!in_array($presentType, $requiredArtifacts, true)) {
                $errors[] = 'UNKNOWN_ARTIFACT_TYPE_' . strtoupper($presentType);
            }
        }

        if (count($byType) !== count($requiredArtifacts)) {
            $errors[] = 'ARTIFACT_COUNT_MISMATCH_REQUIRED_SET';
        }

        if (!empty($errors)) {
            return $this->result($errors, $warnings);
        }

        /* ------------------------------------------------------------
         * Envelope-level invariants
         * ------------------------------------------------------------ */
        $expectedSchemaVersion = ArtifactPolicyRegistry::getSchemaVersion($actionType);
        $envelopeWhitelist     = ArtifactPolicyRegistry::getEnvelopeWhitelist($actionType);
        $metadataWhitelist     = ArtifactPolicyRegistry::getMetadataWhitelist($actionType);

        $bundleIntentHash    = null;
        $bundleActionType    = null;
        $bundleLegalActionId = null;

        foreach ($byType as $type => $artifact) {

            if (is_array($envelopeWhitelist)) {
                $this->validateAllowedKeys(
                    $artifact,
                    $envelopeWhitelist,
                    $warnings,
                    'ENVELOPE_' . strtoupper($type)
                );
            }

            if (($artifact['preview_only'] ?? null) !== true) {
                $errors[] = 'PREVIEW_FLAG_REQUIRED_' . strtoupper($type);
            }

            if (($artifact['format'] ?? null) !== 'structured') {
                $errors[] = 'FORMAT_REQUIRED_STRUCTURED_' . strtoupper($type);
            }

            if (($artifact['artifact_schema_version'] ?? null) !== $expectedSchemaVersion) {
                $errors[] = 'INVALID_SCHEMA_VERSION_' . strtoupper($type);
            }

            $meta = $artifact['metadata'] ?? null;

            if (!is_array($meta)) {
                $errors[] = 'METADATA_REQUIRED_' . strtoupper($type);
                continue;
            }

            if (is_array($metadataWhitelist)) {
                $this->validateAllowedKeys(
                    $meta,
                    $metadataWhitelist,
                    $warnings,
                    'METADATA_' . strtoupper($type)
                );
            }

            $rules         = ArtifactPolicyRegistry::getArtifactRules($actionType, $type);
            $expectedStage = $rules['required_lifecycle_stage'] ?? null;
            $actualStage   = $meta['lifecycle_stage'] ?? null;

            if (!is_string($actualStage) || $actualStage === '') {
                $errors[] = 'LIFECYCLE_STAGE_REQUIRED_' . strtoupper($type);
            } elseif ($expectedStage !== null && $actualStage !== $expectedStage) {
                $errors[] = 'INVALID_LIFECYCLE_STAGE_' . strtoupper($type);
            }

            // Intent hash consistency
            $intentHash = $meta['intent_hash'] ?? null;

            if (!is_string($intentHash) || $intentHash === '') {
                $errors[] = 'INTENT_HASH_REQUIRED_' . strtoupper($type);
            } else {
                $bundleIntentHash ??= $intentHash;
                if ($bundleIntentHash !== $intentHash) {
                    $errors[] = 'INTENT_HASH_MISMATCH_' . strtoupper($type);
                }
            }

            // Action type consistency
            $at = $meta['action_type'] ?? null;

            if (!is_string($at) || $at === '') {
                $errors[] = 'ACTION_TYPE_REQUIRED_' . strtoupper($type);
            } else {
                $bundleActionType ??= $at;
                if ($bundleActionType !== $at) {
                    $errors[] = 'ACTION_TYPE_MISMATCH_' . strtoupper($type);
                }
            }

            // Legal action ID consistency
            $laid = $meta['legal_action_id'] ?? null;

            if ($bundleLegalActionId === null) {
                $bundleLegalActionId = $laid;
            } elseif ($bundleLegalActionId !== $laid) {
                $errors[] = 'LEGAL_ACTION_ID_MISMATCH_' . strtoupper($type);
            }
        }

        if (!empty($errors)) {
            return $this->result($errors, $warnings);
        }

        /* ------------------------------------------------------------
         * Intent Snapshot Validation
         * ------------------------------------------------------------ */
        $snapshotResult = $this->intentSnapshotValidator->validate($intent, $artifacts);

        $errors   = array_merge($errors,   $snapshotResult['validation_errors']   ?? []);
        $warnings = array_merge($warnings, $snapshotResult['validation_warnings'] ?? []);

        if (!empty($errors)) {
            return $this->result($errors, $warnings);
        }

        /* ------------------------------------------------------------
         * Exhibit Invariant Validation
         * ------------------------------------------------------------ */
        $exhibitResult = $this->exhibitValidator->validate($artifacts, $actionType);

        $errors   = array_merge($errors,   $exhibitResult['validation_errors']   ?? []);
        $warnings = array_merge($warnings, $exhibitResult['validation_warnings'] ?? []);

        return $this->result($errors, $warnings);
    }

    /* ============================================================ */
    /* (All helper methods unchanged) */
    /* ============================================================ */


    private function indexArtifactsByType(array $artifacts): array
    {
        $byType = [];
        $errors = [];

        foreach ($artifacts as $i => $artifact) {

            if (!is_array($artifact)) {
                $errors[] = 'INVALID_ARTIFACT_ENTRY_AT_' . $i;
                continue;
            }

            $type = $artifact['artifact_type'] ?? null;

            if (!is_string($type) || $type === '') {
                $errors[] = 'MISSING_ARTIFACT_TYPE_AT_' . $i;
                continue;
            }

            if (isset($byType[$type])) {
                $errors[] = 'DUPLICATE_ARTIFACT_TYPE_' . strtoupper($type);
                continue;
            }

            $byType[$type] = $artifact;
        }

        return [$byType, $errors];
    }

    private function extractActionType(array $byType): ?string
    {
        $first = reset($byType);
        $meta  = is_array($first) ? ($first['metadata'] ?? null) : null;
        $at    = is_array($meta) ? ($meta['action_type'] ?? null) : null;

        return is_string($at) && $at !== '' ? $at : null;
    }

    private function validateAllowedKeys(array $data, array $allowed, array &$warnings, string $scope): void
    {
        $allowedMap = array_flip($allowed);

        foreach ($data as $key => $_) {
            if (!isset($allowedMap[$key])) {
                $warnings[] = 'UNKNOWN_KEY_' . $scope . '_' . strtoupper((string)$key);
            }
        }
    }

    private function result(array $errors, array $warnings): array
    {
        return [
            'structural_status'   => empty($errors) ? 'valid' : 'invalid',
            'validation_errors'   => array_values($errors),
            'validation_warnings' => array_values($warnings),
        ];
    }
}
