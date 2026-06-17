<?php
declare(strict_types=1);

namespace App\Services\Validation\Policy;

/**
 * Contract for state-level artifact structural policies.
 *
 * Each state implements this and registers:
 *  - action types
 *  - schema versions
 *  - artifact requirements
 *  - whitelists
 *
 * No enforcement logic. Structural configuration only.
 */
interface StatePolicyProviderInterface
{
    /**
     * Return full policy map keyed by action_type.
     *
     * [
     *   'az_forcible_detainer' => [ ... ],
     *   'az_other_action'      => [ ... ],
     * ]
     */
    public function getPolicies(): array;
}
