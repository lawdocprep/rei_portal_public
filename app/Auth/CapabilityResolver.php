<?php
declare(strict_types=1);

namespace App\Auth;

use PDO;

final class CapabilityResolver
{
    public function __construct(private PDO $db) {}

    /**
     * Check whether an actor has a capability for a case.
     *
     * Resolution order (fail closed):
     *  1. Explicit case_capability_grants (authoritative)
     *  2. Implied capabilities from tenant_client_profiles
     *     (ONLY if actor is related to the case)
     */
    public function hasCapability(
        string $tenantId,
        string $caseId,
        string $actorId,
        string $capability
    ): bool {

        // --------------------------------------------------
        // 1. Explicit case grants (authoritative)
        // --------------------------------------------------
        $sql = <<<SQL
SELECT 1
FROM case_capability_grants
WHERE tenant_id = :tenant
  AND case_id = :case
  AND grantee_actor_id = :actor
  AND capability = :cap
  AND starts_at <= now()
  AND (ends_at IS NULL OR ends_at > now())
  AND revoked_at IS NULL
LIMIT 1
SQL;

        $st = $this->db->prepare($sql);
        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
            ':actor'  => $actorId,
            ':cap'    => $capability,
        ]);

        if ($st->fetchColumn()) {
            return true;
        }

        // --------------------------------------------------
        // 2. Actor must be related to the case
        // --------------------------------------------------
        if (!$this->isActorRelatedToCase($tenantId, $caseId, $actorId)) {
            return false;
        }

        // --------------------------------------------------
        // 3. Tenant client profile implied grants
        // --------------------------------------------------
        $profile = $this->resolveClientProfile($tenantId, $actorId);
        if ($profile === null) {
            return false;
        }

        return $this->impliesCapability($profile, $capability);
    }

    /**
     * Resolve a document → case and then evaluate capability.
     *
     * This method exists specifically because AuthMiddleware
     * enforces document-scoped capability checks.
     *
     * FAIL CLOSED:
     * - document must exist
     * - tenant must match
     * - case_id must resolve
     */
    public function hasDocumentCapability(
        string $tenantId,
        string $documentId,
        string $actorId,
        string $capability
    ): bool {
        $sql = <<<SQL
        SELECT case_id
        FROM case_documents
        WHERE tenant_id = :tenant
        AND id = :document
        LIMIT 1
        SQL;

        $st = $this->db->prepare($sql);
        $st->execute([
            ':tenant'   => $tenantId,
            ':document' => $documentId,
        ]);

        $caseId = $st->fetchColumn();
        if (!is_string($caseId) || $caseId === '') {
            return false; // fail closed
        }

        return $this->hasCapability(
            $tenantId,
            $caseId,
            $actorId,
            $capability
        );
    }

    /**
     * Verify the actor has an active relationship to the case.
     */
    private function isActorRelatedToCase(
        string $tenantId,
        string $caseId,
        string $actorId
    ): bool {
        $sql = <<<SQL
        SELECT 1
        FROM case_parties
        WHERE tenant_id = :tenant
        AND case_id = :case
        AND actor_id = :actor
        AND starts_at <= now()
        AND (ends_at IS NULL OR ends_at > now())
        LIMIT 1
        SQL;

        $st = $this->db->prepare($sql);
        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
            ':actor'  => $actorId,
        ]);

        return (bool) $st->fetchColumn();
    }

    /**
     * Resolve active tenant client profile.
     */
    private function resolveClientProfile(
        string $tenantId,
        string $actorId
    ): ?string {
        $sql = <<<SQL
        SELECT client_type
        FROM tenant_client_profiles
        WHERE tenant_id = :tenant
        AND client_actor_id = :actor
        AND starts_at <= now()
        AND (ends_at IS NULL OR ends_at > now())
        ORDER BY starts_at DESC
        LIMIT 1
        SQL;

        $st = $this->db->prepare($sql);
        $st->execute([
            ':tenant' => $tenantId,
            ':actor'  => $actorId,
        ]);

        $type = $st->fetchColumn();
        return is_string($type) ? $type : null;
    }

    /**
     * Capability implication rules.
     *
     * Explicit, predictable, white-label safe.
     */
    private function impliesCapability(
        string $clientType,
        string $capability
    ): bool {
        return match ($clientType) {

            'one_off' => false,

            'self_represented' =>
                in_array($capability, [
                    'case:write',
                    'case:occupants:write',
                ], true),

            'represented' =>
                $capability === 'case:occupants:write',

            default => false,
        };
    }

    public function assertCanExecuteLegalActionRequest(
        string $tenantId,
        array $actorRoles,
        string $executionMode // 'attorney' | 'self'
    ): void {
        if ($executionMode === 'attorney') {
            if (!array_intersect($actorRoles, ['attorney', 'legal_admin', 'paralegal'])) {
                throw new RuntimeException('FORBIDDEN: attorney execution required');
            }
            return;
        }

        if ($executionMode === 'self') {
            if (!in_array('pro_se', $actorRoles, true)) {
                throw new RuntimeException('FORBIDDEN: pro se execution required');
            }
            return;
        }

        throw new RuntimeException('FORBIDDEN');
    }

}
