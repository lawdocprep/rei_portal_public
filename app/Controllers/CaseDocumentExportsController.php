<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;

final class CaseDocumentExportsController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * POST /case-documents/{id}/exports
     * ============================================================ */
    public function create(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $caseDocumentId = $req['params']['id'] ?? null;
        if (!is_string($caseDocumentId)) {
            return $this->badRequest();
        }

        $body = $req['body'] ?? null;
        if (!is_array($body)) {
            return $this->badRequest();
        }

        $purpose = $body['purpose'] ?? null;
        $format  = $body['format']  ?? null;
        $reason  = $body['reason']  ?? null;

        if (!is_string($purpose) || !is_string($format)) {
            return $this->badRequest();
        }

        /*
         * Load finalized case_document + immutable version facts
         * NOTE: content lives in document_versions, not case_documents
         */
        $st = $this->db->prepare(
            "SELECT
                cd.id                        AS case_document_id,
                cd.finalized_at              AS finalized_at,
                dv.id                        AS final_version_id,
                dv.version_number            AS final_version_number,
                dv.content_hash              AS content_hash,
                dv.storage_uri               AS storage_uri
             FROM case_documents cd
             JOIN document_versions dv
               ON dv.id = cd.current_version_id
             WHERE cd.id = :id
               AND cd.tenant_id = :tenant
             LIMIT 1"
        );

        $st->execute([
            ':id'     => $caseDocumentId,
            ':tenant' => $ctx->tenantId,
        ]);

        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            return $this->notFound();
        }

        if ($doc['finalized_at'] === null) {
            return $this->conflict('DOCUMENT_NOT_FINALIZED');
        }

        /*
         * UUID generated in Postgres-compatible format
         */
        $exportId = $this->uuid();

        /*
         * document_exports.delivery is NOT NULL
         * Use an explicit, auditable value
         */
        $delivery = 'external';

        $ins = $this->db->prepare(
            "INSERT INTO document_exports (
                id,
                tenant_id,
                case_document_id,
                issued_at,
                issued_by_actor_id,
                issued_by_actor_type,
                purpose,
                format,
                delivery,
                reason,
                final_version_id,
                final_version_number,
                content_hash,
                storage_uri
            ) VALUES (
                :id,
                :tenant,
                :doc,
                now(),
                :actor,
                :actor_type,
                :purpose,
                :format,
                :delivery,
                :reason,
                :final_version_id,
                :final_version_number,
                :content_hash,
                :storage_uri
            )"
        );

        $ins->execute([
            ':id'                   => $exportId,
            ':tenant'               => $ctx->tenantId,
            ':doc'                  => $caseDocumentId,
            ':actor'                => $ctx->actorId,
            ':actor_type'           => $ctx->actorType,
            ':purpose'              => $purpose,
            ':format'               => $format,
            ':delivery'             => $delivery,
            ':reason'               => $reason,
            ':final_version_id'     => $doc['final_version_id'],
            ':final_version_number' => $doc['final_version_number'],
            ':content_hash'         => $doc['content_hash'],
            ':storage_uri'          => $doc['storage_uri'],
        ]);

        return $this->json(201, [
            'export_id'        => $exportId,
            'case_document_id' => $caseDocumentId,
            'issued_at'        => gmdate('c'),
            'purpose'          => $purpose,
            'format'           => $format,
            'delivery'         => $delivery,
            'evidence' => [
                'final_version_id'     => $doc['final_version_id'],
                'final_version_number' => $doc['final_version_number'],
                'content_hash'         => $doc['content_hash'],
                'storage_uri'          => $doc['storage_uri'],
            ],
        ]);
    }

    /* ============================================================
     * GET /case-documents/{id}/exports
     * ============================================================ */
    public function list(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $caseDocumentId = $req['params']['id'] ?? null;
        if (!is_string($caseDocumentId)) {
            return $this->badRequest();
        }

        // Verify document exists + finalized
        $st = $this->db->prepare(
            "SELECT finalized_at
               FROM case_documents
              WHERE id = :id
                AND tenant_id = :tenant
              LIMIT 1"
        );

        $st->execute([
            ':id'     => $caseDocumentId,
            ':tenant' => $ctx->tenantId,
        ]);

        $finalizedAt = $st->fetchColumn();
        if ($finalizedAt === false) {
            return $this->notFound();
        }
        if ($finalizedAt === null) {
            return $this->conflict('DOCUMENT_NOT_FINALIZED');
        }

        $q = $this->db->prepare(
            "SELECT
                id,
                issued_at,
                issued_by_actor_id,
                issued_by_actor_type,
                purpose,
                format,
                delivery,
                final_version_number,
                content_hash
             FROM document_exports
             WHERE tenant_id = :tenant
               AND case_document_id = :doc
             ORDER BY issued_at ASC"
        );

        $q->execute([
            ':tenant' => $ctx->tenantId,
            ':doc'    => $caseDocumentId,
        ]);

        return $this->json(200, [
            'case_document_id' => $caseDocumentId,
            'exports'          => $q->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    /* ============================================================
     * GET /case-documents/{id}/exports/{export_id}
     * ============================================================ */
    public function get(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $caseDocumentId = $req['params']['id'] ?? null;
        $exportId       = $req['params']['export_id'] ?? null;

        if (!is_string($caseDocumentId) || !is_string($exportId)) {
            return $this->badRequest();
        }

        $q = $this->db->prepare(
            "SELECT
                id,
                case_document_id,
                issued_at,
                issued_by_actor_id,
                issued_by_actor_type,
                purpose,
                format,
                delivery,
                reason,
                final_version_id,
                final_version_number,
                content_hash,
                storage_uri
             FROM document_exports
             WHERE id = :export
               AND case_document_id = :doc
               AND tenant_id = :tenant
             LIMIT 1"
        );

        $q->execute([
            ':export' => $exportId,
            ':doc'    => $caseDocumentId,
            ':tenant' => $ctx->tenantId,
        ]);

        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $this->notFound();
        }

        return $this->json(200, $row);
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function json(int $status, array $body): array
    {
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
        ];
    }

    private function unauthorized(): array
    {
        return $this->json(401, ['error' => 'AUTH_REQUIRED']);
    }

    private function notFound(): array
    {
        return $this->json(404, ['error' => 'NOT_FOUND']);
    }

    private function badRequest(): array
    {
        return $this->json(400, ['error' => 'INVALID_REQUEST']);
    }

    private function conflict(string $code): array
    {
        return $this->json(409, ['error' => $code]);
    }

    /**
     * Generate RFC-4122 compatible UUID (v4)
     * Safe for Postgres uuid columns
     */
    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}
