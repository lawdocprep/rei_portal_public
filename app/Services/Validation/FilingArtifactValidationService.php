<?php
declare(strict_types=1);

namespace App\Services\Validation;

use App\Services\Validation\Stages\StateValidationStageProviderRegistry;

final class FilingArtifactValidationService
{
    private ComplaintValidator $complaintValidator;
    private SummonsValidator $summonsValidator;
    private ArbitrationCertificateValidator $certificateValidator;

    public function __construct()
    {
        $this->complaintValidator   = new ComplaintValidator();
        $this->summonsValidator     = new SummonsValidator();
        $this->certificateValidator = new ArbitrationCertificateValidator();
    }

    /**
     * Validate full artifact bundle (preview-only, hardened)
     *
     * @param array $artifacts Raw artifacts from FilingArtifactAssemblyService
     * @param array $intent    Canonical filing intent snapshot
     * @param bool  $traceEnabled Optional audit trace mode (default false)
     *
     * @return array
     */
    public function validateBundle(
        array $artifacts,
        array $intent,
        bool $traceEnabled = false
    ): array {

        if (empty($artifacts) || !is_array($artifacts)) {
            return [
                'artifacts' => [],
                'bundle_validation' => [
                    'structural_status'   => 'invalid',
                    'validation_errors'   => ['EMPTY_ARTIFACT_BUNDLE'],
                    'validation_warnings' => [],
                ],
                'is_structurally_ready_for_filing' => false,
            ];
        }

        /* ------------------------------------------------------------
         * Deterministic ordering (prevents drift across runs)
         * ------------------------------------------------------------ */
        usort(
            $artifacts,
            fn($a, $b) =>
                strcmp($a['artifact_type'] ?? '', $b['artifact_type'] ?? '')
        );

        $validatedArtifacts = [];

        /* ------------------------------------------------------------
         * 1️⃣ Per-artifact structural validation
         * ------------------------------------------------------------ */
        foreach ($artifacts as $artifact) {

            if (!is_array($artifact)) {
                continue;
            }

            $artifactType = $artifact['artifact_type'] ?? null;

            $validationResult = match ($artifactType) {

                'complaint' =>
                    $this->complaintValidator->validate($artifact),

                'summons' =>
                    $this->summonsValidator->validate($artifact),

                'certificate_compulsory_arbitration' =>
                    $this->certificateValidator->validate($artifact),

                default => [
                    'structural_status'   => 'invalid',
                    'validation_errors'   => ['UNKNOWN_ARTIFACT_TYPE'],
                    'validation_warnings' => [],
                ],
            };

            $artifact['structural_status']   = $validationResult['structural_status'];
            $artifact['validation_errors']   = array_values(array_unique(
                $validationResult['validation_errors']
            ));
            $artifact['validation_warnings'] = $validationResult['validation_warnings'];

            $validatedArtifacts[] = $artifact;
        }

        /* ------------------------------------------------------------
         * 2️⃣ Resolve action type (deterministic)
         * ------------------------------------------------------------ */
        $actionType =
            $intent['action_type']
            ?? ($validatedArtifacts[0]['action_type'] ?? null);

        if (!is_string($actionType) || $actionType === '') {
            return [
                'artifacts' => $validatedArtifacts,
                'bundle_validation' => [
                    'structural_status'   => 'invalid',
                    'validation_errors'   => ['MISSING_ACTION_TYPE'],
                    'validation_warnings' => [],
                ],
                'is_structurally_ready_for_filing' => false,
            ];
        }

        /* ------------------------------------------------------------
         * 3️⃣ Resolve state-scoped validation stages
         * ------------------------------------------------------------ */
        $provider = StateValidationStageProviderRegistry::for($actionType);
        $stages   = $provider->getStages($actionType);

        $pipeline = new ArtifactValidationPipeline($stages);

        /* ------------------------------------------------------------
         * 4️⃣ Execute deterministic stage pipeline
         * ------------------------------------------------------------ */
        $pipelineResult = $pipeline->validate(
            artifacts: $validatedArtifacts,
            intent: $intent,
            actionType: $actionType,
            traceEnabled: $traceEnabled
        );

        $bundleValidation = [
            'structural_status'   => $pipelineResult['structural_status'],
            'validation_errors'   => $pipelineResult['validation_errors'],
            'validation_warnings' => $pipelineResult['validation_warnings'],
        ];

        // Attach trace only if explicitly requested
        if ($traceEnabled && isset($pipelineResult['trace'])) {
            $bundleValidation['trace'] = $pipelineResult['trace'];
        }

        /* ------------------------------------------------------------
         * 5️⃣ Derived readiness flag (informational only)
         * ------------------------------------------------------------ */
        $allArtifactsStructurallyValid = !array_filter(
            $validatedArtifacts,
            fn($a) => ($a['structural_status'] ?? null) !== 'valid'
        );

        $isStructurallyReady =
            $bundleValidation['structural_status'] === 'valid'
            && $allArtifactsStructurallyValid;

        return [
            'artifacts' => $validatedArtifacts,
            'bundle_validation' => $bundleValidation,
            'is_structurally_ready_for_filing' => $isStructurallyReady,
        ];
    }
}
