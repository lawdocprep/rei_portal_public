<?php
declare(strict_types=1);

namespace App\Services\Procedures;

use App\Services\ProceduralAction;
use App\Services\ProcedureContext;

final class WritOfRestitutionProcedure implements ProceduralAction
{
    public function run(ProcedureContext $ctx): array
    {
        // TODO: generate writ document + clerk issuance step, etc.
        $ctx->emitCaseEvent('writ_of_restitution_issued', [
            'title' => 'Writ of Restitution Issued',
            'status' => 'completed',
            'legal_kind' => 'judgment',
        ]);

        return [
            'executed_at' => $ctx->nowIso,
            'executed_by' => $ctx->actorId,
            'generated_documents' => [],
            'emitted_events' => [['event_type' => 'writ_of_restitution_issued']],
            'created_deadlines' => [],
            'new_phase' => 'post_judgment',
        ];
    }
}
