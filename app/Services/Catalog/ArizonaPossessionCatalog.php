<?php
declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * Canonical Legal Action Catalog (Arizona) — Foreclosure Purchaser → Recover Possession
 *
 * IMPORTANT:
 * - This is a "catalog" (static policy) that the executor uses to:
 *   - enumerate actions
 *   - enforce prerequisites ("gates")
 *   - resolve procedure classes
 *
 * Gates are enforced using case_events (canonical store), so we do not depend on
 * any new tables beyond what you already have.
 */
final class ArizonaPossessionCatalog
{
    public const JURISDICTION = 'AZ';
    public const TRACK = 'az.foreclosure.possession';

    /**
     * Event types used as canonical gate signals (stored in case_events.event_type).
     */
    public const EVT_FORECLOSURE_SALE        = 'foreclosure_sale';
    public const EVT_TRUSTEE_DEED_RECORDED   = 'trustee_deed_recorded';
    public const EVT_DEMAND_DELIVERY         = 'demand_for_possession_delivery';
    public const EVT_OCCUPANCY_VERIFIED      = 'occupancy_verified';

    /**
     * Requested actions (case_events.payload.requested_action).
     */
    public const ACT_VERIFY_OCCUPANCY        = 'verify_occupancy';
    public const ACT_DEMAND_FOR_POSSESSION   = 'demand_for_possession';
    public const ACT_EVICTION                = 'eviction';
    public const ACT_SERVE_LAWSUIT           = 'serve_eviction_lawsuit';
    public const ACT_INITIAL_HEARING         = 'initial_hearing';
    public const ACT_TRIAL                   = 'trial';
    public const ACT_JUDGMENT                = 'judgment_for_possession';
    public const ACT_WRIT_OF_RESTITUTION     = 'az_writ_of_restitution';
    public const ACT_DELIVER_WRIT_TO_SHERIFF = 'deliver_writ_to_sheriff';
    public const ACT_SHERIFF_SERVICE         = 'sheriff_serves_writ';
    public const ACT_POSSESSION_RECOVERED    = 'possession_recovered';

    /**
     * Return a canonical catalog payload the backend and UI can share.
     */
    public static function catalog(): array
    {
        return [
            'jurisdiction' => self::JURISDICTION,
            'track' => self::TRACK,

            // Canonical phase ordering is still handled by the timeline resolver,
            // but the catalog declares a recommended procedural flow.
            'steps' => [
                [
                    'key' => self::ACT_VERIFY_OCCUPANCY,
                    'label' => 'Verify Occupancy',
                    'procedure' => \App\Services\Procedures\VerifyOccupancyProcedure::class,
                    'gates' => [
                        // Must have foreclosure sale info recorded
                        ['type' => 'requires_event', 'event_type' => self::EVT_FORECLOSURE_SALE],
                    ],
                ],
                [
                    'key' => self::ACT_DEMAND_FOR_POSSESSION,
                    'label' => 'Deliver Demand for Possession',
                    'procedure' => \App\Services\Procedures\DemandForPossessionProcedure::class,
                    'gates' => [
                        ['type' => 'requires_event', 'event_type' => self::EVT_FORECLOSURE_SALE],
                    ],
                    // Non-exclusive delivery methods (actor chooses any combination)
                    'options' => [
                        'delivery_methods' => ['personal_delivery', 'posting', 'certified_mail'],
                        'methods_non_exclusive' => true,
                        'minimum_required' => 1,
                    ],
                ],
                [
                    'key' => self::ACT_EVICTION,
                    'label' => 'Commence Eviction Lawsuit',
                    'procedure' => \App\Services\Procedures\EvictionComplaintProcedure::class,
                    'gates' => [
                        // Trustee deed is required before filing
                        ['type' => 'requires_event', 'event_type' => self::EVT_TRUSTEE_DEED_RECORDED],
                        // At least one demand delivery method is required
                        ['type' => 'requires_event', 'event_type' => self::EVT_DEMAND_DELIVERY, 'min_count' => 1],
                    ],
                ],
                [
                    'key' => self::ACT_WRIT_OF_RESTITUTION,
                    'label' => 'Writ of Restitution',
                    'procedure' => \App\Services\Procedures\WritOfRestitutionProcedure::class,
                    'gates' => [
                        ['type' => 'requires_event', 'event_type' => self::ACT_JUDGMENT], // canonical event emitted by judgment procedure
                    ],
                ],
            ],
        ];
    }
}
