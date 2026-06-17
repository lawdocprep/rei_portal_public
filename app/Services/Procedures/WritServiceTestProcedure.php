<?php
declare(strict_types=1);

namespace App\Services\Procedures;

use App\Services\ProceduralAction;
use App\Services\ProcedureContext;
use PDO;

final class WritServiceTestProcedure implements ProceduralAction
{
    public function run(ProcedureContext $ctx): array
    {
        // Completion = all targets (for this action_request) are served.
        // We derive targets by joining demand_delivery_events -> case_occupant_action_targets.
        $st = $ctx->db->prepare("
            SELECT
                COUNT(*)::int AS total_targets,
                SUM(CASE WHEN t.status = 'served' THEN 1 ELSE 0 END)::int AS served_targets
            FROM demand_delivery_events dde
            JOIN case_occupant_action_targets t
              ON t.id = dde.target_id
            WHERE dde.tenant_id = :tenant
              AND dde.case_id = :case
              AND dde.action_request_id = :arid
        ");
        $st->execute([
            ':tenant' => $ctx->tenantId,
            ':case' => $ctx->caseId,
            ':arid' => $ctx->requestId,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $total = (int)($row['total_targets'] ?? 0);
        $served = (int)($row['served_targets'] ?? 0);

        $isComplete = ($total > 0) && ($served === $total);

        $emitted = [];
        $newPhase = null;

        if ($isComplete) {
            // Idempotency: only emit writ_served once per action_request_id
            $st2 = $ctx->db->prepare("
                SELECT 1
                FROM case_events
                WHERE tenant_id = :tenant
                  AND case_id = :case
                  AND event_type = 'writ_served'
                  AND (payload->>'action_request_id') = :arid
                LIMIT 1
            ");
            $st2->execute([
                ':tenant' => $ctx->tenantId,
                ':case' => $ctx->caseId,
                ':arid' => $ctx->requestId,
            ]);
            $already = (bool)$st2->fetchColumn();

            if (!$already) {
                $payload = [
                    'action_request_id' => $ctx->requestId,
                    'requested_action' => $ctx->requestedAction,
                    'served_targets' => $served,
                    'total_targets' => $total,
                ];
                $ctx->emitCaseEvent('writ_served', $payload);

                $emitted[] = [
                    'event_type' => 'writ_served',
                    'payload' => $payload,
                ];
            } else {
                $emitted[] = [
                    'event_type' => 'writ_served',
                    'payload' => [
                        'action_request_id' => $ctx->requestId,
                        'already_emitted' => true,
                    ],
                ];
            }

            $newPhase = 'az_writ_executed';
        }

        return [
            'procedure' => 'az_writ_service_test',
            'request_id' => $ctx->requestId,
            'case_id' => $ctx->caseId,
            'executed_at' => $ctx->nowIso,

            // progress + gating
            'total_targets' => $total,
            'served_targets' => $served,
            'is_complete' => $isComplete,

            // transition signals
            'emitted_events' => $emitted,
            'new_phase' => $newPhase,
        ];
    }
}
