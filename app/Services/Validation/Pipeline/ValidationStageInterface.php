<?php
declare(strict_types=1);

namespace App\Services\Validation\Pipeline;

use App\Services\Validation\ValidationPipelineContext;

interface ValidationStageInterface
{
    public function name(): string;

    public function execute(ValidationPipelineContext $ctx): void;
}
