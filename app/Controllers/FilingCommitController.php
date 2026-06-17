<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\Filing\FilingFreezeService;
use PDO;

final class FilingCommitController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * POST /cases/{id}/legal-actions/{legal_action_id}/commit-filing
     * ============================================================ */
    public function commitFiling(array $req, ?AuthContext $ctx): array
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

        $svc = new FilingFreezeService($this->db);

        try {

            $result = $svc->commitFiling(
                tenantId: $tenantId,
                caseId: $caseId,
                legalActionId: $legalActionId,
                actorId: $actorId
            );

            return $this->json(200, $result);

        } catch (\RuntimeException $e) {

            $code = $e->getMessage();

            return match ($code) {

                'LEGAL_ACTION_NOT_FOUND' =>
                    $this->json(404, ['error' => $code]),

                'LEGAL_ACTION_CASE_MISMATCH' =>
                    $this->json(409, ['error' => $code]),

                'LEGAL_ACTION_STATUS_INVALID' =>
                    $this->json(409, ['error' => $code]),

                'PREPARED_BUNDLE_NOT_FOUND' =>
                    $this->json(409, ['error' => $code]),

                'BUNDLE_HASH_MISMATCH' =>
                    $this->json(409, ['error' => $code]),

                default =>
                    $this->json(500, [
                        'error'  => 'INTERNAL_ERROR',
                        'detail' => $code
                    ]),
            };
        }
    }

    /* ======================== helpers ======================== */

    private function json(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
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