<?php
declare(strict_types=1);

namespace App\Services\Validation\Stages;

use App\Services\Validation\ValidationStageInterface;

interface StateValidationStageProviderInterface
{
    /**
     * @return ValidationStageInterface[]
     */
    public function getStages(string $actionType): array;
}
