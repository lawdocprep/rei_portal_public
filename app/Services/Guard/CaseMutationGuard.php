<?php
declare(strict_types=1);

namespace App\Services\Guard;

use PDO;
use RuntimeException;

final class CaseMutationGuard
{
    public function __construct(private PDO $db) {}

    /**
     * Blocks mutation if ANY frozen filing bundle exists for this case.
     *
     * This enforces the Phase 19 "point of no return".
     */
    public function assertCaseMutable(string $tenantId, string $caseId): void
    {
        // ------------------------------------------------------------
        // Acquire case-level advisory lock
        // ------------------------------------------------------------
        $lock = $this->db->prepare("
            SELECT pg_advisory_xact_lock(
                hashtext(:tenant),
                hashtext(:case_id)
            )
        ");

        $lock->execute([
            ':tenant'  => $tenantId,
            ':case_id' => $caseId
        ]);

        // ------------------------------------------------------------
        // Check freeze state
        // ------------------------------------------------------------
        $st = $this->db->prepare("
            SELECT 1
            FROM filing_bundles
            WHERE tenant_id = :tenant
            AND case_id   = :case_id
            AND frozen    = true
            LIMIT 1
        ");

        $st->execute([
            ':tenant'  => $tenantId,
            ':case_id' => $caseId
        ]);

        if ((bool)$st->fetchColumn()) {
            throw new RuntimeException('CASE_FROZEN');
        }
    }
    /**
     * Resolve case_id from a case_document_id.
     */
    public function resolveCaseIdFromCaseDocumentId(
        string $tenantId,
        string $caseDocumentId
    ): ?string {
        $st = $this->db->prepare("
            SELECT case_id
            FROM case_documents
            WHERE tenant_id = :tenant
              AND id = :id
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':id'     => $caseDocumentId,
        ]);

        $v = $st->fetchColumn();
        return is_string($v) ? $v : null;
    }

    /**
     * Resolve case_id from a legal_action_id.
     * Useful for prepare / commit endpoints.
     */
    public function resolveCaseIdFromLegalActionId(
        string $tenantId,
        string $legalActionId
    ): ?string {
        $st = $this->db->prepare("
            SELECT case_id
            FROM legal_actions
            WHERE tenant_id = :tenant
              AND id = :id
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':id'     => $legalActionId,
        ]);

        $v = $st->fetchColumn();
        return is_string($v) ? $v : null;
    }

    /**
     * Resolve case_id from an exhibit_workspace_id.
     * Safe to call even if table is not yet active.
     */
    public function resolveCaseIdFromExhibitWorkspaceId(
        string $tenantId,
        string $workspaceId
    ): ?string {
        $st = $this->db->prepare("
            SELECT case_id
            FROM exhibit_workspaces
            WHERE tenant_id = :tenant
              AND id = :id
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':id'     => $workspaceId,
        ]);

        $v = $st->fetchColumn();
        return is_string($v) ? $v : null;
    }

    /**
     * Generic safety helper:
     * Accept a resolved caseId and enforce freeze in one call.
     */
    public function assertMutableIfCaseResolved(
        string $tenantId,
        ?string $caseId
    ): void {
        if ($caseId !== null) {
            $this->assertCaseMutable($tenantId, $caseId);
        }
    }
}