<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\FilingIntentAssembler;
use PDO;

final class FilingIntentController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * GET /cases/{id}/legal-actions/{legal_action_id}/filing-intent
     * ============================================================ */
    public function get(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $caseId = $req['params']['id'] ?? null;
        $legalActionId = $req['params']['legal_action_id'] ?? null;

        if (!is_string($caseId) || $caseId === '') {
            return $this->badRequest('INVALID_CASE_ID');
        }

        if (!is_string($legalActionId) || $legalActionId === '') {
            return $this->badRequest('INVALID_LEGAL_ACTION_ID');
        }

        $tenantId = (string)$ctx->tenantId;

        /**
         * Phase 16 invariant:
         * Filing intent is assembled, not retrieved.
         */
        $assembler = new FilingIntentAssembler($this->db);

        $result = $assembler->assemble(
            tenantId: $tenantId,
            caseId: $caseId,
            legalActionId: $legalActionId
        );

        if (isset($result['error'])) {
            return match ($result['error']) {
                'LEGAL_ACTION_NOT_FOUND' =>
                    $this->notFound('LEGAL_ACTION_NOT_FOUND'),

                'LEGAL_ACTION_CASE_MISMATCH' =>
                    $this->forbidden('LEGAL_ACTION_CASE_MISMATCH'),

                default =>
                    $this->serverError($result['error']),
            };
        }

        return [
            'status' => 200,
            'body'   => $result,
        ];
    }

    /* ============================================================
     * Response helpers (unchanged)
     * ============================================================ */

    private function unauthorized(): array
    {
        return ['status' => 401, 'body' => ['error' => 'UNAUTHORIZED']];
    }

    private function forbidden(string $code): array
    {
        return ['status' => 403, 'body' => ['error' => $code]];
    }

    private function badRequest(string $code): array
    {
        return ['status' => 400, 'body' => ['error' => $code]];
    }

    private function notFound(string $code): array
    {
        return ['status' => 404, 'body' => ['error' => $code]];
    }

    private function serverError(string $code): array
    {
        return ['status' => 500, 'body' => ['error' => $code]];
    }
}
