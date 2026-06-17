<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\Filing\FilingDocumentRenderService;
use PDO;
use RuntimeException;

final class FilingDocumentGenerationController
{
    private FilingDocumentRenderService $documentRenderService;

    public function __construct(
        private PDO $db
    ) {
        $this->documentRenderService =
            new FilingDocumentRenderService($this->db);
    }

    /**
     * POST
     * /cases/{case_id}/legal-actions/{legal_action_id}/generate-documents
     *
     * Preconditions:
     *
     * - bundle exists
     * - bundle is committed
     * - bundle is frozen
     *
     * Behavior:
     *
     * - generates missing documents
     * - assembles filing packet
     * - returns generated document metadata
     */
    public function generateDocuments(
        array $req,
        ?AuthContext $ctx
    ): array {

        if ($ctx === null) {
            return $this->json(
                401,
                ['error' => 'UNAUTHORIZED']
            );
        }

        $tenantId = (string)$ctx->tenantId;
        $actorId  = (string)$ctx->actorId;

        $caseId = (string)($req['params']['case_id'] ?? '');
        $legalActionId = (string)($req['params']['legal_action_id'] ?? '');

        if ($caseId === '') {
            return $this->json(
                400,
                ['error' => 'CASE_ID_REQUIRED']
            );
        }

        if ($legalActionId === '') {
            return $this->json(
                400,
                ['error' => 'LEGAL_ACTION_ID_REQUIRED']
            );
        }

        try {

            $result =
                $this->documentRenderService
                    ->generateFromCommittedBundle(
                        $tenantId,
                        $caseId,
                        $legalActionId,
                        $actorId
                    );

            return $this->json(
                200,
                $result
            );

        } catch (RuntimeException $e) {

            return $this->json(
                400,
                [
                    'error'  => 'DOCUMENT_GENERATION_FAILED',
                    'detail' => $e->getMessage()
                ]
            );

        } catch (\Throwable $e) {

            return $this->json(
                500,
                [
                    'error' => 'INTERNAL_ERROR'
                ]
            );
        }
    }

    private function json(
        int $status,
        array $payload
    ): array {
        return [
            'status' => $status,
            'body'   => $payload
        ];
    }
}