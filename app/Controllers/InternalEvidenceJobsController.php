<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;
use Throwable;

final class InternalEvidenceJobsController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * POST /internal/jobs/expire-evidence-access-grants
     *
     * Job semantics:
     * - Marks expired grants as revoked (revoked_at set if not already set)
     * - Sets revoked_by_actor_* to a system actor for attribution
     * - Idempotent: re-running does not change already-revoked rows
     * ============================================================ */
    public function expireAccessGrants(): array
    {
        $this->db->beginTransaction();

        try {
            // NOTE:
            // We "expire" by setting revoked_at when expires_at is reached.
            // This keeps a single authoritative "inactive" mechanism in AuthMiddleware:
            // revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now()).
            $sql = <<<SQL
UPDATE evidence_access_grants
   SET revoked_at = COALESCE(revoked_at, now()),
       revoked_by_actor_id = COALESCE(revoked_by_actor_id, :actor_id),
       revoked_by_actor_type = COALESCE(revoked_by_actor_type, :actor_type)
 WHERE revoked_at IS NULL
   AND expires_at IS NOT NULL
   AND expires_at <= now()
SQL;

            $st = $this->db->prepare($sql);
            $st->execute([
                ':actor_id'   => 'system:expire-job',
                ':actor_type' => 'system',
            ]);

            $count = $st->rowCount();

            $this->db->commit();

            return $this->json(200, [
                'status' => 'ok',
                'expired_grants_revoked' => $count,
            ]);
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->json(500, ['error' => 'INTERNAL_ERROR']);
        }
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function json(int $status, array $body): array
    {
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
        ];
    }
}
