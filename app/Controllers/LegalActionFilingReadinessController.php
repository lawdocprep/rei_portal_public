<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\FilingReadinessService;
use PDO;

final class LegalActionFilingReadinessController
{
    public function __construct(private PDO $db) {}

    public function get(array $req, AuthContext $ctx): array
    {
        $caseId = (string)($req['params']['id'] ?? '');
        $legalActionId = (string)($req['params']['legal_action_id'] ?? '');

        if ($caseId === '' || $legalActionId === '') {
            return [
                'status' => 400,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['error' => 'INVALID_REQUEST'],
            ];
        }

        $svc = new FilingReadinessService($this->db);
        $result = $svc->assess($ctx->tenantId, $caseId, $legalActionId);

        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $result,
        ];
    }
}
