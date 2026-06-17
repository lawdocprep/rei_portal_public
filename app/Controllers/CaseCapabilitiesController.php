<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;

final class CaseCapabilitiesController
{
    public function __construct(private PDO $db) {}

    /**
     * Locked capability vocabulary
     *
     * Phase 4: case capabilities
     * Phase 10–11: document capabilities (case-scoped)
     */
    private const ALLOWED_CAPABILITIES = [
        // -----------------------------
        // Case-level capabilities
        // -----------------------------
        'case:create',
        'case:write',
        'case:occupants:write',
        'case:ingress:submit',

        // -----------------------------
        // Document-level capabilities
        // (enforced via case scope)
        // -----------------------------
        'document:read',
        'document:export',
    ];

    /* ============================================================
     * POST /cases/{id}/capabilities
     * ============================================================ */
    public function grant(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId = (string)$ctx->tenantId;
        $caseId   = $req['params']['id'] ?? null;
        $body     = $req['body'] ?? null;

        if (!is_string($caseId) || !is_array($body)) {
            return $this->badRequest();
        }

        $actorId    = $body['actor_id'] ?? null;
        $capability = $body['capability'] ?? null;
        $startsAt   = $body['starts_at'] ?? 'now';
        $endsAt     = $body['ends_at'] ?? null;

        if (!is_string($actorId) || !is_string($capability)) {
            return $this->validationError('actor_id and capability are required');
        }

        if (!in_array($capability, self::ALLOWED_CAPABILITIES, true)) {
            return $this->validationError('invalid capability');
        }

        // --------------------------------------------------
        // Idempotency: existing active grant?
        // --------------------------------------------------
        $exists = <<<SQL
SELECT 1
FROM case_capability_grants
WHERE tenant_id = :tenant
  AND case_id = :case
  AND grantee_actor_id = :actor
  AND capability = :cap
  AND starts_at <= now()
  AND (ends_at IS NULL OR ends_at > now())
  AND revoked_at IS NULL
LIMIT 1
SQL;

        $st = $this->db->prepare($exists);
        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
            ':actor'  => $actorId,
            ':cap'    => $capability,
        ]);

        if ($st->fetchColumn()) {
            return $this->ok(['status' => 'already_granted']);
        }

        // --------------------------------------------------
        // Insert grant
        // --------------------------------------------------
        $sql = <<<SQL
INSERT INTO case_capability_grants
(tenant_id, case_id, grantee_actor_id, capability, starts_at, ends_at, granted_by)
VALUES
(:tenant, :case, :actor, :cap, :starts, :ends, :by)
SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
            ':actor'  => $actorId,
            ':cap'    => $capability,
            ':starts' => $startsAt,
            ':ends'   => $endsAt,
            ':by'     => $ctx->actorId,
        ]);

        return $this->ok(['status' => 'granted']);
    }

    /* ============================================================
     * DELETE /cases/{id}/capabilities/{capability}
     * Body: { actor_id }
     * ============================================================ */
    public function revoke(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId   = (string)$ctx->tenantId;
        $caseId     = $req['params']['id'] ?? null;
        $capability = $req['params']['capability'] ?? null;
        $body       = $req['body'] ?? null;

        if (!is_string($caseId) || !is_string($capability) || !is_array($body)) {
            return $this->badRequest();
        }

        $actorId = $body['actor_id'] ?? null;
        if (!is_string($actorId)) {
            return $this->validationError('actor_id is required');
        }

        if (!in_array($capability, self::ALLOWED_CAPABILITIES, true)) {
            return $this->validationError('invalid capability');
        }

        $sql = <<<SQL
UPDATE case_capability_grants
SET revoked_at = now(),
    reason = 'revoked'
WHERE tenant_id = :tenant
  AND case_id = :case
  AND grantee_actor_id = :actor
  AND capability = :cap
  AND revoked_at IS NULL
SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
            ':actor'  => $actorId,
            ':cap'    => $capability,
        ]);

        if ($stmt->rowCount() === 0) {
            return $this->ok(['status' => 'no_active_grant']);
        }

        return $this->ok(['status' => 'revoked']);
    }

    /* ============================================================
     * GET /cases/{id}/capabilities
     * ============================================================ */
    public function list(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId = (string)$ctx->tenantId;
        $caseId   = $req['params']['id'] ?? null;

        if (!is_string($caseId)) {
            return $this->badRequest();
        }

        $sql = <<<SQL
SELECT grantee_actor_id, capability, starts_at, ends_at
FROM case_capability_grants
WHERE tenant_id = :tenant
  AND case_id = :case
  AND revoked_at IS NULL
ORDER BY granted_at DESC
SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
        ]);

        return $this->ok([
            'capabilities' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    }

    /* ============================================================
     * Helpers
     * ============================================================ */
    private function ok(array $body): array
    {
        return [
            'status'  => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
        ];
    }

    private function badRequest(): array
    {
        return [
            'status'  => 400,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['error' => 'INVALID_REQUEST'],
        ];
    }

    private function unauthorized(): array
    {
        return [
            'status'  => 401,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['error' => 'AUTH_REQUIRED'],
        ];
    }

    private function validationError(string $msg): array
    {
        return [
            'status'  => 400,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => [
                'error' => [
                    'code'    => 'VALIDATION_ERROR',
                    'message' => $msg,
                ],
            ],
        ];
    }
}
