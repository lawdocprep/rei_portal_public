<?php
declare(strict_types=1);

/**
 * ArtifactValidationPipeline.php
 *
 * CLEAN PIPELINE ARCHITECTURE (Drop-in replacement)
 *
 * ✅ Single stage contract: App\Services\Validation\Pipeline\ValidationStageInterface
 * ✅ Single execution method: execute(ValidationPipelineContext $ctx): void
 * ✅ Object-based context (defined in this file)
 * ✅ No run() compatibility
 * ✅ No array-context stages
 * ✅ No legacy aliases / shims
 *
 * This file contains ONLY:
 * - ValidationPipelineContext (in App\Services\Validation)
 * - ArtifactValidationPipeline (in App\Services\Validation)
 * - Core pipeline stages that MUST always run (in App\Services\Validation\Pipeline):
 *      - IndexArtifactsStage
 *      - RequiredArtifactsStage
 *      - BundleEnvelopeInvariantStage
 *
 * State providers should return ONLY state-specific stages from:
 *   App\Services\Validation\Pipeline\*
 */

namespace App\Services\Validation;

use App\Services\Validation\Pipeline\ValidationStageInterface;

/**
 * Canonical pipeline context.
 * Lives in App\Services\Validation (NOT Pipeline).
 */
final class ValidationPipelineContext
{
    public string $actionType;

    /** Raw/enriched artifacts (already per-artifact validated upstream) */
    public array $artifacts;

    /** Canonical intent snapshot used for assembly */
    public array $intent;

    /** Indexed artifacts by artifact_type */
    public array $artifactsByType = [];

    /** Validation output */
    public array $errors = [];
    public array $warnings = [];

    /** Audit trace (optional) */
    public bool $traceEnabled = false;

    /**
     * Each trace entry:
     * [
     *   'stage' => string,
     *   'index' => int,
     *   'started_at_ms' => float,
     *   'duration_ms' => float,
     *   'errors_added' => string[],
     *   'warnings_added' => string[],
     * ]
     */
    public array $trace = [];

    /** @internal */
    private array $stageStartCounts = [
        'errors' => 0,
        'warnings' => 0,
    ];

    public function __construct(
        string $actionType,
        array $artifacts,
        array $intent,
        bool $traceEnabled = false
    ) {
        $this->actionType   = $actionType;
        $this->artifacts    = $artifacts;
        $this->intent       = $intent;
        $this->traceEnabled = $traceEnabled;
    }

    public function addError(string $code): void
    {
        $this->errors[] = $code;
    }

    public function addWarning(string $code): void
    {
        $this->warnings[] = $code;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /* ============================================================
     * Trace helpers (no-ops unless enabled)
     * ============================================================ */

    public function traceStageStart(string $stageName, int $index): void
    {
        if (!$this->traceEnabled) {
            return;
        }

        $this->stageStartCounts = [
            'errors' => count($this->errors),
            'warnings' => count($this->warnings),
        ];

        $this->trace[$index] = [
            'stage' => $stageName,
            'index' => $index,
            'started_at_ms' => microtime(true) * 1000.0,
            'duration_ms' => 0.0,
            'errors_added' => [],
            'warnings_added' => [],
        ];
    }

    public function traceStageEnd(int $index, float $durationMs): void
    {
        if (!$this->traceEnabled) {
            return;
        }
        if (!isset($this->trace[$index])) {
            return;
        }

        $startErr  = $this->stageStartCounts['errors'];
        $startWarn = $this->stageStartCounts['warnings'];

        $errorsAdded   = array_slice($this->errors, $startErr);
        $warningsAdded = array_slice($this->warnings, $startWarn);

        $this->trace[$index]['duration_ms']    = $durationMs;
        $this->trace[$index]['errors_added']   = array_values(array_filter($errorsAdded, 'is_string'));
        $this->trace[$index]['warnings_added'] = array_values(array_filter($warningsAdded, 'is_string'));
    }
}

/**
 * ============================================================
 * Pipeline executor
 * ============================================================
 */
final class ArtifactValidationPipeline
{
    /** @var ValidationStageInterface[] */
    private array $stages;

