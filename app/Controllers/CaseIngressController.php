<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Auth\AuditEmitter;
use PDO;
use RuntimeException;

final class CaseIngressController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * POST /cases/{id}/ingress
     * ============================================================ */
    public function submit(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId = (string)$ctx->tenantId;
        $caseId   = $req['params']['id'] ?? null;
        $body     = $req['body'] ?? null;

        if (!is_string($caseId) || !is_array($body)) {
            return $this->badRequest();
        }

        $submissionType   = $body['submission_type'] ?? null;
        if (!is_string($submissionType) || $submissionType === '') {
            return $this->validationError('submission_type is required');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO case_ingress_submissions
             (tenant_id, case_id, presented_court_id, presented_case_number,
              submission_type, submitter_name, submitter_contact, metadata)
             VALUES
             (:tenant, :case, :court, :case_no, :type, :name, :contact, :meta)
             RETURNING id"
        );

        $stmt->execute([
            ':tenant'  => $tenantId,
            ':case'    => $caseId,
            ':court'   => $body['presented_court_id'] ?? null,
            ':case_no' => $body['presented_case_number'] ?? null,
            ':type'    => $submissionType,
            ':name'    => $body['submitter_name'] ?? null,
            ':contact' => $body['submitter_contact'] ?? null,
            ':meta'    => is_array($body['metadata'] ?? null)
                ? json_encode($body['metadata'])
                : null,
        ]);

        return $this->ok([
            'status' => 'received',
            'submission_id' => $stmt->fetchColumn(),
        ]);
    }

    /* ============================================================
     * POST /cases/{id}/ingress/{submission_id}/accept
     * ============================================================ */
    public function accept(array $req, ?AuthContext $ctx): array
    {
        return $this->transition(
            $req,
            $ctx,
            'accepted'
        );
    }

    /* ============================================================
     * POST /cases/{id}/ingress/{submission_id}/reject
     * ============================================================ */
    public function reject(array $req, ?AuthContext $ctx): array
    {
        return $this->transition(
            $req,
            $ctx,
            'rejected'
        );
    }

    /* ============================================================
     * GET /cases/{id}/ingress
     * ============================================================ */
    public function list(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId = (string)$ctx->tenantId;
        $caseId   = $req['params']['id'] ?? null;

        if (!is_string($caseId)) {
            return $this->badRequest();
        }

        $stmt = $this->db->prepare(
            "SELECT
                id,
                submission_type,
                submitter_name,
                submitter_contact,
                status,
                created_at,
                metadata
             FROM case_ingress_submissions
             WHERE tenant_id = :tenant
               AND case_id = :case
             ORDER BY created_at DESC"
        );

        $stmt->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
        ]);

        return $this->ok([
            'submissions' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    /* ============================================================
     * Internal transition helper
     * ============================================================ */
    private function transition(
        array $req,
        ?AuthContext $ctx,
        string $targetStatus
    ): array {
        if ($ctx === null) return $this->unauthorized();

        $tenantId     = (string)$ctx->tenantId;
        $caseId       = $req['params']['id'] ?? null;
        $submissionId = $req['params']['submission_id'] ?? null;
        $reason       = $req['body']['reason'] ?? null;

        if (!is_string($caseId) || !is_string($submissionId)) {
            return $this->badRequest();
        }

        try {
            $this->db->beginTransaction();

            $lock = $this->db->prepare(
                "SELECT status
                   FROM case_ingress_submissions
                  WHERE id = :id
                    AND tenant_id = :tenant
                    AND case_id = :case
                  FOR UPDATE"
            );

            $lock->execute([
                ':id'     => $submissionId,
                ':tenant' => $tenantId,
                ':case'   => $caseId,
            ]);

            $current = $lock->fetchColumn();
            if ($current === false) {
                $this->db->rollBack();
                return $this->notFound();
            }

            if ($current !== 'received') {
                $this->db->rollBack();
                return $this->validationError(
                    "submission already {$current}"
                );
            }

            $update = $this->db->prepare(
                "UPDATE case_ingress_submissions
                 SET status = :status
                 WHERE id = :id"
            );

            $update->execute([
                ':status' => $targetStatus,
                ':id'     => $submissionId,
            ]);

            /* --------------------------------------------------
             * AUDIT EVENT (Phase 4 requirement)
             * -------------------------------------------------- */
            $audit = new AuditEmitter($this->db);
            $audit->emitResult(
                null,
                $req,
                $ctx,
                "INGRESS_{$targetStatus}",
                [
                    'submission_id' => $submissionId,
                    'case_id'       => $caseId,
                    'status'        => $targetStatus,
                    'reason'        => is_string($reason) ? $reason : null,
                ]
            );

            $this->db->commit();

            return $this->ok([
                'status' => $targetStatus,
            ]);

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return [
                'status'  => 500,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => [
                    'error' => [
                        'code'    => 'INTERNAL',
                        'message' => 'Failed to update ingress submission',
                    ],
                ],
            ];
        }
    }

    /* ============================================================
     * Helpers
     * ============================================================ */
    private function ok(array $body): array
    {
        return [
            'status'  => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
        ];
    }

    private function badRequest(): array
    {
        return [
            'status'  => 400,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['error' => 'INVALID_REQUEST'],
        ];
    }

    private function unauthorized(): array
    {
        return [
            'status'  => 401,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['error' => 'AUTH_REQUIRED'],
        ];
    }

    private function notFound(): array
    {
        return [
            'status'  => 404,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['error' => 'NOT_FOUND'],
        ];
    }

    private function validationError(string $msg): array
    {
        return [
            'status'  => 422,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => [
                'error' => [
                    'code'    => 'STATE_INVALID',
                    'message' => $msg,
                ],
            ],
        ];
    }
}
