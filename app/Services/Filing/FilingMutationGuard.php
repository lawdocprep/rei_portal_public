<?php
declare(strict_types=1);

namespace App\Services\Filing;

use PDO;
use RuntimeException;

final class FilingMutationGuard
{
    public function __construct(private PDO $db) {}

    /**
     * Blocks case-level structural changes if any legal action is prepared_for_filing.
     * Use this for: parties, property, exhibits, demand, court assignment, etc.
     */
    public function assertCaseMutable(string $tenantId, string $caseId): void
    {
        $st = $this->db->prepare("
            SELECT 1
            FROM legal_actions
            WHERE tenant_id = :tenant
              AND case_id = :case_id
              AND COALESCE(action_status, status) = 'prepared_for_filing'
            LIMIT 1
        ");
        $st->execute([
            ':tenant' => $tenantId,
            ':case_id' => $caseId,
        ]);

        if ($st->fetchColumn()) {
            throw new RuntimeException('MUTATION_FROZEN');
        }
    }

    /**
     * Blocks legal-action-scoped changes.
     */
    public function assertLegalActionMutable(string $tenantId, string $legalActionId): void
    {
        $st = $this->db->prepare("
            SELECT 1
            FROM legal_actions
            WHERE tenant_id = :tenant
              AND id = :id
              AND COALESCE(action_status, status) = 'prepared_for_filing'
            LIMIT 1
        ");
        $st->execute([
            ':tenant' => $tenantId,
            ':id' => $legalActionId,
        ]);

        if ($st->fetchColumn()) {
            throw new RuntimeException('MUTATION_FROZEN');
        }
    }

}