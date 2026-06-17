<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;

final class PublicEvidenceAccessController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * GET /evidence/access/{token}
     * ============================================================ */
    public function get(array $req): array
    {
        $token = $req['params']['token'] ?? null;
        if (!is_string($token)) {
            return $this->notFound();
        }

        $hash = hash('sha256', $token);

        $q = $this->db->prepare(
            "SELECT
                e.storage_uri,
                e.content_hash
             FROM evidence_access_grants g
             JOIN document_exports e
               ON e.id = g.document_export_id
              AND e.tenant_id = g.tenant_id
             WHERE g.token_hash = :hash
               AND g.revoked_at IS NULL
               AND (g.expires_at IS NULL OR g.expires_at > now())
             LIMIT 1"
        );
        $q->execute([':hash' => $hash]);

        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $this->notFound();
        }

        // NOTE:
        // In production, you would either:
        // - stream from object storage
        // - generate a signed redirect
        // - proxy the file
        return [
            'status'  => 302,
            'headers' => [
                'Location' => $row['storage_uri'],
            ],
            'body' => null,
        ];
    }

    private function notFound(): array
    {
        return [
            'status'  => 404,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['error' => 'INVALID_OR_EXPIRED_TOKEN'],
        ];
    }
}
