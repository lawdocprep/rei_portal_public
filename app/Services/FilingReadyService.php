<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use App\Services\Validation\IntentHashService;
use App\Services\Validation\ValidationPipelineFactory;
use App\Services\ArtifactFilingReadinessService;

/**
 * Phase 16.9 — FilingReadyService (Authoritative Orchestrator)
 *
 * Server-authoritative readiness:
 *   DB case data → intent assembly → artifact generation → structural validation → readiness evaluation
 *
 * Invariants:
 *  - No DB mutation
 *  - No lifecycle transition
 *  - Deterministic output
 *  - Does NOT accept client-supplied intent/artifacts
 */
final class FilingReadyService
{
    private IntentHashService $intentHashService;
    private FilingIntentAssembler $intentAssembler;
    private FilingArtifactAssemblyService $artifactAssembler;

    public function __construct(
        private PDO $db,
        ?IntentHashService $intentHashService = null,
        ?FilingIntentAssembler $intentAssembler = null,
        ?FilingArtifactAssemblyService $artifactAssembler = null
    ) {
        $this->intentHashService = $intentHashService ?? new IntentHashService();

        // Intent assembler uses DB + canonical hash service
        $this->intentAssembler = $intentAssembler
            ?? new FilingIntentAssembler($this->db, $this->intentHashService);

        // Artifact assembler uses DB + canonical hash service + exhibit linkage
        $this->artifactAssembler = $artifactAssembler
            ?? new FilingArtifactAssemblyService($this->db, $this->intentHashService);
    }

