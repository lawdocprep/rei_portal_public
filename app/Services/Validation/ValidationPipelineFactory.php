<?php
declare(strict_types=1);

namespace App\Services\Validation;

use App\Services\Validation\ArtifactValidationPipeline;
use App\Services\Validation\Pipeline\BundleInvariantStage;
use App\Services\Validation\Pipeline\ExhibitInvariantStage;
use App\Services\Validation\Pipeline\IntentSnapshotStage;
use App\Services\Validation\Pipeline\PolicyDrivenCrossArtifactStage;

final class ValidationPipelineFactory
{
    public static function forActionType(string $actionType): ArtifactValidationPipeline
    {
        $stateStages = match ($actionType) {

            // -----------------------------------------
            // AZ Forcible Detainer (Phase 16.8)
            // -----------------------------------------
            'az_forcible_detainer' => [
                new IntentSnapshotStage(),
                new PolicyDrivenCrossArtifactStage(),
                new ExhibitInvariantStage(),
                new BundleInvariantStage(),
            ],

            // -----------------------------------------
            // AZ Demand for Possession
            // -----------------------------------------
            'az_demand_for_possession' => [
                new IntentSnapshotStage(),
                new ExhibitInvariantStage(),
                new BundleInvariantStage(),
            ],

            // -----------------------------------------
            // AZ Writ of Restitution
            // -----------------------------------------
            'az_writ_of_restitution' => [
                new IntentSnapshotStage(),
                new PolicyDrivenCrossArtifactStage(),
                new BundleInvariantStage(),
            ],

            default => [],
        };

        return new ArtifactValidationPipeline($stateStages);
    }
}