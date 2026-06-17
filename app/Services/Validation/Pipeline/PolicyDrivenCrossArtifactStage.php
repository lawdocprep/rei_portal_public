<?php
declare(strict_types=1);

namespace App\Services\Validation\Pipeline;

use App\Services\Validation\ValidationPipelineContext;
use App\Services\Validation\ArtifactPolicyRegistry;

final class PolicyDrivenCrossArtifactStage implements ValidationStageInterface
{
    public function name(): string
    {
        return 'policy_cross_artifact';
    }

    public function execute(ValidationPipelineContext $ctx): void
    {
        // ✅ Option A — property access
        $actionType = $ctx->actionType ?? null;
        $artifacts  = $ctx->artifacts ?? [];

        if (!is_string($actionType) || $actionType === '') {
            return;
        }

        if (empty($artifacts)) {
            return;
        }

        $policy = ArtifactPolicyRegistry::getActionPolicy($actionType);
        if (!$policy) {
            return;
        }

        $rules = $policy['cross_artifact_consistency'] ?? [];
        if (empty($rules)) {
            return;
        }

        foreach ($rules as $rule) {

            $field         = $rule['field'] ?? null;
            $artifactTypes = $rule['artifacts'] ?? [];
            $required      = $rule['required'] ?? false;
            $canonicalizer = $rule['canonicalizer'] ?? null;
            $errorPrefix   = $rule['error_code_prefix'] ?? 'CROSS_ARTIFACT';

            if (!$field || !$canonicalizer || !is_array($artifactTypes)) {
                continue;
            }

            $values = [];

            foreach ($artifacts as $artifact) {

                if (!is_array($artifact)) {
                    continue;
                }

                $type = $artifact['artifact_type'] ?? null;
                if (!in_array($type, $artifactTypes, true)) {
                    continue;
                }

                $content = $artifact['content'] ?? null;
                if (!is_array($content)) {
                    continue;
                }

                if (!array_key_exists($field, $content)) {
                    if ($required) {
                        $ctx->addError(
                            $errorPrefix . '_MISSING_' . strtoupper($type)
                        );
                    }
                    continue;
                }

                $normalized = $canonicalizer::normalize(
                    (array)$content[$field]
                );

                $values[$type] = $normalized;
            }

            if (count($values) <= 1) {
                continue; // nothing to compare
            }

            $baseline = reset($values);

            foreach ($values as $type => $val) {
                if ($val !== $baseline) {
                    $ctx->addError(
                        $errorPrefix . '_MISMATCH_' . strtoupper($type)
                    );
                }
            }
        }
    }
}