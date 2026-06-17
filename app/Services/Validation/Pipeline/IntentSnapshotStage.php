<?php
declare(strict_types=1);

namespace App\Services\Validation\Pipeline;

use App\Services\Validation\ValidationPipelineContext;
use App\Services\Validation\IntentSnapshotValidator;

final class IntentSnapshotStage implements ValidationStageInterface
{
    private IntentSnapshotValidator $validator;

    public function __construct(
        ?IntentSnapshotValidator $validator = null
    ) {
        $this->validator = $validator ?? new IntentSnapshotValidator();
    }

    public function name(): string
    {
        return 'intent_snapshot';
    }

    /**
     * Phase 16.7 — Canonical frozen intent validation
     *
     * Uses:
     *   $ctx->intent     (authoritative snapshot)
     *   $ctx->artifacts  (validated envelopes)
     */
    public function execute(ValidationPipelineContext $ctx): void
    {
        $result = $this->validator->validate(
            $ctx->intent,
            $ctx->artifacts
        );

        foreach ($result['validation_errors'] ?? [] as $error) {
            if (is_string($error) && $error !== '') {
                $ctx->addError($error);
            }
        }

        foreach ($result['validation_warnings'] ?? [] as $warning) {
            if (is_string($warning) && $warning !== '') {
                $ctx->addWarning($warning);
            }
        }
    }
}
