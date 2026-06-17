<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\CaseActionTargetService;
use PDO;
use RuntimeException;

final class CaseActionRequestTargetsController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * GET /cases/{id}/action-targets
     * Optional query:
     *  - action_request_id=<uuid>
     * ============================================================ */
    public function listForCase(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId = (string)$ctx->tenantId;
        $caseId = $req['params']['id'] ?? $req['params']['case_id'] ?? null;

        if (!is_string($caseId) || !$this->isUuid($caseId)) {
            return $this->json(400, ['error' => 'INVALID_CASE_ID']);
        }

        $actionRequestId = $req['query']['action_request_id'] ?? null;
        if ($actionRequestId !== null && (!is_string($actionRequestId) || !$this->isUuid($actionRequestId))) {
            return $this->json(400, ['error' => 'INVALID_ACTION_REQUEST_ID']);
        }

        if ($actionRequestId !== null) {
            $st = $this->db->prepare("
                SELECT *
                FROM case_occupant_action_targets
                WHERE tenant_id = :tenant
                  AND case_id = :case_id
                  AND action_request_id = :arid
                ORDER BY created_at ASC
            ");
            $st->execute([
                ':tenant' => $tenantId,
                ':case_id' => $caseId,
                ':arid' => $actionRequestId,
            ]);
        } else {
            $st = $this->db->prepare("
                SELECT *
                FROM case_occupant_action_targets
                WHERE tenant_id = :tenant
                  AND case_id = :case_id
                ORDER BY created_at ASC
            ");
            $st->execute([
                ':tenant' => $tenantId,
                ':case_id' => $caseId,
            ]);
        }

        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $this->json(200, [
            'case_id' => $caseId,
            'items' => $rows,
        ]);
    }

    /* ============================================================
     * POST /cases/{id}/action-targets
     *
     * Body:
     * {
     *   "action_request_id": "<uuid>",
     *   "phase_key": "az_demand_service",
     *   "action_type": "az_demand_for_possession"
     * }
     * ============================================================ */
    public function create(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId = (string)$ctx->tenantId;
        $caseId = $req['params']['id'] ?? $req['params']['case_id'] ?? null;

        if (!is_string($caseId) || !$this->isUuid($caseId)) {
            return $this->json(400, ['error' => 'INVALID_CASE_ID']);
        }

        $body = $req['body'] ?? null;
        if (!is_array($body)) {
            return $this->json(400, ['error' => 'INVALID_REQUEST']);
        }

        $actionRequestId = $body['action_request_id'] ?? null;
        $phaseKey = $body['phase_key'] ?? null;
        $actionType = $body['action_type'] ?? null;

        if (!is_string($actionRequestId) || !$this->isUuid($actionRequestId)) {
            return $this->json(400, ['error' => 'ACTION_REQUEST_ID_REQUIRED']);
        }
        if (!is_string($phaseKey) || $phaseKey === '') {
            return $this->json(400, ['error' => 'PHASE_KEY_REQUIRED']);
        }
        if (!is_string($actionType) || $actionType === '') {
            return $this->json(400, ['error' => 'ACTION_TYPE_REQUIRED']);
        }

        // Load LAR event
        $st = $this->db->prepare("
            SELECT payload
            FROM case_events
            WHERE tenant_id = :tenant
              AND case_id = :case_id
              AND id = :id
              AND event_type = 'legal_action_request'
            LIMIT 1
        ");
        $st->execute([
            ':tenant' => $tenantId,
            ':case_id' => $caseId,
            ':id' => $actionRequestId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $this->json(404, ['error' => 'ACTION_REQUEST_NOT_FOUND']);
        }

        $larPayload = is_array($row['payload'] ?? null) ? $row['payload'] : [];

        try {
            $svc = new CaseActionTargetService($this->db);
            $inserted = $svc->replaceTargetsFromLarPayload(
                tenantId: $tenantId,
                caseId: $caseId,
                requestId: $actionRequestId,
                phaseKey: $phaseKey,
                actionType: $actionType,
                larPayload: $larPayload
            );
        } catch (RuntimeException $e) {
            return $this->json(400, ['error' => $e->getMessage()]);
        }

        return $this->json(200, [
            'case_id' => $caseId,
            'action_request_id' => $actionRequestId,
            'phase_key' => $phaseKey,
            'action_type' => $actionType,
            'targets' => $inserted,
        ]);
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function unauthorized(): array
    {
        return $this->json(401, ['error' => 'AUTH_REQUIRED']);
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
}
