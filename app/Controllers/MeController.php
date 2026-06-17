<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Auth\AuthContext;

final class MeController
{
    public function show(AuthContext $auth): JsonResponse
    {
        /**
         * AuthContext is already validated by AuthMiddleware:
         * - token verified
         * - tenant isolated
         * - scopes enforced
         */

        return JsonResponse::ok([
            'actor' => [
                'actor_id'   => $auth->actorId(),
                'actor_type' => $auth->actorType(),
            ],

            'tenant' => [
                'tenant_id' => $auth->tenantId(),
            ],

            'service_context' => $auth->serviceContext(),

            'scopes' => $auth->scopes(),
            'roles'  => $auth->roles(),

            'claims' => $auth->claims(),
        ]);
    }
}
