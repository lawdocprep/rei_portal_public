<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;

final class EvidenceAccessGrantsController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * POST /document-exports/{export_id}/access-grants
     * ============================================================ */
    public function create(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $exportId = $req['params']['export_id'] ?? null;
        if (!is_string($exportId)) return $this->badRequest();

        $body = $req['body'] ?? [];
        if (!is_array($body)) return $this->badRequest();

        $subject    = $body['subject'] ?? null;
        $purpose    = $body['purpose'] ?? null;
        $expiresAt  = $body['expires_at'] ?? null;

        if (!is_string($subject) || !is_string($purpose)) {
            return $this->badRequest();
        }

        // Verify export exists + tenant isolation
        $chk = $this->db->prepare(
            "SELECT id
               FROM document_exports
              WHERE id = :id
                AND tenant_id = :tenant
              LIMIT 1"
        );
        $chk->execute([
            ':id'     => $exportId,
            ':tenant' => $ctx->tenantId,
        ]);

        if (!$chk->fetchColumn()) {
            return $this->notFound();
        }

        // Generate secure token
        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $grantId   = $this->uuid();

        $ins = $this->db->prepare(
            "INSERT INTO evidence_access_grants (
                id,
                tenant_id,
                document_export_id,
                subject,
                purpose,
                token_hash,
                issued_at,
                issued_by_actor_id,
                issued_by_actor_type,
                expires_at
            ) VALUES (
                :id,
                :tenant,
                :export,
                :subject,
                :purpose,
                :token_hash,
                now(),
                :actor,
                :actor_type,
                :expires_at
            )"
        );

        $ins->execute([
            ':id'         => $grantId,
            ':tenant'     => $ctx->tenantId,
            ':export'     => $exportId,
            ':subject'    => $subject,
            ':purpose'    => $purpose,
            ':token_hash' => $tokenHash,
            ':actor'      => $ctx->actorId,
            ':actor_type' => $ctx->actorType,
            ':expires_at' => $expiresAt,
        ]);

        return $this->json(201, [
            'grant_id' => $grantId,
            'access_url' => "/evidence/access/{$token}",
            'expires_at' => $expiresAt,
        ]);
    }

    /* ============================================================
     * GET /document-exports/{export_id}/access-grants
     * ============================================================ */
    public function list(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $exportId = $req['params']['export_id'] ?? null;
        if (!is_string($exportId)) return $this->badRequest();

        $q = $this->db->prepare(
            "SELECT
                id,
                subject,
                purpose,
                issued_at,
                expires_at,
                revoked_at
             FROM evidence_access_grants
             WHERE tenant_id = :tenant
               AND document_export_id = :export
             ORDER BY issued_at ASC"
        );
        $q->execute([
            ':tenant' => $ctx->tenantId,
            ':export' => $exportId,
        ]);

        return $this->json(200, [
            'document_export_id' => $exportId,
            'grants' => $q->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    /* ============================================================
     * POST /evidence-access-grants/{id}/revoke
     * ============================================================ */
    public function revoke(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $id = $req['params']['id'] ?? null;
        if (!is_string($id)) return $this->badRequest();

        $upd = $this->db->prepare(
            "UPDATE evidence_access_grants
                SET revoked_at = now(),
                    revoked_by_actor_id = :actor,
                    revoked_by_actor_type = :actor_type
              WHERE id = :id
                AND tenant_id = :tenant
                AND revoked_at IS NULL"
        );

        $upd->execute([
            ':id'         => $id,
            ':tenant'     => $ctx->tenantId,
            ':actor'      => $ctx->actorId,
            ':actor_type' => $ctx->actorType,
        ]);

        if ($upd->rowCount() === 0) {
            return $this->notFound();
        }

        return $this->json(200, ['status' => 'revoked']);
    }

    /* ================= Helpers ================= */

    private function json(int $status, array $body): array
    {
        return ['status' => $status, 'headers' => ['Content-Type' => 'application/json'], 'body' => $body];
    }

    private function unauthorized(): array
    {
        return $this->json(401, ['error' => 'AUTH_REQUIRED']);
    }

    private function badRequest(): array
    {
        return $this->json(400, ['error' => 'INVALID_REQUEST']);
    }

    private function notFound(): array
    {
        return $this->json(404, ['error' => 'NOT_FOUND']);
    }

    private function uuid(): string
    {
        return bin2hex(random_bytes(16));
    }
}