    /**
     * @param ValidationStageInterface[] $stateStages Pipeline stages supplied by provider (state-scoped)
     */
    public function __construct(array $stateStages)
    {
        // Core stages that must always run first (canonical, deterministic).
        $coreStages = [
            new \App\Services\Validation\Pipeline\IndexArtifactsStage(),
            new \App\Services\Validation\Pipeline\RequiredArtifactsStage(),
            new \App\Services\Validation\Pipeline\BundleEnvelopeInvariantStage(),
        ];

        $all = array_merge($coreStages, array_values($stateStages));

        foreach ($all as $stage) {
            if (!$stage instanceof ValidationStageInterface) {
                throw new \InvalidArgumentException(
                    'INVALID_VALIDATION_STAGE_INSTANCE: ' . (is_object($stage) ? get_class($stage) : gettype($stage))
                );
            }
        }

        $this->stages = $all;
    }

    /**
     * Executes an ordered ValidationStageInterface[].
     *
     * Backward-compatible signature (used by FilingArtifactValidationService):
     * - validate(artifacts, intent)                      // actionType derived
     * - validate(artifacts, intent, 'az_forcible_det...') // actionType explicit
     * - validate(..., traceEnabled: true)                // trace returned
     */
    public function validate(
        array $artifacts,
        array $intent,
        ?string $actionType = null,
        bool $traceEnabled = false
    ): array {
        $resolvedActionType = $actionType;

        if (!is_string($resolvedActionType) || $resolvedActionType === '') {
            $resolvedActionType = $this->deriveActionTypeFromArtifacts($artifacts);
        }

        if (!is_string($resolvedActionType) || $resolvedActionType === '') {
            return $this->result(['BUNDLE_MISSING_ACTION_TYPE'], [], $traceEnabled ? [] : null);
        }

        $ctx = new ValidationPipelineContext(
            $resolvedActionType,
            $artifacts,
            $intent,
            $traceEnabled
        );

        foreach ($this->stages as $i => $stage) {

            if ($traceEnabled) {
                $ctx->traceStageStart($stage->name(), (int)$i);
                $t0 = microtime(true);

                $stage->execute($ctx);

                $dt = (microtime(true) - $t0) * 1000.0;
                $ctx->traceStageEnd((int)$i, $dt);
            } else {
                $stage->execute($ctx);
            }

            // Fail-fast for structural errors; warnings accumulate.
            if ($ctx->hasErrors()) {
                break;
            }
        }

        return $this->result(
            $ctx->errors,
            $ctx->warnings,
            $traceEnabled ? $this->normalizedTrace($ctx->trace) : null
        );
    }

    private function deriveActionTypeFromArtifacts(array $artifacts): ?string
    {
        foreach ($artifacts as $a) {
            if (!is_array($a)) {
                continue;
            }
            $meta = $a['metadata'] ?? null;
            if (!is_array($meta)) {
                continue;
            }

            $at = $meta['action_type'] ?? null;
            if (is_string($at) && $at !== '') {
                return $at;
            }
        }

        return null;
    }

    private function result(array $errors, array $warnings, ?array $trace = null): array
    {
        $out = [
            'structural_status'   => empty($errors) ? 'valid' : 'invalid',
            'validation_errors'   => array_values($errors),
            'validation_warnings' => array_values($warnings),
        ];

        // Only present when trace was requested
        if (is_array($trace)) {
            $out['trace'] = $trace;
        }

        return $out;
    }

