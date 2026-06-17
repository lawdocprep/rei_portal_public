<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\CaseActionExecutorService;
use App\Services\CaseActionTargetService;
use App\Services\PhaseActionResolver;
use App\Services\ProceduralStateService;
use App\Services\Events\CaseEventService;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class CaseActionRequestsController
{
    private CaseEventService $events;

    public function __construct(private PDO $db)
    {
        $this->events = new \App\Services\Events\CaseEventService($this->db);
    }

    /* ============================================================
     * POST /cases/{id}/action-requests
     * ============================================================ */
    public function create(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $caseId = $req['params']['id'] ?? null;
        $body   = $req['body'] ?? null;

        if (!is_string($caseId) || $caseId === '') {
            return $this->badRequest('INVALID_CASE_ID');
        }

        if (!is_array($body)) {
            return $this->badRequest('INVALID_REQUEST');
        }

        $requestedAction = $body['requested_action'] ?? null;

        if (!is_string($requestedAction) || $requestedAction === '') {
            return $this->badRequest('REQUESTED_ACTION_REQUIRED');
        }

        $title    = is_string($body['title'] ?? null) ? (string)$body['title'] : null;
        $notes    = is_string($body['notes'] ?? null) ? (string)$body['notes'] : null;
        $dueDate  = is_string($body['due_date'] ?? null) ? (string)$body['due_date'] : null;
        $metadata = is_array($body['metadata'] ?? null) ? $body['metadata'] : null;

        if ($dueDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            return $this->badRequest('INVALID_DUE_DATE');
        }

        $tenantId = (string)$ctx->tenantId;
        $actorId  = (string)$ctx->actorId;

        /*
        * ------------------------------------------------------------
        * Phase gating
        * ------------------------------------------------------------
        */
        $gate = $this->gateActionByPhase($tenantId, $caseId, $requestedAction, $ctx);

        if ($gate !== null) {
            return $gate;
        }

        /*
        * ------------------------------------------------------------
        * Target parsing
        * ------------------------------------------------------------
        */
        $targetsRaw = $body['targets'] ?? null;
        $targets = null;

        if (is_array($targetsRaw)) {

            $targets = [];

            foreach ($targetsRaw as $t) {

                if (is_string($t) && $this->isUuid($t)) {
                    $targets[] = [
                        'occupant_id' => $t,
                        'required'    => true
                    ];
                    continue;
                }

                if (is_array($t)) {

                    $occ = $t['occupant_id'] ?? null;

                    if (!is_string($occ) || !$this->isUuid($occ)) {
                        continue;
                    }

                    $req = array_key_exists('required', $t)
                        ? (bool)$t['required']
                        : true;

                    $targets[] = [
                        'occupant_id' => $occ,
                        'required'    => $req
                    ];
                }
            }

            if (count($targets) === 0) {
                $targets = null;
            }
        }

        /*
        * ------------------------------------------------------------
        * Canonical LAR payload
        * ------------------------------------------------------------
        */
        $payload = [
            'status'                => 'pending',
            'requested_action'      => $requestedAction,
            'title'                 => $title,
            'notes'                 => $notes,
            'due_date'              => $dueDate,
            'metadata'              => $metadata,
            'requested_by_actor_id' => $actorId,
            'targets'               => $targets
        ];

        /*
        * ------------------------------------------------------------
        * Emit canonical event
        * ------------------------------------------------------------
        */
        $eventId = $this->events->emitEvent(
            'legal_action_request',
            $payload,
            $tenantId,
            $caseId,
            $actorId
        );

        if (!is_string($eventId) || $eventId === '') {
            throw new RuntimeException('LEGAL_ACTION_REQUEST_EVENT_WRITE_FAILED');
        }

        $nowIso = gmdate('c');

        /*
        * ------------------------------------------------------------
        * Optional deadline creation
        * ------------------------------------------------------------
        */
        if ($requestedAction === 'az_demand_for_possession') {

            $stmt = $this->db->prepare("
                INSERT INTO case_procedural_deadlines (
                    tenant_id,
                    case_id,
                    phase_key,
                    obligation_key,
                    display_name,
                    due_at,
                    required,
                    blocks_phase_key,
                    status,
                    payload
                )
                VALUES (
                    :tenant,
                    :case,
                    'az_prefiling',
                    'az_demand_service_proof',
                    'Provide proof of demand for possession service',
                    :due_at,
                    true,
                    'az_filing',
                    'pending',
                    :payload::jsonb
                )
            ");

            $stmt->execute([
                ':tenant' => $tenantId,
                ':case'   => $caseId,
                ':due_at' => (new DateTimeImmutable('+3 days'))->format('c'),
                ':payload'=> json_encode([
                    'source_action'     => 'az_demand_for_possession',
                    'action_request_id' => $eventId
                ], JSON_UNESCAPED_SLASHES)
            ]);
        }

        /*
        * ------------------------------------------------------------
        * Materialize occupant targets
        * ------------------------------------------------------------
        */
        $targetSvc = new CaseActionTargetService($this->db);

        $materialized = $targetSvc->replaceTargetsFromLarPayload(
            tenantId:   $tenantId,
            caseId:     $caseId,
            requestId:  $eventId,
            phaseKey:   $requestedAction,
            actionType: $requestedAction,
            larPayload: $payload
        );

        $responsePayload = $payload;
        $responsePayload['materialized_targets'] = $materialized;

        return [
            'status' => 201,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => [
                'request_id' => $eventId,
                'case_id' => $caseId,
                'event_type' => 'legal_action_request',
                'payload' => $responsePayload,
                'created_at' => $nowIso
            ]
        ];
    }

    /* ============================================================
     * PATCH /action-requests/{request_id}
     * ============================================================ */
    public function patch(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $requestId = $req['params']['request_id'] ?? null;
        $body = $req['body'] ?? null;

        if (!is_string($requestId) || $requestId === '') {
            return $this->badRequest('INVALID_REQUEST_ID');
        }
        if (!is_array($body)) {
            return $this->badRequest('INVALID_REQUEST');
        }

        $newStatus = $body['status'] ?? null;
        if (!is_string($newStatus) || !in_array($newStatus, ['approved', 'rejected', 'completed'], true)) {
            return $this->badRequest('INVALID_STATUS');
        }

        $reviewNotes = is_string($body['review_notes'] ?? null) ? (string)$body['review_notes'] : null;

        $tenantId = (string)$ctx->tenantId;
        $actorId  = (string)$ctx->actorId;
        $roles    = $ctx->roles ?? [];

        $isSystemAdmin = in_array('system_admin', $roles, true);

        $st = $this->db->prepare("
            SELECT id, case_id, payload
            FROM case_events
            WHERE tenant_id = :tenant_id
              AND id = :id
              AND event_type = 'legal_action_request'
            LIMIT 1
        ");
        $st->execute([':tenant_id' => $tenantId, ':id' => $requestId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) return $this->notFound();

        $payload = json_decode((string)$row['payload'], true) ?: [];
        $curStatus = $payload['status'] ?? 'pending';
        $requestedBy = $payload['requested_by_actor_id'] ?? null;

        $requestedAction = is_string($payload['requested_action'] ?? null) ? (string)$payload['requested_action'] : '';
        if ($requestedAction === '') {
            return $this->badRequest('REQUESTED_ACTION_REQUIRED');
        }

        /* ============================================================
         * SYSTEM ADMIN: ABSOLUTE AUTHORITY (status changes)
         * ============================================================ */
        if ($isSystemAdmin) {
            $payload['status'] = $newStatus;
            if ($reviewNotes !== null) $payload['review_notes'] = $reviewNotes;
            $payload['reviewed_by'] = $actorId;
            $payload['reviewed_at'] = gmdate('c');

            $this->persistPayload($tenantId, $requestId, $payload);
            return $this->ok($requestId, (string)$row['case_id'], $payload);
        }

        // ------------------------------------------------------------
        // Phase 14.3 hard gating: approvals/completions must be eligible
        // ------------------------------------------------------------
        if ($newStatus === 'approved' || $newStatus === 'completed') {
            $gate = $this->gateActionByPhase($tenantId, (string)$row['case_id'], $requestedAction, $ctx);
            if ($gate !== null) return $gate;
        }

        /* ============================================================
         * NORMAL ROLE / STATE MACHINE
         * ============================================================ */

        $isReviewer = (bool)array_intersect($roles, ['tenant_admin','legal_admin','attorney','paralegal']);
        $isProSe = in_array('pro_se', $roles, true);

        if ($newStatus === 'approved' || $newStatus === 'rejected') {
            if (!$isReviewer || $curStatus !== 'pending') {
                return $this->forbidden('INVALID_TRANSITION_OR_ROLE');
            }
        } elseif ($newStatus === 'completed') {
            if ($curStatus === 'rejected') return $this->forbidden('INVALID_TRANSITION_OR_ROLE');
            if (!$isReviewer && !($isProSe && $requestedBy === $actorId)) {
                return $this->forbidden('INVALID_TRANSITION_OR_ROLE');
            }
        }

        $payload['status'] = $newStatus;
        if ($reviewNotes !== null) $payload['review_notes'] = $reviewNotes;
        $payload['reviewed_by'] = $actorId;
        $payload['reviewed_at'] = gmdate('c');

        $this->persistPayload($tenantId, $requestId, $payload);

        return $this->ok($requestId, (string)$row['case_id'], $payload);
    }

    /* ============================================================
     * POST /action-requests/{request_id}/execute
     * ============================================================ */
    public function execute(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $requestId = $req['params']['request_id'] ?? null;
        if (!is_string($requestId) || $requestId === '') {
            return $this->badRequest('INVALID_REQUEST_ID');
        }

        $tenantId = (string)$ctx->tenantId;
        $actorId  = (string)$ctx->actorId;
        $roles    = $ctx->roles ?? [];
        $isSystemAdmin = in_array('system_admin', $roles, true);

        // Load LAR
        $st = $this->db->prepare("
            SELECT id, case_id, payload
            FROM case_events
            WHERE tenant_id = :tenant_id
              AND id = :id
              AND event_type = 'legal_action_request'
            LIMIT 1
        ");
        $st->execute([':tenant_id' => $tenantId, ':id' => $requestId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $this->notFound();

        $payload = json_decode((string)$row['payload'], true) ?: [];
        $status = $payload['status'] ?? 'pending';

        $requestedAction = is_string($payload['requested_action'] ?? null) ? (string)$payload['requested_action'] : '';
        if ($requestedAction === '') {
            return $this->badRequest('REQUESTED_ACTION_REQUIRED');
        }

        // ------------------------------------------------------------
        // Phase 14.3 hard gating: execution must be eligible
        // (system_admin can auto-approve, but cannot bypass eligibility)
        // ------------------------------------------------------------
        $gate = $this->gateActionByPhase($tenantId, (string)$row['case_id'], $requestedAction, $ctx);
        if ($gate !== null) return $gate;

        /* ============================================================
         * SYSTEM ADMIN: AUTO-APPROVE THEN EXECUTE
         * ============================================================ */
        if ($isSystemAdmin && $status !== 'approved') {
            $payload['status'] = 'approved';
            $payload['auto_approved_by'] = $actorId;
            $payload['auto_approved_at'] = gmdate('c');
            $this->persistPayload($tenantId, $requestId, $payload);
        }

        $svc = new CaseActionExecutorService($this->db);

        try {
            $bundle = $svc->execute($requestId, $ctx);
            return $this->json(200, $bundle);
        } catch (RuntimeException $e) {
            $code = $e->getMessage();

            $map400 = ['INVALID_REQUEST_ID','INVALID_STATUS','TEMPLATE_NOT_FOUND','PHASE_BLOCKED'];
            $map403 = ['FORBIDDEN','INSUFFICIENT_ROLE'];
            $map404 = ['NOT_FOUND'];
            $map409 = ['ACTION_NOT_ELIGIBLE_FOR_PHASE'];

            if (in_array($code, $map404, true)) return $this->json(404, ['error'=>$code]);
            if (in_array($code, $map403, true)) return $this->json(403, ['error'=>$code]);
            if (in_array($code, $map409, true)) return $this->json(409, ['error'=>$code]);
            if (in_array($code, $map400, true)) return $this->json(400, ['error'=>$code]);

            return $this->json(500, ['error'=>'EXECUTION_FAILED','message'=>$e->getMessage()]);
        }
    }

    /* ============================================================
     * Phase 14.3: gating helper
     * ============================================================ */

    private function gateActionByPhase(string $tenantId, string $caseId, string $requestedAction, AuthContext $ctx): ?array
    {
        $procedural = new ProceduralStateService($this->db);
        $resolver   = new PhaseActionResolver($this->db, $procedural);

        $snap = $resolver->resolve($tenantId, $caseId, $ctx->roles ?? []);

        if (isset($snap['error'])) {
            if ($snap['error'] === 'CASE_NOT_FOUND') {
                return $this->json(404, ['error' => 'CASE_NOT_FOUND']);
            }
            return $this->json(400, ['error' => (string)$snap['error']]);
        }

        $allowed = false;
        foreach (($snap['allowed_actions'] ?? []) as $a) {
            if (is_array($a) && ($a['action_type'] ?? null) === $requestedAction) {
                $allowed = true;
                break;
            }
        }

        if ($allowed) return null;

        $block = null;
        foreach (($snap['blocked_actions'] ?? []) as $b) {
            if (is_array($b) && ($b['action_type'] ?? null) === $requestedAction) {
                $block = $b;
                break;
            }
        }

        return $this->json(409, [
            'error' => 'ACTION_NOT_ELIGIBLE_FOR_PHASE',
            'details' => [
                'requested_action' => $requestedAction,
                'current_phase_key' => $snap['current_phase_key'] ?? null,
                'block' => $block,
                'mandatory_next_actions' => $snap['mandatory_next_actions'] ?? [],
                'auto_generate_actions' => $snap['auto_generate_actions'] ?? [],
            ],
        ]);
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function persistPayload(string $tenantId, string $requestId, array $payload): void
    {
        $payloadJson = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $upd = $this->db->prepare("
            UPDATE case_events
            SET
                payload = CAST(:payload AS jsonb),

                event_hash = encode(
                    digest(
                        COALESCE(previous_event_hash, '') ||
                        case_id::text ||
                        case_sequence::text ||
                        event_type ||
                        jsonb_strip_nulls(CAST(:payload AS jsonb))::text,
                        'sha256'
                    ),
                    'hex'
                )

            WHERE tenant_id = :tenant_id
            AND id = :id
        ");

        $upd->execute([
            ':payload'   => $payloadJson,
            ':tenant_id' => $tenantId,
            ':id'        => $requestId,
        ]);
    }

    private function ok(string $requestId, string $caseId, array $payload): array
    {
        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => [
                'request_id' => $requestId,
                'case_id' => $caseId,
                'event_type' => 'legal_action_request',
                'payload' => $payload,
            ],
        ];
    }

    private function unauthorized(): array
    {
        return $this->json(401, ['error'=>'AUTH_REQUIRED']);
    }

    private function forbidden(string $code): array
    {
        return $this->json(403, ['error'=>$code]);
    }

    private function badRequest(string $code): array
    {
        return $this->json(400, ['error'=>$code]);
    }

    private function notFound(): array
    {
        return $this->json(404, ['error'=>'NOT_FOUND']);
    }

    private function json(int $status, array $body): array
    {
        return ['status'=>$status,'headers'=>['Content-Type'=>'application/json'],'body'=>$body];
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
    private function isUuid(string $v): bool
    {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $v);
    }
}
