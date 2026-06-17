<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\Filing\FilingDocumentRenderService;
use PDO;

final class FilingDocumentsController
{
    public function __construct(
        private PDO $db,
        private StorageService $storage
    ) {}

    /* ============================================================
     * POST /cases/{id}/legal-actions/{legal_action_id}/generate-filing-documents
     * ============================================================ */
    public function generate(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $caseId        = $req['params']['id'] ?? null;
        $legalActionId = $req['params']['legal_action_id'] ?? null;

        if (!$this->isUuid($caseId) || !$this->isUuid($legalActionId)) {
            return $this->badRequest('INVALID_IDENTIFIER');
        }

        $tenantId = (string)$ctx->tenantId;
        $actorId  = (string)$ctx->actorId;

        try {

            $service = new FilingDocumentRenderService($this->db);

            $result = $service->generateDocuments(
                tenantId: $tenantId,
                caseId: $caseId,
                legalActionId: $legalActionId,
                actorId: $actorId
            );

            return $this->json(200, [
                'case_id' => $caseId,
                'legal_action_id' => $legalActionId,
                'bundle_id' => $result['bundle_id'] ?? null,
                'documents' => $result['documents'] ?? []
            ]);

        } catch (\RuntimeException $e) {

            $code = $e->getMessage();

            return match ($code) {

                'BUNDLE_NOT_COMMITTED' =>
                    $this->conflict('BUNDLE_NOT_COMMITTED'),

                'COMMITTED_BUNDLE_NOT_FOUND' =>
                    $this->notFound('COMMITTED_BUNDLE_NOT_FOUND'),

                default =>
                    $this->serverError($code)
            };

        } catch (\Throwable $e) {

            return $this->serverError($e->getMessage());
        }
    }

    /* ============================================================
     * GET /cases/{id}/legal-actions/{legal_action_id}/filing-documents
     * ============================================================ */
    public function list(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $caseId        = $req['params']['id'] ?? null;
        $legalActionId = $req['params']['legal_action_id'] ?? null;

        if (!$this->isUuid($caseId) || !$this->isUuid($legalActionId)) {
            return $this->badRequest('INVALID_IDENTIFIER');
        }

        $tenantId = (string)$ctx->tenantId;

        $bundle = $this->loadCommittedBundle(
            $tenantId,
            $caseId,
            $legalActionId
        );

        if (!$bundle) {
            return $this->notFound('COMMITTED_BUNDLE_NOT_FOUND');
        }

        $st = $this->db->prepare("
            SELECT
                id,
                document_type,
                file_path,
                file_hash,
                created_at
            FROM filing_documents
            WHERE tenant_id = :tenant
              AND bundle_id = :bundle_id
            ORDER BY created_at ASC
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':bundle_id' => $bundle['id']
        ]);

        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return $this->json(200, [
            'case_id' => $caseId,
            'legal_action_id' => $legalActionId,
            'bundle_id' => $bundle['id'],
            'documents' => $rows
        ]);
    }

    /* ============================================================ */

    private function loadCommittedBundle(
        string $tenantId,
        string $caseId,
        string $legalActionId
    ): ?array {

        $st = $this->db->prepare("
            SELECT id
            FROM filing_bundles
            WHERE tenant_id = :tenant
              AND case_id = :case_id
              AND legal_action_id = :legal_action_id
              AND freeze_level = 'committed'
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case_id' => $caseId,
            ':legal_action_id' => $legalActionId
        ]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function binary(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $caseId        = $req['params']['id'] ?? null;
        $legalActionId = $req['params']['legal_action_id'] ?? null;
        $documentType  = $req['params']['document_type'] ?? null;

        if (!$this->isUuid($caseId) || !$this->isUuid($legalActionId) || !$documentType) {
            return $this->badRequest('INVALID_IDENTIFIER');
        }

        $tenantId = (string)$ctx->tenantId;

        $bundle = $this->loadCommittedBundle(
            $tenantId,
            $caseId,
            $legalActionId
        );

        if (!$bundle) {
            return $this->notFound('COMMITTED_BUNDLE_NOT_FOUND');
        }

        /* ------------------------------------------------------------
        * FETCH DOCUMENT STORAGE KEY
        * ------------------------------------------------------------ */

        $st = $this->db->prepare("
            SELECT storage_key
            FROM filing_documents
            WHERE tenant_id = :tenant
            AND bundle_id = :bundle
            AND document_type = :type
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':bundle' => $bundle['id'],
            ':type'   => $documentType
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return $this->notFound('DOCUMENT_NOT_FOUND');
        }

        /* ------------------------------------------------------------
        * STREAM DOCUMENT
        * ------------------------------------------------------------ */

        $storage = new \App\Services\Storage\StorageService();

        $data = $this->storage->stream(
            $row['storage_key']
        );

        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$documentType.'.pdf"',
            ],
            'body' => $data
        ];
    }

    private function json(int $status, array $body): array
    {
        return [
            'status' => $status,
            'body'   => $body
        ];
    }

    private function unauthorized(): array
    {
        return $this->json(401, ['error' => 'UNAUTHORIZED']);
    }

    private function badRequest(string $code): array
    {
        return $this->json(400, ['error' => $code]);
    }

    private function conflict(string $code): array
    {
        return $this->json(409, ['error' => $code]);
    }

    private function notFound(string $code): array
    {
        return $this->json(404, ['error' => $code]);
    }

    private function serverError(string $code): array
    {
        return $this->json(500, ['error' => $code]);
    }

    private function isUuid(?string $value): bool
    {
        if (!$value) {
            return false;
        }

        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }
}