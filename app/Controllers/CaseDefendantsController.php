<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\Guard\CaseMutationGuard;
use PDO;
use Throwable;
use RuntimeException;

final class CaseDefendantsController
{
    public function __construct(private PDO $db) {}

    public function create(array $req, AuthContext $ctx): array
    {
        $tenantId = (string)$ctx->tenantId;
        $caseId   = $req['params']['id'] ?? null;

        if (!is_string($caseId) || !$this->isUuid($caseId)) {
            return $this->json(400, ['error' => 'INVALID_CASE_ID']);
        }

        /* ============================================================
         * 🔒 Phase 17 Freeze Guard
         * ============================================================ */
        try {
            $guard = new CaseMutationGuard($this->db);
            $guard->assertCaseMutable($tenantId, $caseId);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'CASE_FROZEN_PREPARED_FOR_FILING') {
                return $this->json(409, ['error' => 'CASE_FROZEN_PREPARED_FOR_FILING']);
            }
            throw $e;
        }

        $body = $req['body'] ?? null;
        if (!is_array($body)) {
            return $this->json(400, ['error' => 'INVALID_REQUEST']);
        }

        $legalActionId = $body['legal_action_id'] ?? null;
        $mode          = $body['mode'] ?? null;
        $occupantIds   = $body['occupant_ids'] ?? [];

        if (!is_string($legalActionId) || !$this->isUuid($legalActionId)) {
            return $this->json(400, ['error' => 'LEGAL_ACTION_ID_REQUIRED']);
        }

        if (!in_array($mode, ['one', 'all'], true)) {
            return $this->json(400, ['error' => 'INVALID_MODE']);
        }

        if ($mode === 'one' && (!is_array($occupantIds) || count($occupantIds) === 0)) {
            return $this->json(400, ['error' => 'OCCUPANT_IDS_REQUIRED']);
        }

        try {
            $this->db->beginTransaction();

            /* --------------------------------------------------------
             * Load all occupant parties
             * -------------------------------------------------------- */
            $st = $this->db->prepare("
                SELECT id, person_id, organization_id, display_name
                FROM case_parties
                WHERE tenant_id = :tenant
                  AND case_id = :case
                  AND party_role = 'occupant'
            ");
            $st->execute([
                ':tenant' => $tenantId,
                ':case'   => $caseId
            ]);
            $occupants = $st->fetchAll(PDO::FETCH_ASSOC);

            if (count($occupants) === 0) {
                $this->db->rollBack();
                return $this->json(400, ['error' => 'NO_OCCUPANTS_AVAILABLE']);
            }

            /* --------------------------------------------------------
             * Resolve selected occupants
             * -------------------------------------------------------- */
            if ($mode === 'one') {
                $selected = [];
                foreach ($occupants as $o) {
                    if (in_array($o['id'], $occupantIds, true)) {
                        $selected[] = $o;
                    }
                }
                if (count($selected) === 0) {
                    $this->db->rollBack();
                    return $this->json(400, ['error' => 'INVALID_OCCUPANT_ID']);
                }
            } else {
                $selected = $occupants;
            }

            /* --------------------------------------------------------
             * Promote selected occupants to defendants
             * -------------------------------------------------------- */
            foreach ($selected as $occ) {

                $exists = $this->db->prepare("
                    SELECT 1 FROM case_parties
                    WHERE tenant_id = :tenant
                      AND case_id = :case
                      AND party_role = 'defendant'
                      AND (
                          (person_id IS NOT NULL AND person_id = :pid)
                          OR
                          (organization_id IS NOT NULL AND organization_id = :oid)
                      )
                    LIMIT 1
                ");

                $exists->execute([
                    ':tenant' => $tenantId,
                    ':case'   => $caseId,
                    ':pid'    => $occ['person_id'],
                    ':oid'    => $occ['organization_id'],
                ]);

                if (!$exists->fetchColumn()) {
                    $ins = $this->db->prepare("
                        INSERT INTO case_parties (
                            id, tenant_id, case_id,
                            party_role,
                            person_id, organization_id,
                            display_name,
                            created_at
                        ) VALUES (
                            uuid_generate_v4(), :tenant, :case,
                            'defendant',
                            :pid, :oid,
                            :name,
                            now()
                        )
                    ");

                    $ins->execute([
                        ':tenant' => $tenantId,
                        ':case'   => $caseId,
                        ':pid'    => $occ['person_id'],
                        ':oid'    => $occ['organization_id'],
                        ':name'   => $occ['display_name'],
                    ]);
                }
            }

            /* --------------------------------------------------------
             * Ensure fictitious "Occupant" defendant exists
             * -------------------------------------------------------- */
            $this->ensureFictitiousOccupant($tenantId, $caseId);

            $this->db->commit();

            return $this->json(200, [
                'case_id' => $caseId,
                'legal_action_id' => $legalActionId,
                'defendant_count' => count($selected) + 1,
                'fictitious_defendant' => 'Occupant',
            ]);

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[DEFENDANT_DECLARE_ERROR] ' . $e->getMessage());

            return $this->json(500, ['error' => 'INTERNAL_ERROR']);
        }
    }

    /* ============================================================ */

    private function ensureFictitiousOccupant(string $tenantId, string $caseId): void
    {
        $st = $this->db->prepare("
            SELECT 1 FROM case_parties
            WHERE tenant_id = :tenant
              AND case_id = :case
              AND party_role = 'defendant'
              AND is_fictitious = true
            LIMIT 1
        ");
        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId
        ]);

        if ($st->fetchColumn()) return;

        $ins = $this->db->prepare("
            INSERT INTO case_parties (
                id, tenant_id, case_id,
                party_role,
                display_name,
                is_fictitious,
                created_at
            ) VALUES (
                uuid_generate_v4(), :tenant, :case,
                'defendant',
                'Occupant',
                true,
                now()
            )
        ");

        $ins->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId
        ]);
    }

    private function json(int $status, array $body): array
    {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
        ];
    }

    private function isUuid(string $v): bool
    {
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $v
        );
    }
}