<?php
declare(strict_types=1);

namespace App\Policies;

use App\Auth\AuthContext;

final class FilingIntentPolicy
{
    /**
     * Read-only access to filing intent.
     *
     * Rules:
     * - user must be authenticated
     * - user must belong to tenant
     * - no phase or readiness enforcement
     */
    public static function canView(
        AuthContext $ctx,
        string $tenantId
    ): bool {
        return (string)$ctx->tenantId === $tenantId;
    }
}
