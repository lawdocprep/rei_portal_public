<?php
declare(strict_types=1);

namespace App\Services\Procedures;

use App\Services\ProceduralAction;
use App\Services\ProcedureContext;
use PDO;

final class ServeLawsuitProcedure implements ProceduralAction
{
    // Adjust if your DB uses a different success value.
    private const SUCCESS_OUTCOME = 'completed';

    // Allowed service methods for lawsuit service in your system
    private const ALLOWED_METHODS = [
        'personal_service',
        'substitute_service',
        'certified_mail',
        'posted_on_property',
        'courier_delivery',
    ];

    public function run(ProcedureContext $ctx): array
    {
        $meta = is_array($ctx->larPayload['metadata'] ?? null)
            ? $ctx->larPayload['metadata']
            : [];

        // Optional explicit binding: metadata.process_service_event_ids = [uuid, ...]
        $explicitIds = $meta['process_service_event_ids'] ?? null;
        if ($explicitIds !== null && !is_array($explicitIds)) {
            throw new \RuntimeException('INVALID_REQUEST');
        }
        $explicitIds = is_array($explicitIds) ? array_values($explicitIds) : [];

        foreach ($explicitIds as $id) {
            if (!is_string($id) || !preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
                throw new \RuntimeException('INVALID_REQUEST');
            }
        }

        // Optional filter: metadata.methods = ['personal_service', ...]
        $methods = $meta['methods'] ?? null;
        if ($methods !== null && !is_array($methods)) {
            throw new \RuntimeException('INVALID_REQUEST');
        }
        $methods = is_array($methods) ? array_values($methods) : [];

        $methods = array_values(array_unique(array_filter($methods, function ($v) {
            return is_string($v) && in_array($v, self::ALLOWED_METHODS, true);
        })));

        $rows = $this->loadServiceEvents($ctx, $explicitIds, $methods);

        // Require at least one successful service event
        if (count($rows) < 1) {
            throw new \RuntimeException('PHASE_BLOCKED');
        }

        // Compute service_date anchor (earliest successful service by default)
        // If you prefer "last service controls deadlines", switch to max.
        $serviceDates = array_values(array_filter(array_map(
            fn($r) => is_string($r['service_date'] ?? null) ? (string)$r['service_date'] : null,
            $rows
        )));
        sort($serviceDates);
        $serviceDate = $serviceDates[0] ?? gmdate('Y-m-d');

        $eventIds = array_map(fn($r) => (string)$r['id'], $rows);
        $usedMethods = array_values(array_unique(array_map(fn($r) => (string)$r['service_method'], $rows)));

        // Emit canonical timeline event referencing the service events
        $ctx->emitCaseEvent('lawsuit_served', [
            'service_date' => $serviceDate,
            'process_service_event_ids' => $eventIds,
            'service_methods' => $usedMethods,
            'title' => 'Lawsuit Served',
        ]);

        return [
            'executed_at' => $ctx->nowIso,
            'executed_by' => $ctx->actorId,
            'generated_documents' => [], // proof of service forms later (template system)
            'emitted_events' => [['event_type' => 'lawsuit_served']],
            'created_deadlines' => [],
            'linked_process_service_event_ids' => $eventIds,
            'new_phase' => 'service_completed',
        ];
    }

    /**
     * @return array<int, array{id:string, service_method:string, service_date:string}>
     */
    private function loadServiceEvents(
        ProcedureContext $ctx,
        array $explicitIds,
        array $methodsFilter
    ): array {
        if (count($explicitIds) > 0) {
            // Validate explicit IDs belong to this tenant+case and are successful
            $placeholders = implode(',', array_fill(0, count($explicitIds), '?'));

            $sql = "
                SELECT id, service_method, service_date
                FROM process_service_events
                WHERE tenant_id = ?
                  AND case_id = ?
                  AND id IN ($placeholders)
                  AND outcome = ?
            ";

            $params = array_merge(
                [$ctx->tenantId, $ctx->caseId],
                $explicitIds,
                [self::SUCCESS_OUTCOME]
            );

            // Optional filter by methods
            if (count($methodsFilter) > 0) {
                $mPH = implode(',', array_fill(0, count($methodsFilter), '?'));
                $sql .= " AND service_method IN ($mPH)";
                $params = array_merge($params, $methodsFilter);
            }

            $sql .= " ORDER BY service_date ASC";

            $st = $ctx->db->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // Auto-select successful service events for the case
        $sql = "
            SELECT id, service_method, service_date
            FROM process_service_events
            WHERE tenant_id = :tenant
              AND case_id = :case
              AND outcome = :outcome
        ";

        $params = [
            ':tenant' => $ctx->tenantId,
            ':case' => $ctx->caseId,
            ':outcome' => self::SUCCESS_OUTCOME,
        ];

        if (count($methodsFilter) > 0) {
            $mPH = implode(',', array_fill(0, count($methodsFilter), '?'));
            // Switch to positional binding for this branch to keep it simple
            $sql = "
                SELECT id, service_method, service_date
                FROM process_service_events
                WHERE tenant_id = ?
                  AND case_id = ?
                  AND outcome = ?
                  AND service_method IN ($mPH)
                ORDER BY service_date ASC
            ";

            $params2 = array_merge([$ctx->tenantId, $ctx->caseId, self::SUCCESS_OUTCOME], $methodsFilter);
            $st = $ctx->db->prepare($sql);
            $st->execute($params2);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $sql .= " ORDER BY service_date ASC";

        $st = $ctx->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
