<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;
use Throwable;
use DateInterval;
use DateTimeImmutable;

final class ExportAccessGrantsController
{
    /**
     * Maximum allowed lifetime for an evidence access grant.
     */
    private const MAX_GRANT_INTERVAL = 'P90D'; // 90 days

    public function __construct(private PDO $db) {}

    /* ============================================================
     * POST /case-documents/{id}/exports/{export_id}/access-grants
     * ============================================================ */
    public function create(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId = (string)$ctx->tenantId;
        $docId    = $req['params']['id'] ?? null;
        $exportId = $req['params']['export_id'] ?? null;

        if (!$this->isUuid($docId) || !$this->isUuid($exportId)) {
            return $this->invalid();
        }

        $body = is_array($req['body'] ?? null) ? $req['body'] : [];

        $subject    = $body['subject'] ?? null;
        $purpose    = $body['purpose'] ?? null;
        $expiresAt  = $body['expires_at'] ?? null;
        $expiresIn  = $body['expires_in'] ?? null;
        $metadataIn = $body['metadata'] ?? null;

        if (!is_string($subject) || trim($subject) === '') {
            return $this->validation('subject required');
        }
        if (!is_string($purpose) || trim($purpose) === '') {
            return $this->validation('purpose required');
        }

        // --------------------------------------------------
        // Resolve expiry
        // --------------------------------------------------
        $now = new DateTimeImmutable('now');
        $expiresAtTs = null;

        if (is_string($expiresAt) && trim($expiresAt) !== '') {
            try {
                $expiresAtTs = new DateTimeImmutable($expiresAt);
            } catch (Throwable) {
                return $this->validation('invalid expires_at');
            }
        }

        if ($expiresAtTs === null && is_string($expiresIn) && trim($expiresIn) !== '') {
            try {
                $expiresAtTs = $now->add(new DateInterval($expiresIn));
            } catch (Throwable) {
                return $this->validation('invalid expires_in');
            }
        }

        if ($expiresAtTs !== null) {
            $maxExpiry = $now->add(new DateInterval(self::MAX_GRANT_INTERVAL));
            if ($expiresAtTs > $maxExpiry) {
                return $this->validation('expires_at exceeds maximum allowed duration');
            }
        }

        // --------------------------------------------------
        // Metadata
        // --------------------------------------------------
        $metadataJson = null;
        if ($metadataIn !== null) {
            if (!is_array($metadataIn)) {
                return $this->validation('metadata must be an object');
            }
            $metadataJson = json_encode($metadataIn, JSON_THROW_ON_ERROR);
        }

        // --------------------------------------------------
        // Ensure export exists + tenant isolation
        // --------------------------------------------------
        $st = $this->db->prepare(
            "SELECT 1
               FROM document_exports
              WHERE tenant_id = :tenant
                AND id = :export
                AND case_document_id = :doc
              LIMIT 1"
        );
        $st->execute([
            ':tenant' => $tenantId,
            ':export' => $exportId,
            ':doc'    => $docId,
        ]);
        if (!$st->fetchColumn()) {
            return $this->notFound();
        }

        // --------------------------------------------------
        // Generate token
        // --------------------------------------------------
        $token     = $this->base64url(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $grantId   = $this->uuidV4();

        $this->db->beginTransaction();
        try {
            $sql = <<<SQL
INSERT INTO evidence_access_grants (
    id,
    tenant_id,
    document_export_id,
    subject,
    purpose,
    token_hash,
    issued_at,
    issued_by_actor_id,
    issued_by_actor_type,
    expires_at,
    metadata
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
    :expires_at,
    :metadata
)
RETURNING id, issued_at, expires_at
SQL;

            $ins = $this->db->prepare($sql);
            $ins->execute([
                ':id'         => $grantId,
                ':tenant'     => $tenantId,
                ':export'     => $exportId,
                ':subject'    => $subject,
                ':purpose'    => $purpose,
                ':token_hash' => $tokenHash,
                ':actor'      => $ctx->actorId,
                ':actor_type' => $ctx->actorType,
                ':expires_at' => $expiresAtTs?->format('c'),
                ':metadata'   => $metadataJson,
            ]);

            $row = $ins->fetch(PDO::FETCH_ASSOC);
            $this->db->commit();

            return $this->json(201, [
                'grant_id'   => $row['id'],
                'export_id'  => $exportId,
                'token'      => $token,
                'issued_at'  => $row['issued_at'],
                'expires_at' => $row['expires_at'],
            ]);
        } catch (Throwable) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return $this->internal();
        }
    }

