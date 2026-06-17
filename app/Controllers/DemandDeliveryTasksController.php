<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\ProceduralEventEngineService;
use App\Services\Events\CaseEventService;
use App\Auth\AuthContext;
use PDO;
use RuntimeException;

/**
 * Phase 14.4 – Deliverable 4.2
 * Phase 14.5 – Writ Service Completion
 * Phase 14.7 – Certified Mail Actual Delivery + Explicit Event Engine Invocation
 *
 * Service task API backed by demand_delivery_events.
 * This controller now serves as the canonical Service of Process lifecycle
 * (demand, summons, writs, orders), including Return of Writ for execution.
 */
final class DemandDeliveryTasksController
{
    private CaseEventService $events;
    
    public function __construct(private PDO $db)
    {
        $this->events = new CaseEventService($db);
    }

    /* ============================================================
     * GET /cases/{id}/service-tasks
     *
     * Optional query:
     *  - status=pending|sent|delivered
     *  - action_request_id=<uuid>
     * ============================================================ */
    public function listForCase(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId = (string)$ctx->tenantId;
        $caseId = $req['params']['id'] ?? null;
        if (!is_string($caseId) || !$this->isUuid($caseId)) {
            return $this->json(400, ['error' => 'INVALID_CASE_ID']);
        }

        $status = $req['query']['status'] ?? null;
        if ($status !== null) {
            if (!is_string($status) || !in_array($status, ['pending','sent','delivered'], true)) {
                return $this->json(400, ['error' => 'INVALID_STATUS']);
            }
        }

        $actionRequestId = $req['query']['action_request_id'] ?? null;
        if ($actionRequestId !== null && (!is_string($actionRequestId) || !$this->isUuid($actionRequestId))) {
            return $this->json(400, ['error' => 'INVALID_ACTION_REQUEST_ID']);
        }

        $where = [
            'tenant_id = :tenant',
            'case_id = :case_id',
        ];
        $args = [
            ':tenant' => $tenantId,
            ':case_id' => $caseId,
        ];

        if ($status !== null) {
            $where[] = 'delivery_status = :status';
            $args[':status'] = $status;
        }
        if ($actionRequestId !== null) {
            $where[] = 'action_request_id = :arid';
            $args[':arid'] = $actionRequestId;
        }

        $sql = "
            SELECT
                dde.id,
                dde.case_id,
                dde.action_request_id,
                dde.target_id,
                dde.occupant_id,
                dde.delivery_method,
                dde.tracking_number,
                dde.proof_case_document_id,
                dde.delivery_status,
                dde.delivered_at,
                dde.delivered_by,
                dde.address_line1,
                dde.address_line2,
                dde.city,
                dde.state,
                dde.postal_code,
                dde.notes,
                dde.created_at
            FROM demand_delivery_events dde
            WHERE " . implode(' AND ', $where) . "
            ORDER BY dde.created_at DESC
        ";

        $st = $this->db->prepare($sql);
        $st->execute($args);

        return $this->json(200, [
            'case_id' => $caseId,
            'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ]);
    }

    /* ============================================================
     * PATCH /service-tasks/{id}
     *
     * Body (any subset):
     * {
     *   "delivery_status": "pending"|"sent"|"delivered",
     *   "tracking_number": "...",                 // mail/courier
     *   "proof_case_document_id": "<uuid>",       // Return of Writ / affidavit
     *   "notes": "..."
     * }
     *
     * Phase 14.7:
     * - Prevent certified mail "delivered" via PATCH because PATCH
     *   stamps delivered_at = now(). Certified mail requires an
     *   authoritative delivered_at and must emit the delivered event.
     * ============================================================ */
    public function patch(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $tenantId = (string)$ctx->tenantId;
        $actorId  = (string)$ctx->actorId;

        $id = $req['params']['id'] ?? null;
        if (!is_string($id) || !$this->isUuid($id)) {
            return $this->badRequest('INVALID_TASK_ID');
        }

        $body = $req['body'] ?? [];

        $newStatus      = $body['delivery_status'] ?? null;
        $trackingNumber = $body['tracking_number'] ?? null;
        $proofDocId     = $body['proof_case_document_id'] ?? null;
        $notes          = $body['notes'] ?? null;

        $this->db->beginTransaction();

        try {

            /* ------------------------------------------------------------
            * Resolve delivery event (id OR service_task_id)
            * ------------------------------------------------------------ */
            $st = $this->db->prepare("
                SELECT *
                FROM demand_delivery_events
                WHERE tenant_id = :tenant
                AND (id = :id OR service_task_id = :id)
                FOR UPDATE
            ");
            $st->execute([
                ':tenant' => $tenantId,
                ':id'     => $id,
            ]);

            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->db->rollBack();
                return $this->json(404, ['error' => 'SERVICE_TASK_NOT_FOUND']);
            }

            $caseId       = (string)$row['case_id'];
            $eventId      = (string)$row['id'];
            $currentStatus = (string)$row['delivery_status'];
            $targetId     = (string)$row['target_id'];
            $method       = (string)$row['delivery_method'];

            /* ============================================================
            * 🔒 Phase 17 Freeze Guard
            * ============================================================ */
            $guard = new \App\Services\Guard\CaseMutationGuard($this->db);

            try {
                $guard->assertCaseMutable($tenantId, $caseId);
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'CASE_FROZEN_PREPARED_FOR_FILING') {
                    $this->db->rollBack();
                    return $this->json(409, [
                        'error' => 'CASE_FROZEN_PREPARED_FOR_FILING'
                    ]);
                }
                throw $e;
            }

            /* ------------------------------------------------------------
            * Validate delivery status
            * ------------------------------------------------------------ */
            $allowedStatuses = ['pending','sent','delivered'];

            if ($method === 'personal_service') {
                $allowedStatuses[] = 'failed';
            }

            if (!is_string($newStatus) || !in_array($newStatus, $allowedStatuses, true)) {
                return $this->badRequest('INVALID_DELIVERY_STATUS');
            }

            // Certified mail must use explicit endpoint for delivery
            if ($newStatus === 'delivered' && $method === 'certified_mail') {
                throw new \RuntimeException('USE_CERTIFIED_MAIL_DELIVERED_ENDPOINT');
            }

            /* ------------------------------------------------------------
            * Idempotent lifecycle handling
            * ------------------------------------------------------------ */
            if ($currentStatus !== $newStatus) {

                if ($method === 'personal_service') {
                    $allowedTransitions = [
                        'pending'   => ['sent','delivered','failed'],
                        'sent'      => ['delivered','failed'],
                        'failed'    => [],
                        'delivered' => [],
                    ];
                } else {
                    $allowedTransitions = [
                        'pending'   => ['sent','delivered'],
                        'sent'      => ['delivered'],
                        'delivered' => [],
                    ];
                }

                if (!in_array($newStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
                    throw new \RuntimeException('INVALID_STATUS_TRANSITION');
                }

                /* --------------------------------------------------------
                * Update delivery event
                * -------------------------------------------------------- */
                $upd = $this->db->prepare("
                    UPDATE demand_delivery_events
                    SET
                        delivery_status = :status,
                        tracking_number = COALESCE(:tracking, tracking_number),
                        proof_case_document_id = COALESCE(:proof, proof_case_document_id),
                        notes = COALESCE(:notes, notes),
                        delivered_at = CASE
                            WHEN :status = 'delivered' THEN now()
                            ELSE delivered_at
                        END,
                        delivered_by = CASE
                            WHEN :status = 'delivered' THEN :actor
                            ELSE delivered_by
                        END
                    WHERE id = :event_id
                ");

                $upd->execute([
                    ':status'   => $newStatus,
                    ':tracking' => $trackingNumber,
                    ':proof'    => $proofDocId,
                    ':notes'    => $notes,
                    ':actor'    => $actorId,
                    ':event_id' => $eventId,
                ]);

                /* --------------------------------------------------------
                * Phase 15 – personal service failure → posting
                * -------------------------------------------------------- */
                if ($method === 'personal_service' && $newStatus === 'failed') {

                    $svc = new \App\Services\DemandDeliveryTaskService($this->db);

                    $svc->ensurePostingForTargetsOnPersonalFailure(
                        tenantId: $tenantId,
                        caseId: $caseId,
                        actionRequestId: (string)$row['action_request_id'],
                        targets: [[
                            'id' => $targetId,
                            'occupant_id' => $row['occupant_id'] ?? null,
                        ]]
                    );
                }

                /* --------------------------------------------------------
                * Mark target served once
                * -------------------------------------------------------- */
                if ($newStatus === 'delivered') {

                    $st2 = $this->db->prepare("
                        UPDATE case_occupant_action_targets
                        SET status = 'served'
                        WHERE id = :target_id
                        AND tenant_id = :tenant
                        AND status <> 'served'
                    ");

                    $st2->execute([
                        ':target_id' => $targetId,
                        ':tenant'    => $tenantId,
                    ]);
                }

                /* --------------------------------------------------------
                * Certified mail sent event
                * -------------------------------------------------------- */
                if ($newStatus === 'sent' && $method === 'certified_mail') {

                    $occurredAt = gmdate('c');

                    $eventType = 'demand_delivery.certified_mail_sent';

                    $payload = [
                        'action_request_id' => (string)$row['action_request_id'],
                        'service_task_id'   => (string)$row['service_task_id'],
                        'delivery_event_id' => $eventId,
                        'tracking_number'   => is_string($trackingNumber) ? $trackingNumber : null,
                        'carrier'           => 'usps',
                        'mailed_at'         => $occurredAt,
                    ];

                    $this->emitCaseEvent(
                        tenantId: $tenantId,
                        caseId: $caseId,
                        actorId: $actorId,
                        eventType: $eventType,
                        payload: $payload
                    );

                    $engine = new \App\Services\ProceduralEventEngineService($this->db);

                    $engineResult = $engine->applySingleEvent(
                        tenantId: $tenantId,
                        caseId: $caseId,
                        eventType: $eventType,
                        payload: $eventPayload,
                        occurredAtIso: gmdate('c'),
                        replayedByActorId: $actorId,
                        dryRun: false
                    );

                    $spawner =
                        new \App\Services\Procedural\PhaseTransitionsSpawnerService(
                            $this->db
                        );

                    $spawner->processEngineResult(
                        engineResult: $engineResult,
                        tenantId: $tenantId,
                        caseId: $caseId,
                        actorId: $actorId,
                        stateCode: 'AZ'
                    );
                }
            }

            /* ------------------------------------------------------------
            * Return canonical updated record
            * ------------------------------------------------------------ */
            $out = $this->db->prepare("
                SELECT *
                FROM demand_delivery_events
                WHERE id = :id
            ");
            $out->execute([':id' => $eventId]);
            $updated = $out->fetch(PDO::FETCH_ASSOC);

            $this->db->commit();

            return [
                'status' => 200,
                'body' => [
                    'service_task_id'        => $updated['service_task_id'],
                    'delivery_event_id'      => $updated['id'],
                    'delivery_status'        => $updated['delivery_status'],
                    'tracking_number'        => $updated['tracking_number'],
                    'proof_case_document_id' => $updated['proof_case_document_id'],
                    'delivered_at'           => $updated['delivered_at'],
                    'delivered_by'           => $updated['delivered_by'],
                ],
            ];

        } catch (\Throwable $e) {

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    /**
     * POST /service-tasks/{id}/certified-mail/delivered
     *
     * Phase 14.7:
     * Record actual USPS delivery with authoritative delivered_at,
     * emit demand_delivery.certified_mail_delivered, and explicitly
     * invoke the event engine to apply legal effects.
     */
    public function markCertifiedMailDelivered(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $tenantId = (string)$ctx->tenantId;
        $actorId  = (string)$ctx->actorId;

        $taskId = $req['params']['id'] ?? null;
        if (!is_string($taskId) || !$this->isUuid($taskId)) {
            return $this->badRequest('INVALID_TASK_ID');
        }

        $body = $req['body'] ?? [];
        $trackingNumber = $body['tracking_number'] ?? null;
        $proofDocId     = $body['proof_case_document_id'] ?? null;
        $notes          = $body['notes'] ?? null;

        $this->db->beginTransaction();

        try {

            /* ------------------------------------------------------------
            * Lock delivery event
            * ------------------------------------------------------------ */
            $st = $this->db->prepare("
                SELECT *
                FROM demand_delivery_events
                WHERE id = :id
                AND tenant_id = :tenant
                FOR UPDATE
            ");
            $st->execute([
                ':id'     => $taskId,
                ':tenant' => $tenantId,
            ]);

            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->db->rollBack();
                return $this->json(404, ['error' => 'SERVICE_TASK_NOT_FOUND']);
            }

            if ((string)$row['delivery_method'] !== 'certified_mail') {
                $this->db->rollBack();
                return $this->badRequest('INVALID_DELIVERY_METHOD');
            }

            $caseId = (string)$row['case_id'];

            /* ============================================================
            * 🔒 Phase 17 Freeze Guard
            * ============================================================ */
            $guard = new \App\Services\Guard\CaseMutationGuard($this->db);

            try {
                $guard->assertCaseMutable($tenantId, $caseId);
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'CASE_FROZEN_PREPARED_FOR_FILING') {
                    $this->db->rollBack();
                    return $this->json(409, [
                        'error' => 'CASE_FROZEN_PREPARED_FOR_FILING'
                    ]);
                }
                throw $e;
            }

            $currentStatus = (string)$row['delivery_status'];

            /* ------------------------------------------------------------
            * Idempotent success
            * ------------------------------------------------------------ */
            if ($currentStatus === 'delivered') {
                $this->db->commit();
                return $this->json(200, [
                    'ok' => true,
                    'id' => $taskId,
                    'delivery_status' => 'delivered',
                ]);
            }

            /* ------------------------------------------------------------
            * Update as delivered
            * ------------------------------------------------------------ */
            $upd = $this->db->prepare("
                UPDATE demand_delivery_events
                SET
                    delivery_status = 'delivered',
                    tracking_number = COALESCE(:tracking, tracking_number),
                    proof_case_document_id = COALESCE(:proof, proof_case_document_id),
                    notes = COALESCE(:notes, notes),
                    delivered_at = now(),
                    delivered_by = :actor
                WHERE id = :id
                AND tenant_id = :tenant
            ");

            $upd->execute([
                ':tracking' => is_string($trackingNumber) ? $trackingNumber : null,
                ':proof'    => (is_string($proofDocId) && $this->isUuid($proofDocId)) ? $proofDocId : null,
                ':notes'    => is_string($notes) ? $notes : null,
                ':actor'    => $actorId,
                ':id'       => $taskId,
                ':tenant'   => $tenantId,
            ]);

            /* ------------------------------------------------------------
            * Emit canonical case_event (dedupe)
            * ------------------------------------------------------------ */
            $eventType       = 'demand_delivery.certified_mail_delivered';
            $actionRequestId = (string)$row['action_request_id'];

            $eventPayload = [
                'action_request_id'      => $actionRequestId,
                'service_task_id'        => $taskId,
                'delivery_event_id'      => $taskId,
                'tracking_number'        => is_string($trackingNumber) ? $trackingNumber : null,
                'proof_case_document_id' => (is_string($proofDocId) && $this->isUuid($proofDocId)) ? $proofDocId : null,
            ];

            $chk = $this->db->prepare("
                SELECT 1
                FROM case_events
                WHERE tenant_id = :tenant
                AND case_id = :case
                AND event_type = :etype
                AND payload->>'action_request_id' = :arid
                LIMIT 1
            ");

            $chk->execute([
                ':tenant' => $tenantId,
                ':case'   => $caseId,
                ':etype'  => $eventType,
                ':arid'   => $actionRequestId,
            ]);

            if (!$chk->fetchColumn()) {
                $this->emitCaseEvent(
                    $tenantId,
                    $caseId,
                    $actorId,
                    $eventType,
                    $eventPayload
                );
            }

            /* ------------------------------------------------------------
            * Apply procedural engine effects
            * ------------------------------------------------------------ */
            $engine = new \App\Services\ProceduralEventEngineService($this->db);

            $engineResult = $engine->applySingleEvent(
                tenantId: $tenantId,
                caseId: $caseId,
                eventType: $eventType,
                payload: $eventPayload,
                occurredAtIso: gmdate('c'),
                replayedByActorId: $actorId,
                dryRun: false
            );

            $spawner =
                new \App\Services\Procedural\PhaseTransitionsSpawnerService(
                    $this->db
                );

            $spawner->processEngineResult(
                engineResult: $engineResult,
                tenantId: $tenantId,
                caseId: $caseId,
                actorId: $actorId,
                stateCode: 'AZ'
            );

            $this->db->commit();

            return $this->json(200, [
                'ok' => true,
                'id' => $taskId,
                'delivery_status' => 'delivered',
            ]);

        } catch (\Throwable $e) {

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    private function emitCaseEvent(
        string $tenantId,
        string $caseId,
        string $actorId,
        string $eventType,
        array $payload
    ): void {

        $id = $this->events->emitEvent(
            $eventType,
            $payload,
            $tenantId,
            $caseId,
            $actorId
        );

        if (!is_string($id) || $id === '') {
            throw new RuntimeException('CASE_EVENT_WRITE_FAILED');
        }
    }


    private function unauthorized(): array
    {
        return $this->json(401, ['error' => 'AUTH_REQUIRED']);
    }

    private function badRequest(string $code): array
    {
        return $this->json(400, ['error' => $code]);
    }

    private function json(int $status, array $body): array
    {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
        ];
    }

    private function isUuid(string $v): bool
    {
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $v
        );
    }

    /**
     * NOTE:
     * The original snippet references emitCaseEvent() and autoSatisfyDeadlinesFromEvent()
     * but does not include their implementations. This drop-in replacement preserves
     * those calls and adds no new required helpers.
     *
     * If emitCaseEvent() is defined in a trait/base class in your repo, it will continue to work.
     * If it is defined elsewhere, ensure this controller still has access to it.
     */
}
