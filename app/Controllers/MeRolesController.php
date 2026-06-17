<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;

final class MeRolesController
{
    public function __construct(
        private PDO $db
    ) {}

    /**
     * GET /me/roles
     */
    public function show(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return [
                'status'  => 401,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'AUTH_REQUIRED'],
            ];
        }

        /**
         * Phase 1 rules:
         * - Global roles come from auth context
         * - Case roles deferred to Phase 2+
         */

        $globalRoles = array_values(array_unique($ctx->roles));
        $caseRoles   = []; // Phase 1: not yet resolved

        return [
            'status'  => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => [
                'global_roles'    => $globalRoles,
                'case_roles'      => $caseRoles,
                'effective_roles' => $globalRoles,
            ],
        ];
    }
}
