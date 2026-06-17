<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\FilingReadyService;
use PDO;

final class FilingReadinessController
{
    public function __construct(
        private PDO $db
    ) {}

    /**
     * POST /cases/{id}/legal-actions/{legal_action_id}/ready-to-file
     *
     * Body:
     * {
     *   "intent": {...},
     *   "artifacts": {...}
     * }
     */
    public function readyToFile(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->json(401, ['error' => 'UNAUTHORIZED']);
        }

        $tenantId      = (string)$ctx->tenantId;
        $caseId        = $req['params']['id'] ?? null;
        $legalActionId = $req['params']['legal_action_id'] ?? null;

        if (!$this->isUuid($caseId) || !$this->isUuid($legalActionId)) {
            return $this->json(400, ['error' => 'INVALID_IDENTIFIER']);
        }

        // 🚫 Phase 16.9 — Body not allowed
        if (!empty($req['body'])) {
            return $this->json(400, ['error' => 'REQUEST_BODY_NOT_ALLOWED']);
        }

        // ------------------------------------------------------------
        // Verify legal action exists
        // ------------------------------------------------------------
        $st = $this->db->prepare("
            SELECT action_type
            FROM legal_actions
            WHERE tenant_id = :tenant
            AND case_id   = :case
            AND id        = :id
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
            ':id'     => $legalActionId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return $this->json(404, ['error' => 'LEGAL_ACTION_NOT_FOUND']);
        }

        $actionType = (string)$row['action_type'];

        // ------------------------------------------------------------
        // Phase 16.9 — Server-authoritative readiness
        // ------------------------------------------------------------
        $readyService = new FilingReadyService($this->db);

        $report = $readyService->evaluate(
            $tenantId,
            $caseId,
            $legalActionId
        );

        return $this->json(200, $report);
    }

    private function json(int $status, array $body): array
    {
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
        ];
    }

    private function isUuid(?string $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        // Relaxed structural UUID check (no RFC bit enforcement)
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        );
    }
}