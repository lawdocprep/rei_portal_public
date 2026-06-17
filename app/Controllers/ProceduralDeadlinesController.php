<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Events\CaseEventService;
use App\Auth\AuthContext;
use PDO;
use RuntimeException;

final class ProceduralDeadlinesController
{
    private CaseEventService $events;

    public function __construct(private PDO $db)
    {
        $this->events = new CaseEventService($db);
    }

    /* ============================================================
     * POST /procedural-deadlines
     * Create a single court deadline (manual entry or system entry)
     * ============================================================ */
    public function create(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId = (string)$ctx->tenantId;
        $actorId  = (string)$ctx->actorId;
        $roles    = $ctx->roles ?? [];

        $body = $req['body'] ?? null;
        if (!is_array($body)) return $this->badRequest('Invalid JSON body.');

        $caseId        = $body['case_id'] ?? null;
        $phaseKey      = $body['phase_key'] ?? null;
        $obligationKey = $body['obligation_key'] ?? null;
        $displayName   = $body['display_name'] ?? null;
        $dueAt         = $body['due_at'] ?? null;

        if (!is_string($caseId) || $caseId === '') return $this->badRequest('case_id is required.');
        if (!is_string($phaseKey) || $phaseKey === '') return $this->badRequest('phase_key is required.');
        if (!is_string($obligationKey) || $obligationKey === '') return $this->badRequest('obligation_key is required.');
        if (!is_string($displayName) || $displayName === '') return $this->badRequest('display_name is required.');
        if (!is_string($dueAt) || $dueAt === '') return $this->badRequest('due_at is required.');

        $required      = isset($body['required']) ? (bool)$body['required'] : true;
        $blocksPhase   = $body['blocks_phase_key'] ?? null;
        if ($blocksPhase !== null && !is_string($blocksPhase)) {
            return $this->badRequest('blocks_phase_key must be string or null.');
        }

        $source = $body['source'] ?? 'court_order';
        if (!is_string($source) || $source === '') $source = 'court_order';

        $sourceDocId = $body['source_document_id'] ?? null;
        if ($sourceDocId !== null && !is_string($sourceDocId)) {
            return $this->badRequest('source_document_id must be string or null.');
        }

        $payload = $body['payload'] ?? [];
        if (!is_array($payload)) return $this->badRequest('payload must be an object.');

        // Permission: prefer tenant_phase_permissions if present; otherwise allow admins/legal roles
        $this->requirePhasePermission($tenantId, $phaseKey, $roles, 'create');

        $this->db->beginTransaction();
        try {
            $deadlineId = $this->insertDeadline([
                'tenant_id' => $tenantId,
                'case_id'   => $caseId,
                'phase_key' => $phaseKey,
                'obligation_key' => $obligationKey,
                'display_name'   => $displayName,
                'due_at'         => $dueAt,
                'required'       => $required,
                'blocks_phase_key' => $blocksPhase,
                'status'         => 'pending',
                'payload'        => array_merge($payload, [
                    'source' => $source,
                    'source_document_id' => $sourceDocId,
                ]),
            ]);

            $this->emitCaseEvent($tenantId, $caseId, $actorId, 'procedural_deadline_created', [
                'deadline_id'    => $deadlineId,
                'phase_key'      => $phaseKey,
                'obligation_key' => $obligationKey,
                'display_name'   => $displayName,
                'due_at'         => $dueAt,
                'required'       => $required,
                'blocks_phase_key' => $blocksPhase,
                'source'         => $source,
                'source_document_id' => $sourceDocId,
            ]);

            $this->db->commit();

            return [
                'status' => 201,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => [
                    'status' => 'ok',
                    'id' => $deadlineId,
                ],
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /* ============================================================
     * POST /procedural-deadlines/ingest
     * Bulk ingest (AI output) — inserts deadlines + audit event
     * ============================================================ */
    public function ingest(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId = (string)$ctx->tenantId;
        $actorId  = (string)$ctx->actorId;
        $roles    = $ctx->roles ?? [];

        $body = $req['body'] ?? null;
        if (!is_array($body)) return $this->badRequest('Invalid JSON body.');

        $caseId = $body['case_id'] ?? null;
        if (!is_string($caseId) || $caseId === '') return $this->badRequest('case_id is required.');

        $source = $body['source'] ?? 'ai_extraction';
        if (!is_string($source) || $source === '') $source = 'ai_extraction';

        $sourceDocId = $body['source_document_id'] ?? null;
        if ($sourceDocId !== null && !is_string($sourceDocId)) {
            return $this->badRequest('source_document_id must be string or null.');
        }

        $items = $body['items'] ?? null;
        if (!is_array($items) || count($items) === 0) {
            return $this->badRequest('items must be a non-empty array.');
        }

        $created = [];

        $this->db->beginTransaction();
        try {
            foreach ($items as $i => $it) {
                if (!is_array($it)) {
                    return $this->badRequest("items[$i] must be an object.");
                }

                $phaseKey      = $it['phase_key'] ?? null;
                $obligationKey = $it['obligation_key'] ?? null;
                $displayName   = $it['display_name'] ?? null;
                $dueAt         = $it['due_at'] ?? null;

                if (!is_string($phaseKey) || $phaseKey === '') return $this->badRequest("items[$i].phase_key is required.");
                if (!is_string($obligationKey) || $obligationKey === '') return $this->badRequest("items[$i].obligation_key is required.");
                if (!is_string($displayName) || $displayName === '') return $this->badRequest("items[$i].display_name is required.");
                if (!is_string($dueAt) || $dueAt === '') return $this->badRequest("items[$i].due_at is required.");

                $required    = isset($it['required']) ? (bool)$it['required'] : true;
                $blocksPhase = $it['blocks_phase_key'] ?? null;
                if ($blocksPhase !== null && !is_string($blocksPhase)) {
                    return $this->badRequest("items[$i].blocks_phase_key must be string or null.");
                }

                $payload = $it['payload'] ?? [];
                if (!is_array($payload)) return $this->badRequest("items[$i].payload must be an object.");

                // permission (per phase)
                $this->requirePhasePermission($tenantId, $phaseKey, $roles, 'create');

                $deadlineId = $this->insertDeadline([
                    'tenant_id' => $tenantId,
                    'case_id'   => $caseId,
                    'phase_key' => $phaseKey,
                    'obligation_key' => $obligationKey,
                    'display_name'   => $displayName,
                    'due_at'         => $dueAt,
                    'required'       => $required,
                    'blocks_phase_key' => $blocksPhase,
                    'status'         => 'pending',
                    'payload'        => array_merge($payload, [
                        'source' => $source,
                        'source_document_id' => $sourceDocId,
                    ]),
                ]);

                $created[] = $deadlineId;
            }

            $this->emitCaseEvent($tenantId, $caseId, $actorId, 'procedural_deadlines_ingested', [
                'source' => $source,
                'source_document_id' => $sourceDocId,
                'count' => count($created),
                'deadline_ids' => $created,
            ]);

            $this->db->commit();

            return [
                'status' => 201,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => [
                    'status' => 'ok',
                    'created' => $created,
                ],
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /* ============================================================
     * POST /procedural-deadlines/{id}/satisfy
     * Explicit satisfaction (audit-grade)
     * ============================================================ */
    public function satisfy(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId = (string)$ctx->tenantId;
        $actorId  = (string)$ctx->actorId;
        $roles    = $ctx->roles ?? [];

        $deadlineId = $req['params']['id'] ?? null;
        if (!is_string($deadlineId) || $deadlineId === '') {
            return $this->badRequest('Missing deadline id.');
        }

        $body = $req['body'] ?? null;
        if (!is_array($body)) return $this->badRequest('Invalid JSON body.');

        $satisfiedVia = $body['satisfied_via'] ?? null; // e.g. "document_upload"
        if (!is_string($satisfiedVia) || $satisfiedVia === '') {
            return $this->badRequest('satisfied_via is required.');
        }

        $documentId = $body['document_id'] ?? null;
        if ($documentId !== null && !is_string($documentId)) {
            return $this->badRequest('document_id must be string or null.');
        }

        $caseEventId = $body['case_event_id'] ?? null;
        if ($caseEventId !== null && !is_string($caseEventId)) {
            return $this->badRequest('case_event_id must be string or null.');
        }

        $notes = $body['notes'] ?? null;
        if ($notes !== null && !is_string($notes)) {
            return $this->badRequest('notes must be string or null.');
        }

        // Load existing deadline (tenant isolation)
        $stmt = $this->db->prepare(
            "SELECT id, tenant_id, case_id, phase_key, obligation_key, display_name, due_at, status, payload
               FROM case_procedural_deadlines
              WHERE id = :id
                AND tenant_id = :tenant"
        );
        $stmt->execute(['id' => $deadlineId, 'tenant' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $this->notFound('Deadline not found.');

        $phaseKey = (string)$row['phase_key'];

        // permission
        $this->requirePhasePermission($tenantId, $phaseKey, $roles, 'edit');

        $existingPayload = $this->jsonToArray($row['payload'] ?? null);

        $now = (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP');

        $nextPayload = $existingPayload;
        $nextPayload['satisfaction'] = [
            'satisfied_via' => $satisfiedVia,
            'document_id'   => $documentId,
            'case_event_id' => $caseEventId,
            'notes'         => $notes,
            'satisfied_at'  => $now,
            'satisfied_by'  => $actorId,
        ];

        $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare(
                "UPDATE case_procedural_deadlines
                    SET status = 'completed',
                        completed_at = :completed_at,
                        completed_by = :completed_by,
                        payload = :payload::jsonb
                  WHERE id = :id
                    AND tenant_id = :tenant"
            );

            $upd->execute([
                'completed_at' => $now,
                'completed_by' => $actorId,
                'payload'      => json_encode($nextPayload, JSON_UNESCAPED_SLASHES),
                'id'           => $deadlineId,
                'tenant'       => $tenantId,
            ]);

            $this->emitCaseEvent($tenantId, (string)$row['case_id'], $actorId, 'deadline_satisfied', [
                'deadline_id'    => $deadlineId,
                'phase_key'      => (string)$row['phase_key'],
                'obligation_key' => (string)$row['obligation_key'],
                'display_name'   => (string)$row['display_name'],
                'due_at'         => (string)$row['due_at'],
                'satisfaction'   => $nextPayload['satisfaction'],
            ]);

            $this->db->commit();

            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => [
                    'status' => 'ok',
                    'id' => $deadlineId,
                ],
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /* ============================================================
     * PATCH /procedural-deadlines/{id}
     * Optional: modify due_at/status/etc (court modification / waiver)
     * ============================================================ */
    public function patch(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId = (string)$ctx->tenantId;
        $actorId  = (string)$ctx->actorId;
        $roles    = $ctx->roles ?? [];

        $deadlineId = $req['params']['id'] ?? null;
        if (!is_string($deadlineId) || $deadlineId === '') {
            return $this->badRequest('Missing deadline id.');
        }

        $body = $req['body'] ?? null;
        if (!is_array($body)) return $this->badRequest('Invalid JSON body.');

        $stmt = $this->db->prepare(
            "SELECT id, tenant_id, case_id, phase_key, obligation_key, display_name, due_at, status, payload
               FROM case_procedural_deadlines
              WHERE id = :id
                AND tenant_id = :tenant"
        );
        $stmt->execute(['id' => $deadlineId, 'tenant' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $this->notFound('Deadline not found.');

        $phaseKey = (string)$row['phase_key'];
        $this->requirePhasePermission($tenantId, $phaseKey, $roles, 'edit');

        $allowedStatuses = ['pending', 'completed', 'waived', 'not_applicable'];
        $status = $body['status'] ?? null;
        if ($status !== null) {
            if (!is_string($status) || !in_array($status, $allowedStatuses, true)) {
                return $this->badRequest('Invalid status.');
            }
        }

        $dueAt = $body['due_at'] ?? null;
        if ($dueAt !== null && !is_string($dueAt)) {
            return $this->badRequest('due_at must be string or null.');
        }

        $blocksPhase = $body['blocks_phase_key'] ?? null;
        if ($blocksPhase !== null && !is_string($blocksPhase)) {
            return $this->badRequest('blocks_phase_key must be string or null.');
        }

        $payloadPatch = $body['payload'] ?? null;
        if ($payloadPatch !== null && !is_array($payloadPatch)) {
            return $this->badRequest('payload must be an object.');
        }

        $existingPayload = $this->jsonToArray($row['payload'] ?? null);
        $nextPayload = $existingPayload;
        if ($payloadPatch !== null) {
            $nextPayload = array_merge($existingPayload, $payloadPatch);
        }

        $nextStatus = $status !== null ? $status : (string)$row['status'];
        $nextDueAt  = $dueAt !== null ? $dueAt : (string)$row['due_at'];

        $completedAt = $row['completed_at'] ?? null;
        $completedBy = $row['completed_by'] ?? null;

        if ($status === 'completed') {
            $completedAt = (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:sP');
            $completedBy = $actorId;
        }

        $sql = "
UPDATE case_procedural_deadlines
   SET status = :status,
       due_at = :due_at,
       blocks_phase_key = :blocks_phase_key,
       payload = :payload::jsonb,
       completed_at = :completed_at,
       completed_by = :completed_by
 WHERE id = :id
   AND tenant_id = :tenant
";
        $upd = $this->db->prepare($sql);
        $upd->execute([
            'status' => $nextStatus,
            'due_at' => $nextDueAt,
            'blocks_phase_key' => $blocksPhase ?? ($row['blocks_phase_key'] ?? null),
            'payload' => json_encode($nextPayload, JSON_UNESCAPED_SLASHES),
            'completed_at' => $completedAt,
            'completed_by' => $completedBy,
            'id' => $deadlineId,
            'tenant' => $tenantId,
        ]);

        $this->emitCaseEvent($tenantId, (string)$row['case_id'], $actorId, 'procedural_deadline_modified', [
            'deadline_id' => $deadlineId,
            'phase_key' => $phaseKey,
            'obligation_key' => (string)$row['obligation_key'],
            'changes' => $body,
        ]);

        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => ['status' => 'ok'],
        ];
    }

    /* ============================================================
     * Internals
     * ============================================================ */

    private function insertDeadline(array $d): string
    {
        $stmt = $this->db->prepare(
            "INSERT INTO case_procedural_deadlines
                (tenant_id, case_id, phase_key, obligation_key, display_name, due_at, required, blocks_phase_key, status, payload)
             VALUES
                (:tenant_id, :case_id, :phase_key, :obligation_key, :display_name, :due_at, :required, :blocks_phase_key, :status, :payload::jsonb)
             RETURNING id"
        );

        $stmt->execute([
            'tenant_id' => $d['tenant_id'],
            'case_id' => $d['case_id'],
            'phase_key' => $d['phase_key'],
            'obligation_key' => $d['obligation_key'],
            'display_name' => $d['display_name'],
            'due_at' => $d['due_at'],
            'required' => $d['required'] ? true : false,
            'blocks_phase_key' => $d['blocks_phase_key'],
            'status' => $d['status'],
            'payload' => json_encode($d['payload'] ?? [], JSON_UNESCAPED_SLASHES),
        ]);

        $id = $stmt->fetchColumn();
        if (!is_string($id) || $id === '') {
            throw new RuntimeException('Failed to insert deadline.');
        }
        return $id;
    }

    private function emitCaseEvent(
        string $tenantId,
        string $caseId,
        string $actorId,
        string $eventType,
        array $payload
    ): void {
        $eventId = $this->events->emitEvent(
            $eventType,
            $payload,
            $tenantId,
            $caseId,
            $actorId
        );

        if (!is_string($eventId) || $eventId === '') {
            throw new RuntimeException('CASE_EVENT_EMIT_FAILED');
        }
    }

    private function requirePhasePermission(string $tenantId, string $phaseKey, array $roles, string $op): void
    {
        // system_admin always allowed
        if (in_array('system_admin', $roles, true)) return;

        $stmt = $this->db->prepare(
            "SELECT can_view_roles, can_create_roles, can_edit_roles
               FROM tenant_phase_permissions
              WHERE tenant_id = :tenant
                AND phase = :phase"
        );
        $stmt->execute(['tenant' => $tenantId, 'phase' => $phaseKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // If no row exists yet, allow typical admin/legal roles by default (policy can be tightened later)
        if (!$row) {
            $fallback = ['tenant_admin', 'legal_admin', 'attorney', 'paralegal'];
            $ok = count(array_intersect($roles, $fallback)) > 0;
            if (!$ok) {
                throw new RuntimeException('Forbidden: insufficient role for phase operation.');
            }
            return;
        }

        $allow = [];
        if ($op === 'create') $allow = $this->pgArrayToPhp($row['can_create_roles'] ?? null);
        if ($op === 'edit')   $allow = $this->pgArrayToPhp($row['can_edit_roles'] ?? null);
        if ($op === 'view')   $allow = $this->pgArrayToPhp($row['can_view_roles'] ?? null);

        if (count(array_intersect($roles, $allow)) === 0) {
            throw new RuntimeException('Forbidden: insufficient role for phase operation.');
        }
    }

    private function pgArrayToPhp($pg): array
    {
        // PDO may return text[] as "{a,b}" or already as PHP array depending on driver settings.
        if (is_array($pg)) return $pg;

        if (!is_string($pg) || $pg === '') return [];
        $trim = trim($pg, "{}");
        if ($trim === '') return [];
        return array_map('trim', explode(',', $trim));
    }

    private function jsonToArray($val): array
    {
        if (is_array($val)) return $val;
        if (!is_string($val) || $val === '') return [];

        $decoded = json_decode($val, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function enqueueUspsCertifiedMailTrackingStub(
        string $tenantId,
        string $caseId,
        ?string $actionRequestId,
        ?string $deliveryEventId,
        string $trackingNumber
    ): void {
        // Intentionally a no-op for now.
        // You can later persist into a queue table, or emit an internal event,
        // or enqueue to your worker system.
        //
        // Example future shape (NOT implemented):
        // INSERT INTO usps_tracking_jobs (...)
        // or emitCaseEvent('usps.tracking.requested', [...])
    }

    /* ------------------------------------------------------------
    * Helper: mark deadline complete + emit event
    * ------------------------------------------------------------
    */
    private function completeDeadline(
        string $tenantId,
        string $deadlineId,
        string $actorId,
        array $payload
    ): void {
        $nowIso = gmdate('c');

        $upd = $this->db->prepare("
            UPDATE case_procedural_deadlines
            SET
                status = 'completed',
                completed_at = :at,
                completed_by = :actor,
                payload = :payload::jsonb
            WHERE id = :id
            AND tenant_id = :tenant
        ");

        $upd->execute([
            ':at'     => $nowIso,
            ':actor'  => $actorId,
            ':payload'=> json_encode($payload, JSON_UNESCAPED_SLASHES),
            ':id'     => $deadlineId,
            ':tenant' => $tenantId,
        ]);

        $this->emitCaseEvent(
            tenantId: $tenantId,
            caseId:   $payload['case_id'] ?? null,
            actorId:  $actorId,
            eventType:'deadline_satisfied',
            payload: [
                'deadline_id' => $deadlineId,
                'satisfied_at'=> $nowIso,
                'method'      => $payload['satisfaction']['method'] ?? null,
            ]
        );
    }

    private function processScheduledDeadlineCompletions(
        string $tenantId,
        string $caseId,
        string $actorId
    ): void {
        $st = $this->db->prepare("
            SELECT id, payload
            FROM case_procedural_deadlines
            WHERE tenant_id = :tenant
            AND case_id   = :case
            AND status IN ('pending','blocked')
            AND (payload->'satisfaction_pending'->>'scheduled_at') IS NOT NULL
        ");
        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
        ]);

        $now = new \DateTimeImmutable('now');

        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $deadlineId = (string)$row['id'];
            $pl = $this->jsonToArray($row['payload'] ?? null);

            $pending = $pl['satisfaction_pending'] ?? null;
            if (!is_array($pending)) continue;

            $scheduledAt = $pending['scheduled_at'] ?? null;
            if (!is_string($scheduledAt) || $scheduledAt === '') continue;

            $due = new \DateTimeImmutable($scheduledAt);
            if ($now < $due) continue;

            // ✅ Matured: mark satisfied now (or at scheduledAt; choose one and be consistent)
            $satisfaction = [
                'satisfied_via'   => 'certified_mail_deemed_received',
                'satisfied_at'    => $scheduledAt,
                'satisfied_by'    => $actorId,
                'details'         => $pending,
            ];

            $pl['satisfaction'] = $satisfaction;
            unset($pl['satisfaction_pending']);

            $this->completeDeadline(
                tenantId: $tenantId,
                caseId: $caseId,
                actorId: $actorId,
                deadlineId: $deadlineId,
                completedAt: $scheduledAt,
                nextPayload: $pl,
                sourceEventType: (string)($pending['source_event_type'] ?? 'certified_mail_deemed_received')
            );
        }
}


    /* ============================================================
     * Standard responses
     * ============================================================ */

    private function unauthorized(): array
    {
        return [
            'status' => 401,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => ['error' => 'UNAUTHORIZED'],
        ];
    }

    private function notFound(string $msg): array
    {
        return [
            'status' => 404,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => ['error' => 'NOT_FOUND', 'message' => $msg],
        ];
    }

    private function badRequest(string $msg): array
    {
        return [
            'status' => 400,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => ['error' => 'BAD_REQUEST', 'message' => $msg],
        ];
    }
}
