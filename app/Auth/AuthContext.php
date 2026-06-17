<?php
declare(strict_types=1);

namespace App\Auth;

final class AuthContext
{
    public function __construct(
        public readonly string $tenantId,        // UUID
        public readonly string $actorId,         // UUID (user / actor)
        public readonly string $actorType,       // person | service
        public readonly string $serviceContext,  // human | judicial | automation
        public readonly array $scopes,           // string[]
        public readonly array $roles,            // string[]
        public readonly ?string $jti,             // JWT ID (string)
        public readonly array $claims             // full claims
    ) {}
    /* ============================================================
    * Role helpers (Timeline / Admin governance)
    * ============================================================ */

    public function hasRole(string $roleName): bool
    {
        return in_array($roleName, $this->roles, true);
    }

    public function hasAnyRole(array $roleNames): bool
    {
        foreach ($roleNames as $r) {
            if (in_array($r, $this->roles, true)) {
                return true;
            }
        }
        return false;
    }

    public function requireTenantAdminOrSystemAdmin(): void
    {
        if (!$this->hasAnyRole(['system_admin', 'tenant_admin'])) {
            throw new \RuntimeException('ADMIN_REQUIRED');
        }
    }

}
