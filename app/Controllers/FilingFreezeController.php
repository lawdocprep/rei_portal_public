<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\Filing\FilingFreezeService;
use PDO;

final class FilingFreezeController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * POST /cases/{id}/legal-actions/{legal_action_id}/prepare-for-filing
     * ============================================================ */
    public function prepareForFiling(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $tenantId = (string)$ctx->tenantId;
        $actorId  = (string)$ctx->actorId;

        $caseId = $req['params']['id'] ?? null;
        $legalActionId = $req['params']['legal_action_id'] ?? null;

        if (!is_string($caseId) || !$this->isUuid($caseId)) {
            return $this->badRequest('INVALID_CASE_ID');
        }

        if (!is_string($legalActionId) || !$this->isUuid($legalActionId)) {
            return $this->badRequest('INVALID_LEGAL_ACTION_ID');
        }

        $startedTx = false;

        try {
            /*
             * ------------------------------------------------------------
             * Start a transaction so the xact advisory lock actually
             * persists through the prepare-for-filing call.
             * ------------------------------------------------------------
             */
            if (!$this->db->inTransaction()) {
                $this->db->beginTransaction();
                $startedTx = true;
            }

            /*
             * ------------------------------------------------------------
             * Cross-server reproducibility hardening
             *
             * Force transaction-local UTC so any DB-side textual timestamp
             * serialization used in hashing / snapshotting is stable.
             * ------------------------------------------------------------
             */
            $this->db->exec("SET LOCAL TIME ZONE 'UTC'");

            /*
             * ------------------------------------------------------------
             * Double-freeze protection
             *
             * Serialize prepare-for-filing attempts for the case so that
             * only one worker can compute/freeze the bundle at once.
             * ------------------------------------------------------------
             */
            $lock = $this->db->prepare("
                SELECT pg_advisory_xact_lock(
                    ('x' || substr(replace(:tenant_id,'-',''),1,8))::bit(32)::int,
                    ('x' || substr(replace(:case_id,'-',''),1,8))::bit(32)::int
                )
            ");

            $lock->execute([
                ':tenant_id' => $tenantId,
                ':case_id'   => $caseId
            ]);

            /*
             * Lazy dependency construction:
             * FilingFreezeService will internally create CaseEventService
             * if one is not provided.
             */
            $svc = new FilingFreezeService($this->db);

            $result = $svc->prepareForFiling(
                tenantId: $tenantId,
                caseId: $caseId,
                legalActionId: $legalActionId,
                actorId: $actorId
            );

            if ($startedTx && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return $this->json(200, $result);

        } catch (\RuntimeException $e) {

            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $code = $e->getMessage();

            return match ($code) {
                'NOT_READY_FOR_FILING'
                    => $this->json(409, ['error' => $code]),

                'LEGAL_ACTION_NOT_FOUND'
                    => $this->json(404, ['error' => $code]),

                'LEGAL_ACTION_CASE_MISMATCH'
                    => $this->json(409, ['error' => $code]),

                'LEGAL_ACTION_STATUS_INVALID'
                    => $this->json(409, ['error' => $code]),

                default
                    => $this->json(500, [
                        'error'  => 'INTERNAL_ERROR',
                        'detail' => $code
                    ]),
            };

        } catch (\Throwable $e) {

            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return $this->json(500, [
                'error'  => 'INTERNAL_ERROR',
                'detail' => $e->getMessage()
            ]);
        }
    }

    /* ======================== helpers ======================== */

    private function json(int $status, array $body): array
    {
        return [
            'status' => $status,
            'body'   => $body
        ];
    }

    private function badRequest(string $code): array
    {
        return $this->json(400, ['error' => $code]);
    }

    private function unauthorized(): array
    {
        return $this->json(401, ['error' => 'UNAUTHORIZED']);
    }

    private function isUuid(string $v): bool
    {
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $v
        );
    }
}