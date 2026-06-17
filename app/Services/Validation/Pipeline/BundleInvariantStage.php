<?php
declare(strict_types=1);

namespace App\Services\Validation\Pipeline;

use App\Services\Validation\ValidationPipelineContext;
use App\Services\Validation\BundleInvariantValidator;

final class BundleInvariantStage implements ValidationStageInterface
{
    private BundleInvariantValidator $validator;

    public function __construct()
    {
        $this->validator = new BundleInvariantValidator();
    }

    public function name(): string
    {
        return 'bundle_invariant';
    }

    /**
     * Object-based pipeline contract.
     * Mutates ValidationPipelineContext directly.
     */
    public function execute(ValidationPipelineContext $ctx): void
    {
        // Extract both required inputs from context
        $intent    = $ctx->intent;
        $artifacts = $ctx->artifacts;

        $result = $this->validator->validate($intent, $artifacts);

        foreach ($result['validation_errors'] ?? [] as $e) {
            if (is_string($e) && $e !== '') {
                $ctx->addError($e);
            }
        }

        foreach ($result['validation_warnings'] ?? [] as $w) {
            if (is_string($w) && $w !== '') {
                $ctx->addWarning($w);
            }
        }
    }
}