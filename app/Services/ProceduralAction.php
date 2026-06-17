<?php
declare(strict_types=1);

namespace App\Services;

interface ProceduralAction
{
    /**
     * Must be idempotent at the orchestrator level; procedures should assume
     * they are called only once per requestId.
     */
    public function run(ProcedureContext $ctx): array;
}
