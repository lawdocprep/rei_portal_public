<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;

final class AdminExportsGovernanceController
{
    public function __construct(private PDO $db) {}

    /* ============================================================
     * GET /admin/exports/governance-summary
     * ============================================================ */
    public function summary(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $tenantId = (string)$ctx->tenantId;

        $sql = "
            SELECT
                COUNT(DISTINCT de.id) AS total_exports,
                COUNT(DISTINCT eag.id) FILTER (
                    WHERE eag.revoked_at IS NULL
                      AND (eag.expires_at IS NULL OR eag.expires_at > now())
                ) AS active_grants,
                COUNT(DISTINCT eag.id) FILTER (
                    WHERE eag.revoked_at IS NOT NULL
                ) AS revoked_grants,
                COUNT(DISTINCT eag.id) FILTER (
                    WHERE eag.expires_at IS NOT NULL AND eag.expires_at <= now()
                ) AS expired_grants
            FROM document_exports de
            LEFT JOIN evidence_access_grants eag
              ON eag.document_export_id = de.id
             AND eag.tenant_id = de.tenant_id
            WHERE de.tenant_id = :tenant
        ";

        $st = $this->db->prepare($sql);
        $st->execute([':tenant' => $tenantId]);

        return $this->json(200, $st->fetch(PDO::FETCH_ASSOC));
    }

    private function json(int $status, array $body): array
    {
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
        ];
    }

    private function unauthorized(): array
    {
        return $this->json(401, ['error' => 'AUTH_REQUIRED']);
    }
}
