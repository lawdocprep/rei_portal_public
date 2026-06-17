<?php
declare(strict_types=1);

namespace App\Auth;

use RuntimeException;

final class TenantIsolation
{
    /**
     * Enforce tenant isolation rules.
     *
     * This is a v1 stub.
     * Real implementation will:
     * - compare token tenant_id to resource tenant_id
     * - apply read/write masking rules
     */
    public function enforce(array $authContext, array $request): void
    {
        // FAIL CLOSED until implemented
        // This prevents accidental cross-tenant access.
        throw new RuntimeException(
            'TenantIsolation not implemented'
        );
    }
}
