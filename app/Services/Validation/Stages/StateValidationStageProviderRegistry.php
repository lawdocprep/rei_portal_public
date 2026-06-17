<?php
declare(strict_types=1);

namespace App\Services\Validation\Stages;

use App\Services\Validation\Stages\Providers\ArizonaValidationStageProvider;
use RuntimeException;

final class StateValidationStageProviderRegistry
{
    public static function for(string $actionType): StateValidationStageProviderInterface
    {
        if (str_starts_with($actionType, 'az_')) {
            return new ArizonaValidationStageProvider();
        }

        throw new RuntimeException('NO_STATE_VALIDATION_STAGE_PROVIDER');
    }
}
