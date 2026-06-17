<?php
declare(strict_types=1);

namespace App\Services\Procedures;

use App\Services\ProceduralAction;
use App\Services\ProcedureContext;
use PDO;
use RuntimeException;

final class AzWritServiceProcedure implements ProceduralAction
{
    public function run(ProcedureContext $ctx): array
    {
        // Defensive: this procedure is for the specific action type
        if ($ctx->requestedAction !== 'az_writ_service_test') {
            throw new RuntimeException('PROCEDURE_ACTION_MISMATCH');
        }

        $db = $ctx->db;

        // Transaction makes target sync + event emission atomic.
        $db->beginTransaction();

        try {
            // 1) Load required targets for this request
            $targets = $this->loadRequiredTargets($db, $ctx->tenantId, $ctx->caseId, $ctx->requestId);

            if (count($targets) === 0) {
                throw new RuntimeException('NO_REQUIRED_TARGETS');
            }

            // 2) Load delivery events for those targets
            $eventsByTargetId = $this->loadDeliveryEventsByTargetId($db, $ctx->tenantId, $ctx->caseId, array_keys($targets));

            // 3) Validate: each required target has a delivery event and is delivered
            $missing = [];
            $notDelivered = [];

            foreach ($targets as $targetId => $t) {
                $ev = $eventsByTargetId[$targetId] ?? null;
                if (!is_array($ev)) {
                    $missing[] = $targetId;
                    continue;
                }

                $status = (string)($ev['delivery_status'] ?? '');
                if ($status !== 'delivered') {
                    $notDelivered[] = [
                        'target_id' => $targetId,
                        'delivery_event_id' => (string)($ev['id'] ?? ''),
                        'delivery_status' => $status,
                    ];
                }
            }

            if (count($missing) > 0) {
                throw new RuntimeException('MISSING_DEMAND_DELIVERY_EVENT');
            }

            if (count($notDelivered) > 0) {
                // Fail closed; UI must complete delivery + proof first.
                throw new RuntimeException('DELIVERY_INCOMPLETE');
            }

            // 4) Mark required targets as served (idempotent)
            $this->markTargetsServed($db, $ctx->tenantId, $ctx->caseId, $ctx->requestId);

            // 5) Emit phase-triggering event (phase_transitions listens for writ_served)
            // Include keys to satisfy jsonb subset conditions:
            // - require_all_targets_served = true
            // - require_all_deliveries_delivered = true
            $payloadTargets = [];
            $methods = [];

            foreach ($targets as $targetId => $t) {
                $ev = $eventsByTargetId[$targetId];
                $method = (string)($ev['delivery_method'] ?? '');
                if ($method !== '') $methods[$method] = true;

                $payloadTargets[] = [
                    'target_id' => $targetId,
                    'occupant_id' => (string)$t['occupant_id'],
                    'delivery_event_id' => (string)$ev['id'],
                    'delivery_method' => $method,
                    'delivery_status' => (string)$ev['delivery_status'],
                    'tracking_number' => $ev['tracking_number'] ?? null,
                    'proof_case_document_id' => $ev['proof_case_document_id'] ?? null,
                    'delivered_at' => $ev['delivered_at'] ?? null,
                ];
            }

            $ctx->emitCaseEvent('writ_served', [
                'action_request_id' => $ctx->requestId,
                'action_type' => $ctx->requestedAction,
                'phase_key' => (string)($targets[array_key_first($targets)]['phase_key'] ?? ''),
                'methods' => array_values(array_keys($methods)),
                'require_all_targets_served' => true,
                'require_all_deliveries_delivered' => true,
                'targets' => $payloadTargets,
            ]);

            $db->commit();

            return [
                'emitted_events' => ['writ_served'],
                'created_deadlines' => [],
                'new_phase' => 'az_writ_executed',
            ];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            // Surface structured failure for the executor bundle
            // (your executor likely wraps exceptions; keep message stable)
            throw $e;
        }
    }

    private function loadRequiredTargets(PDO $db, string $tenantId, string $caseId, string $requestId): array
    {
        $st = $db->prepare("
            SELECT id, occupant_id, phase_key
            FROM case_occupant_action_targets
            WHERE tenant_id = :tenant
              AND case_id = :case
              AND action_request_id = :req
              AND required = true
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case' => $caseId,
            ':req' => $requestId,
        ]);

        $out = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(string)$r['id']] = [
                'id' => (string)$r['id'],
                'occupant_id' => (string)$r['occupant_id'],
                'phase_key' => (string)$r['phase_key'],
            ];
        }
        return $out;
    }

    private function loadDeliveryEventsByTargetId(PDO $db, string $tenantId, string $caseId, array $targetIds): array
    {
        // Use = ANY(:ids) for uuid[]; PDO needs explicit array->pg string cast
        $idsLiteral = '{' . implode(',', array_map(static fn($id) => '"' . $id . '"', $targetIds)) . '}';

        $st = $db->prepare("
            SELECT
                dde.id,
                dde.target_id,
                dde.delivery_method,
                dde.delivery_status,
                dde.tracking_number,
                dde.proof_case_document_id,
                dde.delivered_at
            FROM demand_delivery_events dde
            WHERE dde.target_id = ANY(:ids::uuid[])
        ");

        $st->execute([
            ':ids' => $idsLiteral,
        ]);

        $out = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(string)$r['target_id']] = [
                'id' => (string)$r['id'],
                'target_id' => (string)$r['target_id'],
                'delivery_method' => $r['delivery_method'] ?? null,
                'delivery_status' => (string)$r['delivery_status'],
                'tracking_number' => $r['tracking_number'] ?? null,
                'proof_case_document_id' => $r['proof_case_document_id'] ?? null,
                'delivered_at' => $r['delivered_at'] ?? null,
            ];
        }
        return $out;
    }

    private function markTargetsServed(PDO $db, string $tenantId, string $caseId, string $requestId): void
    {
        $st = $db->prepare("
            UPDATE case_occupant_action_targets
            SET status = 'served'
            WHERE tenant_id = :tenant
              AND case_id = :case
              AND action_request_id = :req
              AND required = true
              AND status <> 'served'
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case' => $caseId,
            ':req' => $requestId,
        ]);
    }
}
