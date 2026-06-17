<?php
declare(strict_types=1);

namespace App\Services\Procedures;

use App\Services\ProceduralAction;
use App\Services\ProcedureContext;

final class EvictionComplaintProcedure implements ProceduralAction
{
    public function run(ProcedureContext $ctx): array
    {
        // TODO: generate complaint + summons + cover sheet; create case_documents; etc.
        $ctx->emitCaseEvent('complaint_filed', [
            'title' => 'Eviction Complaint Filed',
            'status' => 'completed',
            'legal_kind' => 'eviction',
        ]);

        return [
            'executed_at' => $ctx->nowIso,
            'executed_by' => $ctx->actorId,
            'generated_documents' => [],
            'emitted_events' => [['event_type' => 'complaint_filed']],
            'created_deadlines' => [],
            'new_phase' => 'filing',
        ];
    }
}