    /* ============================================================
     * GET /case-documents/{id}/exports/{export_id}/access-grants
     * Admin list-by-export
     * ============================================================ */
    public function listByExport(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId = (string)$ctx->tenantId;
        $docId    = $req['params']['id'] ?? null;
        $exportId = $req['params']['export_id'] ?? null;

        if (!$this->isUuid($docId) || !$this->isUuid($exportId)) {
            return $this->invalid();
        }

        $st = $this->db->prepare(
            "SELECT
                id,
                subject,
                purpose,
                issued_at,
                expires_at,
                revoked_at,
                issued_by_actor_id,
                issued_by_actor_type
             FROM evidence_access_grants
             WHERE tenant_id = :tenant
               AND document_export_id = :export
             ORDER BY issued_at DESC"
        );
        $st->execute([
            ':tenant' => $tenantId,
            ':export' => $exportId,
        ]);

        $rows = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $status = 'active';
            if ($r['revoked_at'] !== null) {
                $status = 'revoked';
            } elseif ($r['expires_at'] !== null && strtotime($r['expires_at']) <= time()) {
                $status = 'expired';
            }

            $rows[] = [
                'grant_id'  => $r['id'],
                'subject'   => $r['subject'],
                'purpose'   => $r['purpose'],
                'status'    => $status,
                'issued_at' => $r['issued_at'],
                'expires_at'=> $r['expires_at'],
                'issued_by' => [
                    'actor_id'   => $r['issued_by_actor_id'],
                    'actor_type' => $r['issued_by_actor_type'],
                ],
            ];
        }

        return $this->json(200, [
            'export_id' => $exportId,
            'grants'    => $rows,
        ]);
    }

    /* ============================================================
     * POST /access-grants/{grant_id}/revoke
     * ============================================================ */
    public function revoke(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId = (string)$ctx->tenantId;
        $grantId  = $req['params']['grant_id'] ?? null;

        if (!$this->isUuid($grantId)) return $this->invalid();

        $st = $this->db->prepare(
            "UPDATE evidence_access_grants
                SET revoked_at = COALESCE(revoked_at, now()),
                    revoked_by_actor_id = :actor,
                    revoked_by_actor_type = :actor_type
              WHERE tenant_id = :tenant
                AND id = :id
            RETURNING id, revoked_at, expires_at, document_export_id"
        );
        $st->execute([
            ':tenant'     => $tenantId,
            ':id'         => $grantId,
            ':actor'      => $ctx->actorId,
            ':actor_type' => $ctx->actorType,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $this->notFound();

        return $this->json(200, [
            'grant_id'   => $row['id'],
            'export_id'  => $row['document_export_id'],
            'revoked_at'=> $row['revoked_at'],
            'expires_at'=> $row['expires_at'],
            'status'    => 'revoked',
        ]);
    }

    /* ============================================================
     * Automatic expiry cleanup (cron / job)
     * ============================================================ */
    public function cleanupExpired(): array
    {
        $st = $this->db->prepare(
            "UPDATE evidence_access_grants
                SET revoked_at = now()
              WHERE revoked_at IS NULL
                AND expires_at IS NOT NULL
                AND expires_at <= now()"
        );
        $st->execute();

        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => [
                'expired_revoked' => $st->rowCount(),
            ],
        ];
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function uuidV4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    private function isUuid(?string $v): bool
    {
        return is_string($v) && (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $v
        );
    }

    private function json(int $status, array $body): array
    {
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
        ];
    }

    private function unauthorized(): array { return $this->json(401, ['error' => 'AUTH_REQUIRED']); }
    private function invalid(): array      { return $this->json(400, ['error' => 'INVALID_REQUEST']); }
    private function validation(string $m): array { return $this->json(422, ['error' => $m]); }
    private function notFound(): array     { return $this->json(404, ['error' => 'NOT_FOUND']); }
    private function internal(): array     { return $this->json(500, ['error' => 'INTERNAL']); }
}
