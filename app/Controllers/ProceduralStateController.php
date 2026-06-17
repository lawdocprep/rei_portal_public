<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\ProceduralStateService;
use PDO;

final class ProceduralStateController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * GET /cases/{id}/procedural-state
     * ============================================================ */
    public function getProceduralState(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return [
                'status' => 401,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['error' => 'AUTH_REQUIRED'],
            ];
        }

        $tenantId = (string)$ctx->tenantId;
        $caseId = $req['params']['id'] ?? $req['params']['case_id'] ?? null;

        if (!is_string($caseId) || $caseId === '') {
            return [
                'status' => 400,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['error' => 'Missing case id'],
            ];
        }

        $svc = new ProceduralStateService($this->db);
        $state = $svc->build($tenantId, $caseId);

        if (!empty($state['error']) && $state['error'] === 'CASE_NOT_FOUND') {
            return [
                'status' => 404,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['error' => 'CASE_NOT_FOUND'],
            ];
        }

        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $state,
        ];
    }
}
