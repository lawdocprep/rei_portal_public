<?php
declare(strict_types=1);

namespace App\Services\Procedures;

use App\Services\ProceduralAction;
use App\Services\ProcedureContext;

final class VerifyOccupancyProcedure implements ProceduralAction
{
    public function run(ProcedureContext $ctx): array
    {
        // TODO: implement real workflow (inspection, photos, notes, etc.)
        // For now: emit a canonical event.
        $ctx->emitCaseEvent('occupancy_verified', [
            'title' => 'Occupancy Verified',
            'status' => 'completed',
        ]);

        return [
            'executed_at' => $ctx->nowIso,
            'executed_by' => $ctx->actorId,
            'generated_documents' => [],
            'emitted_events' => [['event_type' => 'occupancy_verified']],
            'created_deadlines' => [],
            'new_phase' => 'pre_filing',
        ];
    }
}
