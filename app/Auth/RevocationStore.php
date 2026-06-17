<?php
declare(strict_types=1);

namespace App\Auth;

use PDO;

final class RevocationStore
{
    public function __construct(private PDO $db) {}

    public function isAccessTokenRevoked(string $tenantId, string $jti): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1
               FROM auth_access_token_revocations
              WHERE tenant_id = :tenant_id
                AND jti = :jti
              LIMIT 1'
        );

        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':jti'       => $jti,
        ]);

        return (bool)$stmt->fetchColumn();
    }
}
