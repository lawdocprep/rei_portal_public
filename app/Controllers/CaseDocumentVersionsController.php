<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;

final class CaseDocumentVersionsController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * GET /case-documents/{id}/versions
     * ============================================================ */
    public function list(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $documentId = $req['id'] ?? null;
        if ($documentId === null) {
            return $this->notFound();
        }

        // Ensure document exists and belongs to tenant
        $docStmt = $this->db->prepare("
            SELECT id, current_version_id
            FROM case_documents
            WHERE id = :id
              AND tenant_id = :tenant_id
            LIMIT 1
        ");
        $docStmt->execute([
            'id'        => $documentId,
            'tenant_id' => $ctx->tenantId,
        ]);

        $document = $docStmt->fetch(PDO::FETCH_ASSOC);
        if (!$document) {
            return $this->notFound();
        }

        $stmt = $this->db->prepare("
            SELECT
                dv.id,
                dv.version_number,
                dv.is_final,
                dv.storage_uri,
                dv.content_hash,
                (dv.id = cd.current_version_id) AS is_current
            FROM document_versions dv
            JOIN case_documents cd ON cd.id = dv.document_id
            WHERE cd.id = :document_id
              AND cd.tenant_id = :tenant_id
            ORDER BY dv.version_number ASC
        ");

        $stmt->execute([
            'document_id' => $documentId,
            'tenant_id'   => $ctx->tenantId,
        ]);

        $versions = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $versions[] = [
                'version_number' => (int)$row['version_number'],
                'current'        => (bool)$row['is_current'],
                'is_final'       => (bool)$row['is_final'],
                'storage_uri'    => $row['storage_uri'],
                'content_hash'   => $row['content_hash'],
            ];
        }

        return [
            'document_id' => $documentId,
            'versions'    => $versions,
        ];
    }

    /* ============================================================
     * GET /case-documents/{id}/current-version
     * ============================================================ */
    public function current(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $documentId = $req['id'] ?? null;
        if ($documentId === null) {
            return $this->notFound();
        }

        $stmt = $this->db->prepare("
            SELECT
                dv.version_number,
                dv.is_final,
                dv.storage_uri,
                dv.content_hash
            FROM document_versions dv
            JOIN case_documents cd ON cd.current_version_id = dv.id
            WHERE cd.id = :document_id
              AND cd.tenant_id = :tenant_id
            LIMIT 1
        ");

        $stmt->execute([
            'document_id' => $documentId,
            'tenant_id'   => $ctx->tenantId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'document_id' => $documentId,
            'version'     => $row ? [
                'version_number' => (int)$row['version_number'],
                'is_final'       => (bool)$row['is_final'],
                'storage_uri'    => $row['storage_uri'],
                'content_hash'   => $row['content_hash'],
            ] : null,
        ];
    }

    /* ============================================================
     * Error helpers (consistent with existing controllers)
     * ============================================================ */
    private function unauthorized(): array
    {
        http_response_code(401);
        return ['error' => 'AUTH_REQUIRED'];
    }

    private function notFound(): array
    {
        http_response_code(404);
        return ['error' => 'DOCUMENT_NOT_FOUND'];
    }
}
