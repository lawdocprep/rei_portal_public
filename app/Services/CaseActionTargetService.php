<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Phase 14.4 – Deliverable 4.2
 *
 * Expands a Legal Action Request (LAR) into per-occupant (per case_party role row)
 * targets in case_occupant_action_targets.
 *
 * IMPORTANT MODEL NOTE
 * - "Occupant" is not a standalone table. It is represented by rows in case_parties
 *   (party_role = tenant/occupant/owner/etc). The target.occupant_id stored in
 *   case_occupant_action_targets refers to case_parties.id.
 */
final class CaseActionTargetService
{
    public function __construct(
        private PDO $db
    ) {}

    /**
     * Replace the materialized target set for (tenant, case, requestId, phaseKey, actionType).
     *
     * Accepted LAR payload shapes:
     *  - larPayload['targets']:
     *      - ["<case_parties.id>", ...]
     *      - [{ occupant_id: "<case_parties.id>", required?: bool }, ...]
     *
     *  - larPayload['target']:
     *      - { mode: "all", roles?: ["tenant","occupant",...], required?: bool }
     *      - { mode: "one", occupant_id: "<case_parties.id>", required?: bool }
     *      - { mode: "role", roles: [...], required?: bool }
     *
     * Returns inserted rows:
     *   [{ id, occupant_id, required }, ...]
     */
    public function replaceTargetsFromLarPayload(
        string $tenantId,
        string $caseId,
        string $requestId,
        string $phaseKey,
        string $actionType,
        array $larPayload
    ): array {
        // 1) Explicit targets[]
        $rows = $this->parseExplicitTargets($larPayload['targets'] ?? null);

        // 2) Single target object
        if (count($rows) === 0) {
            $rows = $this->expandFromTargetObject(
                tenantId: $tenantId,
                caseId: $caseId,
                target: is_array($larPayload['target'] ?? null)
                    ? $larPayload['target']
                    : null
            );
        }

        // 3) Fallback: case parties
        if (count($rows) === 0) {
            $rows = $this->expandFromCaseParties(
                tenantId: $tenantId,
                caseId: $caseId,
                roles: ['tenant', 'occupant', 'owner']
            );
        }

        // 4) Correct guardrail
        if ($this->actionRequiresTargets($actionType) && count($rows) === 0) {
            throw new RuntimeException('TARGETS_REQUIRED');
        }

        // 5) If targets are optional and none resolved → noop
        if (count($rows) === 0) {
            return [];
        }

        // 6) Deduplicate by occupant_id
        $seen  = [];
        $dedup = [];

        foreach ($rows as $r) {
            $k = (string)($r['occupant_id'] ?? '');
            if ($k === '' || isset($seen[$k])) continue;

            $seen[$k] = true;
            $dedup[] = [
                'occupant_id' => $k,
                'required'    => (bool)($r['required'] ?? true),
            ];
        }

        // 7) Replace existing targets
        $del = $this->db->prepare(
            "DELETE FROM case_occupant_action_targets
            WHERE tenant_id = :tenant
            AND case_id = :case_id
            AND action_request_id = :arid
            AND phase_key = :phase
            AND action_type = :atype"
        );

        $del->execute([
            ':tenant'  => $tenantId,
            ':case_id' => $caseId,
            ':arid'    => $requestId,
            ':phase'   => $phaseKey,
            ':atype'   => $actionType,
        ]);

        $ins = $this->db->prepare(
            "INSERT INTO case_occupant_action_targets (
                id, tenant_id, case_id,
                occupant_id, action_request_id,
                phase_key, action_type,
                required, status, created_at
            ) VALUES (
                :id, :tenant, :case_id,
                :occupant_id, :arid,
                :phase, :atype,
                :required, :status, :created_at
            )"
        );

        $now = gmdate('c');
        $inserted = [];

        foreach ($dedup as $r) {
            $id = $this->uuid();
            $ins->execute([
                ':id'          => $id,
                ':tenant'      => $tenantId,
                ':case_id'     => $caseId,
                ':occupant_id' => $r['occupant_id'],
                ':arid'        => $requestId,
                ':phase'       => $phaseKey,
                ':atype'       => $actionType,
                ':required'    => $r['required'] ? 't' : 'f',
                ':status'      => 'pending',
                ':created_at'  => $now,
            ]);

            $inserted[] = [
                'id'          => $id,
                'occupant_id' => $r['occupant_id'],
                'required'    => $r['required'],
            ];
        }

