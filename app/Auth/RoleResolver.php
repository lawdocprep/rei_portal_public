<?php
namespace App\Auth;

use PDO;

final class RoleResolver
{
    public function __construct(private PDO $db) {}

    /** @return string[] */
    public function resolveCaseRoles(int $tenantId, int $caseId, int $actorId): array
    {
        $sql = "SELECT party_role
                FROM case_parties
                WHERE tenant_id = :t
                  AND case_id = :c
                  AND actor_id = :a
                  AND effective_from <= now()
                  AND (effective_to IS NULL OR effective_to > now())";
        $st = $this->db->prepare($sql);
        $st->execute([':t'=>$tenantId, ':c'=>$caseId, ':a'=>$actorId]);
        return array_values(array_unique(array_map(fn($r) => $r['party_role'], $st->fetchAll(PDO::FETCH_ASSOC))));
    }
}
