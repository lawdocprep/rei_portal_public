<?php
declare(strict_types=1);

namespace App\Services\Validation\Stages\Providers;

use App\Services\Validation\Pipeline\ValidationStageInterface;

use App\Services\Validation\Stages\StateValidationStageProviderInterface;

use App\Services\Validation\Pipeline\IntentSnapshotStage;
use App\Services\Validation\Pipeline\ExhibitInvariantStage;

use App\Services\Validation\IntentSnapshotValidator;
use App\Services\Validation\ExhibitInvariantValidator;

use App\Services\Validation\Pipeline\PolicyDrivenCrossArtifactStage;

final class ArizonaValidationStageProvider implements StateValidationStageProviderInterface
{
    /**
     * @return ValidationStageInterface[]
     */
    public function getStages(string $actionType): array
    {
        return [

            /*
             * 1️⃣ Index + required stages are handled inside
             *     ArtifactValidationPipeline default construction.
             *
             *     If you've fully migrated to state-scoped pipeline,
             *     then include your local index/required stages here.
             */

            new IntentSnapshotStage(
                new IntentSnapshotValidator()
            ),

            new ExhibitInvariantStage(
                new ExhibitInvariantValidator()
            ),

            /*
             * 2️⃣ Cross-artifact validation
             *     (now 100% policy-driven)
             */
            new PolicyDrivenCrossArtifactStage(),
        ];
    }
}