    /**
     * Evaluate filing readiness for a case/legal action.
     *
     * Stable contract:
     * {
     *   ready_for_filing: bool,
     *   blocking_reasons: string[],
     *   warnings: string[],
     *   structural_validation: {
     *     structural_status: "valid"|"invalid",
     *     validation_errors: string[],
     *     validation_warnings: string[]
     *     ... (optional extra fields)
     *   }
     * }
     */
    public function evaluate(string $tenantId, string $caseId, string $legalActionId, array $opts = []): array
    {
        $maxWarnings = is_int($opts['max_warnings'] ?? null)
            ? (int)$opts['max_warnings']
            : 50;

        /* ============================================================
         * 1) Assemble canonical intent snapshot (Phase 16.5)
         * ============================================================ */
        $intent = $this->intentAssembler->assemble(
            tenantId: $tenantId,
            caseId: $caseId,
            legalActionId: $legalActionId,
            opts: $opts
        );

        if (isset($intent['error'])) {
            // Deterministic failure: bubble as structural invalid + blocking.
            $code = (string)$intent['error'];

            return [
                'ready_for_filing' => false,
                'blocking_reasons' => [$code],
                'warnings' => [],
                'structural_validation' => [
                    'structural_status' => 'invalid',
                    'validation_errors' => [$code],
                    'validation_warnings' => [],
                ],
            ];
        }

        $actionType = $intent['action_type'] ?? null;
        if (!is_string($actionType) || $actionType === '') {
            return [
                'ready_for_filing' => false,
                'blocking_reasons' => ['INVALID_ACTION_TYPE'],
                'warnings' => [],
                'structural_validation' => [
                    'structural_status' => 'invalid',
                    'validation_errors' => ['INVALID_ACTION_TYPE'],
                    'validation_warnings' => [],
                ],
            ];
        }

        /* ============================================================
         * 2) Assemble artifacts from intent (Phase 16.6)
         * ============================================================ */
        $bundle = $this->artifactAssembler->assemble($intent);

        $artifacts = $bundle['artifacts'] ?? [];
        if (!is_array($artifacts)) {
            $artifacts = [];
        }

        $warnings = [];
        if (!empty($intent['warnings']) && is_array($intent['warnings'])) {
            $warnings = array_merge($warnings, $intent['warnings']);
        }
        if (!empty($bundle['warnings']) && is_array($bundle['warnings'])) {
            $warnings = array_merge($warnings, $bundle['warnings']);
        }

        /* ============================================================
         * 3) Structural validation pipeline (Phase 16.7–16.8)
         *    Use your ValidationPipelineFactory for the action type.
         * ============================================================ */
        $pipeline = ValidationPipelineFactory::forActionType($actionType);

        // validate() signature in your controller comments:
        // validate(array $artifacts, array $intent, ?string $actionType, bool $trace)
        $structural = $pipeline->validate(
            $artifacts,
            $intent,
            $actionType,
            false
        );

        if (!is_array($structural)) {
            // Defensive fallback; should never happen.
            $structural = [
                'structural_status' => empty($artifacts) ? 'invalid' : 'valid',
                'validation_errors' => empty($artifacts) ? ['EMPTY_ARTIFACT_BUNDLE'] : [],
                'validation_warnings' => ['STRUCTURAL_PIPELINE_RETURN_INVALID'],
            ];
        }

        // Normalize structural output (guarantee stable keys)
        $structuralStatus = (string)($structural['structural_status'] ?? 'invalid');
        $validationErrors = $structural['validation_errors'] ?? [];
        $validationWarnings = $structural['validation_warnings'] ?? [];

        if (!is_array($validationErrors)) {
            $validationErrors = [];
        }
        if (!is_array($validationWarnings)) {
            $validationWarnings = [];
        }

        foreach ($validationWarnings as $vw) {
            if (is_string($vw) && $vw !== '') {
                $warnings[] = $vw;
            }
        }

        /* ============================================================
         * 4) Filing readiness evaluation (Phase 16.9)
         *    ArtifactFilingReadinessService is the canonical evaluator.
         * ============================================================ */
        $structuralValidator = function (array $intent, array $artifacts, string $actionType) use ($pipeline): array {
            return $pipeline->validate(
                $artifacts,
                $intent,
                $actionType,
                false
            );
        };

        $readinessService = new ArtifactFilingReadinessService($structuralValidator);

        // Canonical readiness call signature (as used earlier in your controller)
        $report = $readinessService->evaluate(
            $intent,
            $artifacts,
            $actionType
        );

        if (!is_array($report)) {
            // Defensive fallback: treat as structural invalid
            $blocking = ['READINESS_EVALUATOR_FAILED'];
            if ($structuralStatus !== 'valid') {
                $blocking[] = 'STRUCTURAL_INVALID';
            }
            foreach ($validationErrors as $e) {
                if (is_string($e) && $e !== '') {
                    $blocking[] = $e;
                }
            }

            $warnings = $this->cap($this->uniqueStrings($warnings), $maxWarnings);

            return [
                'ready_for_filing' => false,
                'blocking_reasons' => $this->uniqueStrings($blocking),
                'warnings' => $warnings,
                'structural_validation' => $this->normalizeStructuralPayload($structural),
            ];
        }

        // Ensure the returned report conforms (and merge warnings)
        $outWarnings = $report['warnings'] ?? [];
        if (is_array($outWarnings)) {
            foreach ($outWarnings as $w) {
                if (is_string($w) && $w !== '') {
                    $warnings[] = $w;
                }
            }
        }

        $report['warnings'] = $this->cap($this->uniqueStrings($warnings), $maxWarnings);

        // Always include normalized structural payload (even if evaluator returned one)
        $report['structural_validation'] = $this->normalizeStructuralPayload(
            is_array($report['structural_validation'] ?? null)
                ? (array)$report['structural_validation']
                : $structural
        );

        // Never allow readiness=true if blocking reasons present
        $blocking = $report['blocking_reasons'] ?? [];
        if (!is_array($blocking)) {
            $blocking = [];
        }

        $blocking = $this->uniqueStrings(array_values(array_filter($blocking, fn($v) => is_string($v) && trim($v) !== '')));
        $report['blocking_reasons'] = $blocking;
        $report['ready_for_filing'] = (bool)($report['ready_for_filing'] ?? false) && count($blocking) === 0;
        $report['intent'] = $intent;
        $report['structured_artifacts'] = $artifacts;

        return $report;
    }

    /* ============================================================
     * Output normalization + small utilities
     * ============================================================ */

    private function normalizeStructuralPayload(array $structural): array
    {
        return [
            'structural_status' => $structural['structural_status'] ?? 'invalid',
            'validation_errors' => is_array($structural['validation_errors'] ?? null) ? $structural['validation_errors'] : [],
            'validation_warnings' => is_array($structural['validation_warnings'] ?? null) ? $structural['validation_warnings'] : [],
            // Preserve any extra fields the pipeline returns (bundle_validation, artifactsByType, etc.)
            ...array_diff_key(
                $structural,
                array_flip(['structural_status', 'validation_errors', 'validation_warnings'])
            ),
        ];
    }

    private function uniqueStrings(array $items): array
    {
        $seen = [];
        $out = [];

        foreach ($items as $v) {
            if (!is_string($v)) {
                continue;
            }
            $v = trim($v);
            if ($v === '' || isset($seen[$v])) {
                continue;
            }
            $seen[$v] = true;
            $out[] = $v;
        }

        return $out;
    }

    private function cap(array $items, int $max): array
    {
        if ($max < 0) {
            return [];
        }
        if (count($items) > $max) {
            return array_slice($items, 0, $max);
        }
        return $items;
    }
}