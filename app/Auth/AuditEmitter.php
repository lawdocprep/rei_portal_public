<?php
declare(strict_types=1);

namespace App\Auth;

use PDO;
use PDOException;
use RuntimeException;

final class AuditEmitter
{
    public function __construct(private PDO $db) {}

    /* ============================================================
     * Mutating request lifecycle
     * ============================================================ */

    public function emitAttempt(
        array $req,
        AuthContext $ctx,
        string $endpointKey
    ): string {
        [$entityType, $entityId] = $this->inferEntityScope($req, $endpointKey);

        $sql = <<<SQL
INSERT INTO audit_events (
    tenant_id,
    event_at,
    actor_user_id,
    entity_type,
    entity_id,
    operation,
    summary,
    diff_payload
) VALUES (
    :tenant_id,
    now(),
    :actor_user_id,
    :entity_type,
    :entity_id,
    :operation,
    :summary,
    :diff_payload
)
RETURNING id
SQL;

        try {
            $st = $this->db->prepare($sql);
            $st->execute([
                ':tenant_id'     => $ctx->tenantId,
                ':actor_user_id' => $ctx->actorId,
                ':entity_type'   => $entityType,
                ':entity_id'     => $entityId,
                ':operation'     => $endpointKey . ':attempt',
                ':summary'       => 'attempt',
                ':diff_payload'  => json_encode(
                    ['request' => $this->sanitizeRequest($req)],
                    JSON_UNESCAPED_SLASHES
                ),
            ]);

            $id = $st->fetchColumn();
            if (!is_string($id) || !$this->isUuid($id)) {
                throw new RuntimeException('AUDIT_ATTEMPT_NO_ID');
            }

            return $id;
        } catch (PDOException $e) {
            throw new RuntimeException('AUDIT_ATTEMPT_WRITE_FAILED', 0, $e);
        }
    }

    public function emitResult(
        ?string $auditId,
        array $req,
        AuthContext $ctx,
        string $endpointKey,
        array $resp
    ): void {
        if (!$auditId || !$this->isUuid($auditId)) {
            throw new RuntimeException('AUDIT_RESULT_WITHOUT_ATTEMPT');
        }

        [$entityType, $entityId] = $this->inferEntityScope($req, $endpointKey);

        $sql = <<<SQL
UPDATE audit_events
SET event_at     = now(),
    actor_user_id = :actor_user_id,
    entity_type   = :entity_type,
    entity_id     = :entity_id,
    operation     = :operation,
    summary       = :summary,
    diff_payload  = :diff_payload
WHERE id = :id
  AND tenant_id = :tenant_id
  AND operation LIKE '%:attempt'
SQL;

        try {
            $st = $this->db->prepare($sql);
            $st->execute([
                ':id'            => $auditId,
                ':tenant_id'     => $ctx->tenantId,
                ':actor_user_id' => $ctx->actorId,
                ':entity_type'   => $entityType,
                ':entity_id'     => $entityId,
                ':operation'     => $endpointKey . ':result',
                ':summary'       => (string)($resp['status'] ?? 'result'),
                ':diff_payload'  => json_encode(
                    [
                        'request'  => $this->sanitizeRequest($req),
                        'response' => $this->sanitizeResponse($resp),
                    ],
                    JSON_UNESCAPED_SLASHES
                ),
            ]);

            if ($st->rowCount() !== 1) {
                throw new RuntimeException('AUDIT_RESULT_UPDATE_FAILED');
            }
        } catch (PDOException $e) {
            throw new RuntimeException('AUDIT_RESULT_WRITE_FAILED', 0, $e);
        }
    }

    /* ============================================================
     * Phase 11 – External access auditing
     * ============================================================ */

    public function emitAccess(
        array $req,
        AuthContext $ctx,
        string $endpointKey,
        array $meta = []
    ): void {
        [$entityType, $entityId] = $this->inferEntityScope($req, $endpointKey);

        $sql = <<<SQL
INSERT INTO audit_events (
    tenant_id,
    event_at,
    actor_user_id,
    entity_type,
    entity_id,
    operation,
    summary,
    diff_payload
) VALUES (
    :tenant_id,
    now(),
    :actor_user_id,
    :entity_type,
    :entity_id,
    :operation,
    :summary,
    :diff_payload
)
SQL;

        try {
            $st = $this->db->prepare($sql);
            $st->execute([
                ':tenant_id'     => $ctx->tenantId,
                ':actor_user_id' => $ctx->actorId,
                ':entity_type'   => $entityType,
                ':entity_id'     => $entityId,
                ':operation'     => $endpointKey . ':access',
                ':summary'       => 'evidence access',
                ':diff_payload'  => json_encode(
                    [
                        'request' => $this->sanitizeRequest($req),
                        'meta'    => $this->sanitizeMeta($meta),
                    ],
                    JSON_UNESCAPED_SLASHES
                ),
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('AUDIT_ACCESS_WRITE_FAILED', 0, $e);
        }
    }

    /* ============================================================
     * Sanitization (court-safe)
     * ============================================================ */

    private function sanitizeRequest(array $req): array
    {
        return [
            'method'             => $req['method']             ?? null,
            'path'               => $req['path']               ?? null,
            'canonical_endpoint' => $req['canonical_endpoint'] ?? null,
            'params'             => $req['params']             ?? [],
            'case_id'            => $req['case_id']            ?? null,
            'case_document_id'   => $req['case_document_id']   ?? null,
            'actor_type'         => $req['actor_type']         ?? null,
            'user_agent'         => $req['user_agent']         ?? null,
        ];
    }

    private function sanitizeResponse(array $resp): array
    {
        return [
            'status' => $resp['status'] ?? null,
            'body'   => $this->stripSecrets($resp['body'] ?? []),
        ];
    }

    private function sanitizeMeta(array $meta): array
    {
        return $this->stripSecrets($meta);
    }

    private function stripSecrets(array $data): array
    {
        unset(
            $data['token'],
            $data['authorization'],
            $data['Authorization'],
            $data['access_token'],
            $data['headers']
        );

        return $data;
    }

    /* ============================================================
     * Entity resolution (authoritative)
     * ============================================================ */

    private function inferEntityScope(array $req, string $endpointKey): array
    {
        if (isset($req['params']['grant_id']) && $this->isUuid($req['params']['grant_id'])) {
            return ['evidence_access_grant', $req['params']['grant_id']];
        }

        if (isset($req['params']['export_id']) && $this->isUuid($req['params']['export_id'])) {
            return ['document_export', $req['params']['export_id']];
        }

        if (isset($req['case_document_id']) && $this->isUuid($req['case_document_id'])) {
            return ['case_document', $req['case_document_id']];
        }

        if (isset($req['case_id']) && $this->isUuid($req['case_id'])) {
            return ['case', $req['case_id']];
        }

        return ['system', $req['canonical_endpoint'] ?? 'unknown'];
    }

    private function isUuid(string $v): bool
    {
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $v
        );
    }
}