    /**
     * Ensure stable ordering + stable shapes for debug snapshots.
     */
    private function normalizedTrace(array $trace): array
    {
        ksort($trace);

        $out = [];
        foreach ($trace as $row) {
            if (!is_array($row)) {
                continue;
            }

            $out[] = [
                'stage' => (string)($row['stage'] ?? ''),
                'index' => (int)($row['index'] ?? 0),
                'duration_ms' => (float)($row['duration_ms'] ?? 0.0),
                'errors_added' => array_values(is_array($row['errors_added'] ?? null) ? $row['errors_added'] : []),
                'warnings_added' => array_values(is_array($row['warnings_added'] ?? null) ? $row['warnings_added'] : []),
            ];
        }

        return $out;
    }
}

/* ========================================================================
 * Core pipeline stages (canonical) — kept in Pipeline namespace.
 * These MUST NOT be redefined elsewhere.
 * ====================================================================== */

namespace App\Services\Validation\Pipeline;

use App\Services\Validation\ArtifactPolicyRegistry;
use App\Services\Validation\ValidationPipelineContext;

final class IndexArtifactsStage implements ValidationStageInterface
{
    public function name(): string
    {
        return 'index_artifacts';
    }

    public function execute(ValidationPipelineContext $ctx): void
    {
        if (empty($ctx->artifacts) || !is_array($ctx->artifacts)) {
            $ctx->addError('EMPTY_ARTIFACT_BUNDLE');
            return;
        }

        $byType = [];

        foreach ($ctx->artifacts as $i => $artifact) {
            if (!is_array($artifact)) {
                $ctx->addError('INVALID_ARTIFACT_ENTRY_AT_' . $i);
                continue;
            }

            $type = $artifact['artifact_type'] ?? null;
            if (!is_string($type) || $type === '') {
                $ctx->addError('MISSING_ARTIFACT_TYPE_AT_' . $i);
                continue;
            }

            if (isset($byType[$type])) {
                $ctx->addError('DUPLICATE_ARTIFACT_TYPE_' . strtoupper($type));
                continue;
            }

            $byType[$type] = $artifact;
        }

        $ctx->artifactsByType = $byType;
    }
}

final class RequiredArtifactsStage implements ValidationStageInterface
{
    public function name(): string
    {
        return 'required_artifacts';
    }

    public function execute(ValidationPipelineContext $ctx): void
    {
        $required = ArtifactPolicyRegistry::getRequiredArtifacts($ctx->actionType);

        if (!is_array($required)) {
            $ctx->addError('NO_REQUIRED_ARTIFACT_POLICY_FOR_ACTION_TYPE');
            return;
        }

        foreach ($required as $req) {
            if (!isset($ctx->artifactsByType[$req])) {
                $ctx->addError('MISSING_REQUIRED_ARTIFACT_' . strtoupper((string)$req));
            }
        }

        foreach (array_keys($ctx->artifactsByType) as $present) {
            if (!in_array($present, $required, true)) {
                $ctx->addError('UNKNOWN_ARTIFACT_TYPE_' . strtoupper((string)$present));
            }
        }

        if (count($ctx->artifactsByType) !== count($required)) {
            $ctx->addError('ARTIFACT_COUNT_MISMATCH_REQUIRED_SET');
        }
    }
}

/**
 * NOTE:
 * This stage enforces envelope invariants + whitelist warnings.
 * It is policy-driven and runs before state-specific stages.
 */
final class BundleEnvelopeInvariantStage implements ValidationStageInterface
{
    public function name(): string
    {
        return 'bundle_envelope_invariants';
    }

