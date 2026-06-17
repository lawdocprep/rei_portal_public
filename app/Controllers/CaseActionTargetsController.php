<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;
use Throwable;

/**
 * Phase 14.4 Deliverable #3
 *
 * Action Request Targets (one per occupant/person/party-role).
 *
 * Table expected: case_occupant_action_targets
 * Columns (per your introspection):
 * - id (uuid)
 * - tenant_id (uuid)
 * - case_id (uuid)
 * - occupant_id (uuid)              // references party_role concept in your system
 * - action_request_id (uuid)        // LAR id (case_events.id)
 * - phase_key (text)
 * - action_type (text)
 * - required (boolean)
 * - status (text)                   // pending|satisfied|skipped|blocked (your choice; we validate lightly)
 * - created_at (timestamptz)
 *
 * Endpoints:
 * - GET  /cases/{id}/action-targets?action_request_id=...   (or filter by phase_key/action_type)
 * - PUT  /cases/{id}/action-targets                         (replace targets for an action_request_id)
 */
final class CaseActionTargetsController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * GET /cases/{id}/action-targets
     * ============================================================ */
    public function list(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId = (string)$ctx->tenantId;

        $caseId = $req['params']['id'] ?? null;
        if (!is_string($caseId) || !$this->isUuid($caseId)) {
            return $this->json(400, ['error' => 'INVALID_CASE_ID']);
        }

        $q = $req['query'] ?? [];
        if (!is_array($q)) $q = [];

        $actionRequestId = isset($q['action_request_id']) && is_string($q['action_request_id']) ? $q['action_request_id'] : null;
        $phaseKey        = isset($q['phase_key']) && is_string($q['phase_key']) ? $q['phase_key'] : null;
        $actionType      = isset($q['action_type']) && is_string($q['action_type']) ? $q['action_type'] : null;

        if ($actionRequestId !== null && !$this->isUuid($actionRequestId)) {
            return $this->json(400, ['error' => 'INVALID_ACTION_REQUEST_ID']);
        }

        $sql = "
            SELECT
                id,
                tenant_id,
                case_id,
                occupant_id,
                action_request_id,
                phase_key,
                action_type,
                required,
                status,
                created_at
            FROM case_occupant_action_targets
            WHERE tenant_id = :tenant
              AND case_id = :case_id
        ";

        $params = [
            ':tenant' => $tenantId,
            ':case_id' => $caseId,
        ];

        if ($actionRequestId !== null) {
            $sql .= " AND action_request_id = :arid";
            $params[':arid'] = $actionRequestId;
        }
        if ($phaseKey !== null && $phaseKey !== '') {
            $sql .= " AND phase_key = :phase";
            $params[':phase'] = $phaseKey;
        }
        if ($actionType !== null && $actionType !== '') {
            $sql .= " AND action_type = :atype";
            $params[':atype'] = $actionType;
        }

        $sql .= " ORDER BY created_at ASC, occupant_id ASC";

        $st = $this->db->prepare($sql);
        $st->execute($params);

        return $this->json(200, [
            'case_id' => $caseId,
            'items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ]);
    }

    /* ============================================================
     * PUT /cases/{id}/action-targets
     * Body:
     * {
     *   "action_request_id": "<uuid>",
     *   "phase_key": "az_demand_for_possession",
     *   "action_type": "az_demand_for_possession",
     *   "required": true,                 // optional default for targets
     *   "targets": [
     *     { "occupant_id": "<uuid>", "required": true, "status": "pending" },
     *     ...
     *   ]
     * }
     *
     * Replaces targets for (tenant, case, action_request_id, phase_key, action_type).
     * ============================================================ */
    public function replace(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId = (string)$ctx->tenantId;

        $caseId = $req['params']['id'] ?? null;
        if (!is_string($caseId) || !$this->isUuid($caseId)) {
            return $this->json(400, ['error' => 'INVALID_CASE_ID']);
        }

        $body = $req['body'] ?? null;
        if (!is_array($body)) {
            return $this->json(400, ['error' => 'INVALID_REQUEST']);
        }

        $actionRequestId = $body['action_request_id'] ?? null;
        $phaseKey        = $body['phase_key'] ?? null;
        $actionType      = $body['action_type'] ?? null;

        if (!is_string($actionRequestId) || !$this->isUuid($actionRequestId)) {
            return $this->json(400, ['error' => 'ACTION_REQUEST_ID_REQUIRED']);
        }
        if (!is_string($phaseKey) || $phaseKey === '') {
            return $this->json(400, ['error' => 'PHASE_KEY_REQUIRED']);
        }
        if (!is_string($actionType) || $actionType === '') {
            return $this->json(400, ['error' => 'ACTION_TYPE_REQUIRED']);
        }

        $defaultRequired = isset($body['required']) ? (bool)$body['required'] : true;

        $targets = $body['targets'] ?? null;
        if (!is_array($targets)) {
            return $this->json(400, ['error' => 'TARGETS_REQUIRED']);
        }

        $rows = [];
        foreach ($targets as $t) {
            if (!is_array($t)) continue;
            $occ = $t['occupant_id'] ?? null;
            if (!is_string($occ) || !$this->isUuid($occ)) continue;

            $required = array_key_exists('required', $t) ? (bool)$t['required'] : $defaultRequired;
            $status   = isset($t['status']) && is_string($t['status']) ? $t['status'] : 'pending';

            // Light validation: keep status flexible, but avoid empty string
            if ($status === '') $status = 'pending';

            $rows[] = [
                'occupant_id' => $occ,
                'required' => $required,
                'status' => $status,
            ];
        }

        if (count($rows) === 0) {
            return $this->json(400, ['error' => 'NO_VALID_TARGETS']);
        }

        try {
            $this->db->beginTransaction();

            // delete existing set
            $del = $this->db->prepare("
                DELETE FROM case_occupant_action_targets
                WHERE tenant_id = :tenant
                  AND case_id = :case_id
                  AND action_request_id = :arid
                  AND phase_key = :phase
                  AND action_type = :atype
            ");
            $del->execute([
                ':tenant' => $tenantId,
                ':case_id' => $caseId,
                ':arid' => $actionRequestId,
                ':phase' => $phaseKey,
                ':atype' => $actionType,
            ]);

            $ins = $this->db->prepare("
                INSERT INTO case_occupant_action_targets (
                    id, tenant_id, case_id,
                    occupant_id, action_request_id,
                    phase_key, action_type,
                    required, status, created_at
                ) VALUES (
                    :id, :tenant, :case_id,
                    :occupant_id, :arid,
                    :phase, :atype,
                    :required, :status, :created_at
                )
            ");

            $now = gmdate('c');
            foreach ($rows as $r) {
                $ins->execute([
                    ':id' => $this->uuid(),
                    ':tenant' => $tenantId,
                    ':case_id' => $caseId,
                    ':occupant_id' => $r['occupant_id'],
                    ':arid' => $actionRequestId,
                    ':phase' => $phaseKey,
                    ':atype' => $actionType,
                    ':required' => $r['required'] ? 't' : 'f',
                    ':status' => $r['status'],
                    ':created_at' => $now,
                ]);
            }

            $this->db->commit();

            return $this->json(200, [
                'case_id' => $caseId,
                'action_request_id' => $actionRequestId,
                'phase_key' => $phaseKey,
                'action_type' => $actionType,
                'target_count' => count($rows),
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[ACTION TARGETS REPLACE ERROR] ' . $e->getMessage());
            return $this->json(500, ['error' => 'INTERNAL_ERROR']);
        }
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function unauthorized(): array { return $this->json(401, ['error' => 'AUTH_REQUIRED']); }

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
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $v);
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
}
