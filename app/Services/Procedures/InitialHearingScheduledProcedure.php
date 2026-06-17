<?php
declare(strict_types=1);

namespace App\Services\Procedures;

use App\Services\ProceduralAction;
use App\Services\ProcedureContext;

final class InitialHearingScheduledProcedure implements ProceduralAction
{
    public function run(ProcedureContext $ctx): array
    {
        $meta = is_array($ctx->larPayload['metadata'] ?? null) ? $ctx->larPayload['metadata'] : [];

        // Accept YYYY-MM-DD; default +7 days if omitted.
        $hearingDate = is_string($meta['hearing_date'] ?? null) ? $meta['hearing_date'] : null;
        if ($hearingDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hearingDate)) {
            throw new \RuntimeException('INVALID_REQUEST');
        }
        if ($hearingDate === null) {
            $hearingDate = gmdate('Y-m-d', time() + 7 * 86400);
        }

        $ctx->emitCaseEvent('initial_hearing_scheduled', [
            'hearing_date' => $hearingDate,
            'title' => 'Initial Hearing Scheduled',
        ]);

        return [
            'executed_at' => $ctx->nowIso,
            'executed_by' => $ctx->actorId,
            'generated_documents' => [], // summons stub can be added later
            'emitted_events' => [['event_type' => 'initial_hearing_scheduled']],
            'created_deadlines' => [],
            'new_phase' => 'hearing_scheduled',
        ];
    }
}