        return $inserted;
    }


    private function parseExplicitTargets(mixed $targets): array
    {
        $rows = [];
        if (!is_array($targets)) return $rows;

        foreach ($targets as $t) {
            if (is_string($t) && $this->isUuid($t)) {
                $rows[] = ['occupant_id' => $t, 'required' => true];
                continue;
            }
            if (is_array($t)) {
                $occ = $t['occupant_id'] ?? null;
                if (!is_string($occ) || !$this->isUuid($occ)) continue;
                $req = array_key_exists('required', $t) ? (bool)$t['required'] : true;
                $rows[] = ['occupant_id' => $occ, 'required' => $req];
            }
        }

        return $rows;
    }

    private function expandFromTargetObject(string $tenantId, string $caseId, ?array $target): array
    {
        if ($target === null) return [];

        $mode = $target['mode'] ?? null;
        if (!is_string($mode) || $mode === '') return [];

        $required = array_key_exists('required', $target) ? (bool)$target['required'] : true;

        if ($mode === 'one') {
            $occ = $target['occupant_id'] ?? null;
            if (!is_string($occ) || !$this->isUuid($occ)) {
                throw new RuntimeException('OCCUPANT_ID_REQUIRED');
            }
            return [['occupant_id' => $occ, 'required' => $required]];
        }

        if ($mode === 'all') {
            $roles = $this->normalizeRoles($target['roles'] ?? null);
            if ($roles === null) {
                $roles = ['tenant', 'occupant', 'owner'];
            }
            $rows = $this->expandFromCaseParties($tenantId, $caseId, $roles);
            foreach ($rows as &$r) {
                $r['required'] = $required;
            }
            return $rows;
        }

        if ($mode === 'role') {
            $roles = $this->normalizeRoles($target['roles'] ?? null);
            if ($roles === null || count($roles) === 0) {
                throw new RuntimeException('TARGET_ROLES_REQUIRED');
            }
            $rows = $this->expandFromCaseParties($tenantId, $caseId, $roles);
            foreach ($rows as &$r) {
                $r['required'] = $required;
            }
            return $rows;
        }

        return [];
    }

    /**
     * Resolve current case_parties IDs by role.
     */
    private function expandFromCaseParties(string $tenantId, string $caseId, array $roles): array
    {
        $roles = array_values(array_unique(array_filter($roles, fn($r) => is_string($r) && $r !== '')));
        if (count($roles) === 0) return [];

        $in = implode(',', array_fill(0, count($roles), '?'));
        $sql = "
            SELECT id
            FROM case_parties
            WHERE tenant_id = ?
              AND case_id = ?
              AND ends_at IS NULL
              AND party_role::text IN ($in)
            ORDER BY starts_at ASC
        ";
        $st = $this->db->prepare($sql);
        $st->execute(array_merge([$tenantId, $caseId], $roles));

        $rows = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $id = (string)($r['id'] ?? '');
            if ($id !== '' && $this->isUuid($id)) {
                $rows[] = ['occupant_id' => $id, 'required' => true];
            }
        }
        return $rows;
    }

    public function loadExistingTargets(
        string $tenantId,
        string $caseId,
        string $requestId
    ): array {
        $st = $this->db->prepare("
            SELECT
                id,
                occupant_id,
                status,
                created_at
            FROM case_occupant_action_targets
            WHERE tenant_id = :tenant
            AND case_id = :case
            AND action_request_id = :arid
            ORDER BY created_at ASC
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
            ':arid'   => $requestId,
        ]);

        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // CaseActionTargetService.php
    // CaseActionTargetService.php (inside the class)
    private function actionRequiresTargets(string $actionType): bool
    {
        // Phase 14.5: actions that do NOT fan out to occupants
        return !in_array($actionType, [
            'az_verify_occupancy',
        ], true);
    }





    private function normalizeRoles(mixed $v): ?array
    {
        if ($v === null) return null;
        if (!is_array($v)) return null;
        $out = [];
        foreach ($v as $r) {
            if (is_string($r) && $r !== '') $out[] = $r;
        }
        $out = array_values(array_unique($out));
        return $out;
    }

    private function isUuid(string $v): bool
    {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $v);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
