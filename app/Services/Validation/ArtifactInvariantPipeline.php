<?php
declare(strict_types=1);

namespace App\Services\Validation;

final class ArtifactInvariantPipeline
{
    private IntentSnapshotValidator $intentSnapshotValidator;
    private BundleInvariantValidator $bundleInvariantValidator;
    private ExhibitInvariantValidator $exhibitInvariantValidator;

    public function __construct()
    {
        $this->intentSnapshotValidator  = new IntentSnapshotValidator();
        $this->bundleInvariantValidator = new BundleInvariantValidator();
        $this->exhibitInvariantValidator = new ExhibitInvariantValidator();
    }

    /**
     * Executes all bundle-level invariant validators in deterministic order.
     *
     * This is Phase 16.7 structural enforcement only.
     * No DB mutation.
     * No filing.
     * No jurisdiction enforcement.
     */
    public function validate(array $artifacts, array $intent): array
    {
        $allErrors = [];
        $allWarnings = [];

        /* ------------------------------------------------------------
         * 1️⃣ Intent Snapshot Integrity
         * ------------------------------------------------------------ */
        $intentResult = $this->intentSnapshotValidator->validate(
            $artifacts,
            $intent
        );

        $allErrors   = array_merge($allErrors,   $intentResult['validation_errors']);
        $allWarnings = array_merge($allWarnings, $intentResult['validation_warnings']);

        /* ------------------------------------------------------------
         * 2️⃣ Core Bundle Invariants
         * ------------------------------------------------------------ */
        $bundleResult = $this->bundleInvariantValidator->validate(
            $artifacts
        );

        $allErrors   = array_merge($allErrors,   $bundleResult['validation_errors']);
        $allWarnings = array_merge($allWarnings, $bundleResult['validation_warnings']);

        /* ------------------------------------------------------------
         * 3️⃣ Exhibit Invariants
         * ------------------------------------------------------------ */
        $exhibitResult = $this->exhibitInvariantValidator->validate(
            $artifacts
        );

        $allErrors   = array_merge($allErrors,   $exhibitResult['validation_errors']);
        $allWarnings = array_merge($allWarnings, $exhibitResult['validation_warnings']);

        return [
            'structural_status'   => empty($allErrors) ? 'valid' : 'invalid',
            'validation_errors'   => array_values(array_unique($allErrors)),
            'validation_warnings' => array_values(array_unique($allWarnings)),
        ];
    }
}
