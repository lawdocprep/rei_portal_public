<?php
declare(strict_types=1);

namespace App\Services\Validation;

final class CrossArtifactBundleValidator
{
    /**
     * CrossArtifactBundleValidator is now a thin façade.
     *
     * All structural invariants are delegated to the
     * ArtifactValidationPipeline.
     */
    public function validate(array $artifacts): array
    {
        if (empty($artifacts) || !is_array($artifacts)) {
            return [
                'structural_status'   => 'invalid',
                'validation_errors'   => ['EMPTY_ARTIFACT_BUNDLE'],
                'validation_warnings' => [],
            ];
        }

        $pipeline = new ArtifactValidationPipeline();

        return $pipeline->validateBundle($artifacts);
    }
}
