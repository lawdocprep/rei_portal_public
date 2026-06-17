<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\FilingIntentAssembler;
use App\Services\FilingArtifactAssemblyService;
use App\Services\Validation\FilingArtifactValidationService;
use PDO;

final class FilingArtifactsController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * GET /cases/{id}/legal-actions/{legal_action_id}/filing-artifacts
     * ============================================================ */
    public function get(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $caseId        = $req['params']['id'] ?? null;
        $legalActionId = $req['params']['legal_action_id'] ?? null;

        if (!is_string($caseId) || $caseId === '') {
            return $this->badRequest('INVALID_CASE_ID');
        }

        if (!is_string($legalActionId) || $legalActionId === '') {
            return $this->badRequest('INVALID_LEGAL_ACTION_ID');
        }

        $tenantId = (string)$ctx->tenantId;

        /* --------------------------------------------------------
         * 1️⃣ Assemble Filing Intent (authoritative)
         * -------------------------------------------------------- */
        $intentAssembler = new FilingIntentAssembler($this->db);

        $intent = $intentAssembler->assemble(
            tenantId: $tenantId,
            caseId: $caseId,
            legalActionId: $legalActionId
        );

        if (isset($intent['error'])) {
            return match ($intent['error']) {
                'LEGAL_ACTION_NOT_FOUND' =>
                    $this->notFound('LEGAL_ACTION_NOT_FOUND'),

                'LEGAL_ACTION_CASE_MISMATCH' =>
                    $this->forbidden('LEGAL_ACTION_CASE_MISMATCH'),

                default =>
                    $this->serverError($intent['error']),
            };
        }

        if (($intent['tenant_id'] ?? $tenantId) !== $tenantId) {
            return $this->forbidden('TENANT_MISMATCH');
        }

        /* --------------------------------------------------------
         * 2️⃣ Assemble Preview Artifacts
         * -------------------------------------------------------- */
        $artifactSvc = new FilingArtifactAssemblyService();
        $assembly    = $artifactSvc->assemble($intent);

        /* --------------------------------------------------------
         * 3️⃣ Determine Debug Trace Flag (Header Driven)
         * -------------------------------------------------------- */
        $traceEnabled = false;

        $headers = $req['headers'] ?? [];

        foreach ($headers as $key => $value) {
            if (strtolower((string)$key) === 'x-validation-debug') {
                $traceEnabled = in_array(
                    strtolower((string)$value),
                    ['1', 'true', 'yes'],
                    true
                );
                break;
            }
        }

        /* --------------------------------------------------------
         * 4️⃣ Full Structural Validation Pipeline
         * -------------------------------------------------------- */
        $validationService = new FilingArtifactValidationService();

        $validated = $validationService->validateBundle(
            $assembly['artifacts'] ?? [],
            $intent,
            $traceEnabled
        );

        return [
            'status' => 200,
            'body' => [
                'legal_action_id'                  => $legalActionId,
                'action_type'                      => $intent['action_type'] ?? null,
                'artifacts'                        => $validated['artifacts'],
                'bundle_validation'                => $validated['bundle_validation'],
                'is_structurally_ready_for_filing' =>
                    $validated['is_structurally_ready_for_filing'],
                'warnings'                         => $assembly['warnings'] ?? [],
            ],
        ];
    }

    /* ============================================================
     * Response helpers
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
