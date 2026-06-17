<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;

final class CaseDocumentAuditController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * GET /case-documents/{id}/audit
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

        // Read audit trail (entity-scoped)
        $q = $this->db->prepare(
            "SELECT
                id AS event_id,
                occurred_at,
                event_type,
                actor_id,
                actor_type,
                service_context,
                details
             FROM audit_events
             WHERE tenant_id = :tenant
               AND entity_type = 'case_document'
               AND entity_id = :doc
             ORDER BY occurred_at ASC, id ASC"
        );
        $q->execute([
            ':tenant' => $ctx->tenantId,
            ':doc'    => $caseDocumentId,
        ]);

        return $this->json(200, [
            'case_document_id' => $caseDocumentId,
            'events' => $q->fetchAll(PDO::FETCH_ASSOC),
        ]);
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
}