    public function execute(ValidationPipelineContext $ctx): void
    {
        $expectedSchema = ArtifactPolicyRegistry::getSchemaVersion($ctx->actionType);

        $bundleIntentHash    = null;
        $bundleActionType    = null;
        $bundleLegalActionId = null;

        $envelopeWhitelist = ArtifactPolicyRegistry::getEnvelopeWhitelist($ctx->actionType);
        $metadataWhitelist = ArtifactPolicyRegistry::getMetadataWhitelist($ctx->actionType);

        foreach ($ctx->artifactsByType as $type => $artifact) {

            // Envelope whitelist (warnings)
            if (is_array($envelopeWhitelist)) {
                $this->validateAllowedKeys(
                    $artifact,
                    $envelopeWhitelist,
                    $ctx,
                    'ENVELOPE_' . strtoupper($type)
                );
            }

            if (($artifact['preview_only'] ?? null) !== true) {
                $ctx->addError('PREVIEW_FLAG_REQUIRED_' . strtoupper($type));
            }

            if (($artifact['format'] ?? null) !== 'structured') {
                $ctx->addError('FORMAT_REQUIRED_STRUCTURED_' . strtoupper($type));
            }

            if ($expectedSchema !== null && ($artifact['artifact_schema_version'] ?? null) !== $expectedSchema) {
                $ctx->addError('INVALID_SCHEMA_VERSION_' . strtoupper($type));
            }

            $meta = $artifact['metadata'] ?? null;
            if (!is_array($meta)) {
                $ctx->addError('METADATA_REQUIRED_' . strtoupper($type));
                continue;
            }

            // Metadata whitelist (warnings)
            if (is_array($metadataWhitelist)) {
                $this->validateAllowedKeys(
                    $meta,
                    $metadataWhitelist,
                    $ctx,
                    'METADATA_' . strtoupper($type)
                );
            }

            // Lifecycle stage (policy-driven)
            $rules         = ArtifactPolicyRegistry::getArtifactRules($ctx->actionType, $type);
            $expectedStage = is_array($rules) ? ($rules['required_lifecycle_stage'] ?? null) : null;
            $actualStage   = $meta['lifecycle_stage'] ?? null;

            if (!is_string($actualStage) || $actualStage === '') {
                $ctx->addError('LIFECYCLE_STAGE_REQUIRED_' . strtoupper($type));
            } elseif ($expectedStage !== null && $actualStage !== $expectedStage) {
                $ctx->addError('INVALID_LIFECYCLE_STAGE_' . strtoupper($type));
            }

            // Intent hash consistency
            $ih = $meta['intent_hash'] ?? null;
            if (!is_string($ih) || $ih === '') {
                $ctx->addError('INTENT_HASH_REQUIRED_' . strtoupper($type));
            } else {
                $bundleIntentHash ??= $ih;
                if ($bundleIntentHash !== $ih) {
                    $ctx->addError('INTENT_HASH_MISMATCH_' . strtoupper($type));
                }
            }

            // Action type consistency
            $at = $meta['action_type'] ?? null;
            if (!is_string($at) || $at === '') {
                $ctx->addError('ACTION_TYPE_REQUIRED_' . strtoupper($type));
            } else {
                $bundleActionType ??= $at;
                if ($bundleActionType !== $at) {
                    $ctx->addError('ACTION_TYPE_MISMATCH_' . strtoupper($type));
                }
            }

            // Legal action ID consistency
            $laid = $meta['legal_action_id'] ?? null;
            if ($bundleLegalActionId === null) {
                $bundleLegalActionId = $laid;
            } elseif ($bundleLegalActionId !== $laid) {
                $ctx->addError('LEGAL_ACTION_ID_MISMATCH_' . strtoupper($type));
            }

            // Content top-level whitelist (warnings)
            $content = $artifact['content'] ?? null;
            if (is_array($content)) {
                $contentWhitelist = ArtifactPolicyRegistry::getContentTopLevelWhitelist($ctx->actionType, $type);
                if (is_array($contentWhitelist)) {
                    $this->validateAllowedKeys(
                        $content,
                        $contentWhitelist,
                        $ctx,
                        'CONTENT_' . strtoupper($type)
                    );
                }
            }
        }
    }

    private function validateAllowedKeys(array $data, array $allowed, ValidationPipelineContext $ctx, string $scope): void
    {
        $allowedMap = array_flip($allowed);

        foreach ($data as $k => $_) {
            if (!isset($allowedMap[$k])) {
                $ctx->addWarning('UNKNOWN_KEY_' . $scope . '_' . strtoupper((string)$k));
            }
        }
    }
}
