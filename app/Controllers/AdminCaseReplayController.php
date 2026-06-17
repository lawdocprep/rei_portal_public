<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\EventReplayService;
use App\Services\ProceduralEventEngineService;
use PDO;

final class AdminCaseReplayController
{
    public function __construct(
        private PDO $db
    ) {}

    public function replay(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->json(401, ['error' => 'UNAUTHORIZED']);
        }

        $tenantId = (string)$ctx->tenantId;
        $actorId  = (string)$ctx->actorId;

        $caseId = $req['params']['id'] ?? null;
        if (!is_string($caseId) || !$this->isUuid($caseId)) {
            return $this->json(400, ['error' => 'INVALID_CASE_ID']);
        }

        $body = $req['body'] ?? [];
        $confirm = (bool)($body['confirm'] ?? false);
        if (!$confirm) {
            return $this->json(400, ['error' => 'CONFIRM_REQUIRED']);
        }

        $dryRun = (bool)($body['dry_run'] ?? false);
        $reason = isset($body['reason']) && is_string($body['reason']) ? $body['reason'] : null;

        $engine = new ProceduralEventEngineService($this->db);
        $svc = new EventReplayService($this->db, $engine);

        $result = $svc->replayCase(
            tenantId: $tenantId,
            caseId: $caseId,
            actorId: $actorId,
            dryRun: $dryRun,
            reason: $reason
        );

        return $this->json(200, [
            'case_id' => $caseId,
            'tenant_id' => $tenantId,
            'replay' => $result,
        ]);
    }

    private function isUuid(string $v): bool
    {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $v);
    }

    private function json(int $status, array $body): array
    {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
        ];
    }
}
