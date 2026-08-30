<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use App\Policies\ActionTypeFilingPolicy;
use App\Services\Caption\CaptionFormatterFactory;
use App\Services\Validation\IntentHashService;

final class FilingIntentAssembler
{
    private IntentHashService $intentHashService;

    public function __construct(
        private PDO $db,
        ?IntentHashService $intentHashService = null
    ) {
        $this->intentHashService =
            $intentHashService ?? new IntentHashService();
    }

    public function assemble(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        array $opts = []
    ): array {

        $maxWarnings = is_int($opts['max_warnings'] ?? null)
            ? (int)$opts['max_warnings']
            : 50;

        /* ============================================================
         * 1) Load legal action
         * ============================================================ */
        $legalAction = $this->loadLegalAction(
            $tenantId,
            $caseId,
            $legalActionId
        );

        if ($legalAction === null) {
            $exists = $this->legalActionExists(
                $tenantId,
                $legalActionId
            );

            return [
                'error' => $exists
                    ? 'LEGAL_ACTION_CASE_MISMATCH'
                    : 'LEGAL_ACTION_NOT_FOUND'
            ];
        }

        $actionType = (string)$legalAction['action_type'];

        /* ============================================================
         * 2) Resolve policy + options
         * ============================================================ */
        $policy = ActionTypeFilingPolicy::forActionType($actionType);

        $includeFictitious =
            array_key_exists('include_fictitious_occupant', $opts)
                ? (bool)$opts['include_fictitious_occupant']
                : (bool)$policy['include_fictitious_occupant'];

        $captionStyle =
            is_string($opts['caption_style'] ?? null)
                ? (string)$opts['caption_style']
                : ActionTypeFilingPolicy::defaultCaptionStyle($actionType);

        /* ============================================================
         * 3) Load context
         * ============================================================ */
        $propertyRow = $this->loadPropertyAddress(
            $tenantId,
            $caseId
        );

        $property = $this->normalizeProperty($propertyRow);

        $parties = $this->loadActiveCaseParties(
            $tenantId,
            $caseId
        );

        $deadlines = $this->loadDeadlineContext(
            $tenantId,
            $caseId
        );

        /* ============================================================
         * 4) Map parties → filing intent
         * ============================================================ */
        [
            $plaintiffs,
            $defendants,
            $mappingExplain,
            $warnings
        ] = $this->mapPartiesToIntent(
            $parties,
            $propertyRow,
            $includeFictitious
        );

        foreach ($deadlines['blocking_deadlines'] as $d) {
            $warnings[] =
                "Blocking deadline pending: {$d['obligation_key']}";
        }

        if (count($warnings) > $maxWarnings) {
            $warnings = array_slice(
                $warnings,
                0,
                $maxWarnings
            );
        }

        /* ============================================================
         * 5) Deterministic sort
         * ============================================================ */
        $plaintiffs = $this->sortIntentParties($plaintiffs);
        $defendants = $this->sortIntentParties($defendants);

        /* ============================================================
         * 6) Caption via plug-in
         * ============================================================ */
        $formatter = CaptionFormatterFactory::make($captionStyle);

        $caption = $formatter->format(
            $plaintiffs,
            $defendants
        );

        /* ============================================================
         * 7) Resolve authoritative venue / court display
         *
         * Venue authority:
         *
         * case_venue_assignments.court_id
         *          ↓
         * courts.id
         *          ↓
         * courts.court_type_code
         *
         * legal_actions.court_* is retained only as a fallback for
         * legacy cases where no venue assignment exists.
         * ============================================================ */

        $venue = $this->loadResolvedVenue(
            $tenantId,
            $caseId
        );

        if ($venue !== null) {

            $courtDisplay = [
                'court_id' => $venue['court_id'] ?? null,

                'court_name' =>
                    $venue['court_name'] ?? null,

                /*
                 * Canonical machine-readable classification.
                 *
                 * Examples:
                 *   GENERAL_TRIAL
                 *   LIMITED_TRIAL
                 *
                 * Downstream procedural logic should use this field.
                 */
                'court_type_code' =>
                    $venue['court_type_code'] ?? null,

                /*
                 * Human/display court type.
                 *
                 * Downstream document headings may use this value.
                 */
                'court_type' =>
                    $venue['court_type'] ?? null,

                'court_city' => null,

                'court_state' =>
                    $venue['state_code'] ?? null,

                'court_county' =>
                    $venue['county_name'] ?? null,

                'court_precinct' =>
                    $venue['precinct_name'] ?? null,

                'jurisdiction_unit_id' =>
                    $venue['jurisdiction_unit_id'] ?? null,

                'precinct_id' =>
                    $venue['precinct_id'] ?? null,

                'court_case_number' =>
                    $legalAction['court_case_number'] ?? null,

                /*
                 * Venue-resolved data is authoritative.
                 */
                'advisory_only' => false,
            ];

        } else {

            /*
             * Legacy fallback.
             *
             * This path should normally not be used once
             * ResolveVenueProcedure has successfully completed.
             */
            $courtDisplay = [
                'court_id' => null,

                'court_name' =>
                    $legalAction['court_name'] ?? null,

                'court_type_code' => null,

                'court_type' =>
                    $legalAction['court_type'] ?? null,

                'court_city' =>
                    $legalAction['court_city'] ?? null,

                'court_state' =>
                    $legalAction['court_state'] ?? null,

                'court_county' =>
                    $legalAction['court_county'] ?? null,

                'court_precinct' => null,

                'jurisdiction_unit_id' => null,
                'precinct_id' => null,

                'court_case_number' =>
                    $legalAction['court_case_number'] ?? null,

                'advisory_only' => true,
            ];

            $warnings[] =
                'No resolved venue assignment found; '
                . 'using legal action court snapshot fallback.';
        }

        /* ============================================================
         * 8) Assemble canonical intent snapshot
         * ============================================================ */

        /**
         * Demand context
         */
        $demand = $this->loadDemandContext(
            $tenantId,
            $caseId
        );

        /**
         * Attorney context
         */
        $attorney = [
            'name' =>
                $legalAction['attorney_name'] ?? null,
        ];

        /**
         * Signature block
         */
        $signature = [
            'attorney_name' =>
                $attorney['name'],

            'signed_date' =>
                date('Y-m-d'),
        ];

        /**
         * Exhibits
         */
        $exhibits = [];

        $intent = [
            'tenant_id'       => $tenantId,
            'case_id'         => $caseId,
            'legal_action_id' => $legalActionId,
            'action_type'     => $actionType,

            'plaintiffs' => $plaintiffs,
            'defendants' => $defendants,
            'caption'    => $caption,

            'property'  => $property,
            'demand'    => $demand,
            'attorney'  => $attorney,
            'signature' => $signature,
            'exhibits'  => $exhibits,

            'court_display' =>
                $courtDisplay,

            'procedural_context' =>
                $deadlines,
        ];

        /* ============================================================
         * 9) Compute canonical intent hash
         * ============================================================ */
        $intentHash =
            $this->intentHashService->compute($intent);

        if (
            is_string($intentHash)
            && $intentHash !== ''
        ) {
            $intent['intent_hash'] = $intentHash;
        }

        /* ============================================================
         * 10) Add explain + warnings
         * ============================================================ */
        if (count($warnings) > $maxWarnings) {
            $warnings = array_slice(
                $warnings,
                0,
                $maxWarnings
            );
        }

        $intent['warnings'] = $warnings;

        $intent['explain'] = [
            'party_counts' => [
                'stored_parties_total' =>
                    count($parties),

                'plaintiffs' =>
                    count($plaintiffs),

                'defendants' =>
                    count($defendants),
            ],

            'role_mapping' =>
                $mappingExplain,

            'policy' =>
                $policy,

            'property_present' =>
                $property !== null,

            'venue' => [
                'resolved' =>
                    $venue !== null,

                'court_id' =>
                    $courtDisplay['court_id'] ?? null,

                'court_type_code' =>
                    $courtDisplay['court_type_code'] ?? null,

                'advisory_only' =>
                    $courtDisplay['advisory_only'] ?? true,
            ],
        ];

        return $intent;
    }

    /* ============================================================
     * Canonical property normalization
     * ============================================================ */

    private function normalizeProperty(?array $row): ?array
    {
        if (!$row) {
            return null;
        }

        return [
            'property_id' =>
                $row['id'] ?? null,

            'address' => [
                'line1' =>
                    $row['address_line1'] ?? null,

                'line2' =>
                    $row['address_line2'] ?? null,

                'city' =>
                    $row['city'] ?? null,

                'state' =>
                    $row['state'] ?? null,

                'postal_code' =>
                    $row['zip'] ?? null,
            ],

            'legal_description' =>
                $row['legal_description'] ?? null,
        ];
    }

    /* ============================================================
     * NEW: Authoritative resolved venue loader
     *
     * case_venue_assignments does not contain tenant_id, so tenant
     * isolation is enforced by joining cases and requiring the
     * matching cases.tenant_id.
     * ============================================================ */

    private function loadResolvedVenue(
        string $tenantId,
        string $caseId
    ): ?array {

        $st = $this->db->prepare("
            SELECT
                cva.court_id,
                cva.state_code,
                cva.precinct_id,
                cva.jurisdiction_unit_id,
                cva.stage,
                cva.assigned_at,

                c.name AS court_name,
                c.court_type,
                c.court_type_code,

                co.name AS county_name,
                cp.name AS precinct_name

            FROM case_venue_assignments cva

            JOIN cases cs
              ON cs.id = cva.case_id
             AND cs.tenant_id = :tenant

            JOIN courts c
              ON c.id = cva.court_id

            LEFT JOIN counties co
              ON co.id = c.county_id

            LEFT JOIN court_precincts cp
              ON cp.id = cva.precinct_id

            WHERE cva.case_id = :case

            ORDER BY
                cva.assigned_at DESC,
                cva.id DESC

            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
        ]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /* ============================================================
     * Legal action loaders
     * ============================================================ */

    private function loadLegalAction(
        string $tenantId,
        string $caseId,
        string $id
    ): ?array {

        $st = $this->db->prepare("
            SELECT *
            FROM legal_actions
            WHERE tenant_id = :tenant
              AND case_id   = :case
              AND id        = :id
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
            ':id'     => $id,
        ]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function legalActionExists(
        string $tenantId,
        string $id
    ): bool {

        $st = $this->db->prepare("
            SELECT 1
            FROM legal_actions
            WHERE tenant_id = :tenant
              AND id = :id
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':id'     => $id,
        ]);

        return (bool)$st->fetchColumn();
    }

    /* ============================================================
     * Property
     * ============================================================ */

    private function loadPropertyAddress(
        string $tenantId,
        string $caseId
    ): ?array {

        $st = $this->db->prepare("
            SELECT p.*
            FROM case_properties cp
            JOIN properties p
              ON p.id = cp.property_id
            WHERE cp.tenant_id = :tenant
              AND cp.case_id   = :case
              AND cp.ends_at IS NULL
            ORDER BY
                cp.starts_at ASC,
                cp.id ASC
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
        ]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /* ============================================================
     * Demand context
     * ============================================================ */

    private function loadDemandContext(
        string $tenantId,
        string $caseId
    ): array {

        $st = $this->db->prepare("
            SELECT
                delivery_method,
                delivered_at,
                delivery_status
            FROM demand_delivery_events
            WHERE tenant_id = :tenant
              AND case_id   = :case
              AND delivery_status IN (
                    'delivered',
                    'completed',
                    'deemed_received'
              )
            ORDER BY
                delivered_at DESC NULLS LAST,
                created_at DESC
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'method' => null,
                'date' => null,
                'statutory_basis' =>
                    'A.R.S. § 12-1173.01(A)(2)',
            ];
        }

        return [
            'method' =>
                $row['delivery_method'] ?? null,

            'date' =>
                $row['delivered_at'] ?? null,

            'statutory_basis' =>
                'A.R.S. § 12-1173.01(A)(2)',
        ];
    }

    /* ============================================================
     * Case parties
     * ============================================================ */

    private function loadActiveCaseParties(
        string $tenantId,
        string $caseId
    ): array {

        $st = $this->db->prepare("
            SELECT
                cp.*,
                to_jsonb(a) AS actor
            FROM case_parties cp
            JOIN actors a
              ON a.id = cp.actor_id
            WHERE cp.tenant_id = :tenant
              AND cp.case_id   = :case
              AND cp.ends_at IS NULL
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
        ]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /* ============================================================
     * Procedural deadlines
     * ============================================================ */

    private function loadDeadlineContext(
        string $tenantId,
        string $caseId
    ): array {

        $st = $this->db->prepare("
            SELECT
                obligation_key,
                status,
                required
            FROM case_procedural_deadlines
            WHERE tenant_id = :tenant
              AND case_id   = :case
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
        ]);

        $blocking = [];

        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            if (
                $r['required']
                && $r['status'] === 'pending'
            ) {
                $blocking[] = $r;
            }
        }

        return [
            'blocking_deadlines' => $blocking
        ];
    }

    /* ============================================================
     * Party mapping + utilities
     * ============================================================ */

    private function mapPartiesToIntent(
        array $rows,
        ?array $property,
        bool $includeFictitious
    ): array {

        $plaintiffs = [];
        $defendants = [];
        $warnings   = [];

        $plaintiffRoles = [
            'owner',
            'buyer',
            'client',
            'lienholder',
            'hoa',
            'taxing_authority'
        ];

        $defendantRoles = [
            'occupant',
            'renter',
            'lessee',
            'seller'
        ];

        $sawOccupant = false;

        foreach ($rows as $r) {

            $role = $r['party_role'];

            $actor =
                json_decode(
                    $r['actor'],
                    true
                ) ?: [];

            $name =
                $actor['display_name']
                ?? $actor['name']
                ?? 'Unknown';

            if (
                in_array(
                    $role,
                    $plaintiffRoles,
                    true
                )
            ) {

                $plaintiffs[] =
                    $this->intentParty(
                        'plaintiff',
                        $name,
                        $r
                    );

            } elseif (
                in_array(
                    $role,
                    $defendantRoles,
                    true
                )
            ) {

                if ($role === 'occupant') {
                    $sawOccupant = true;
                }

                $defendants[] =
                    $this->intentParty(
                        'defendant',
                        $name,
                        $r
                    );

            } else {

                $warnings[] =
                    "Unmapped party_role: {$role}";
            }
        }

        if (
            $includeFictitious
            && !$sawOccupant
            && $property
        ) {

            $defendants[] = [
                'intent_role' =>
                    'defendant',

                'type' =>
                    'fictitious',

                'name' =>
                    'Occupant',

                'address' =>
                    $this->addressFromProperty($property),

                'fictitious' => [
                    'kind' =>
                        'occupant',

                    'reason' =>
                        'unknown occupants',
                ],
            ];
        }

        return [
            $plaintiffs,
            $defendants,
            [
                'plaintiff_roles' =>
                    $plaintiffRoles,

                'defendant_roles' =>
                    $defendantRoles,
            ],
            $warnings,
        ];
    }

    private function intentParty(
        string $role,
        string $name,
        array $row
    ): array {

        return [
            'intent_role' =>
                $role,

            'type' =>
                'real',

            'case_party_id' =>
                $row['id'],

            'actor_id' =>
                $row['actor_id'],

            'name' =>
                $name,

            'derived_from_roles' => [
                $row['party_role']
            ],

            'address' =>
                null,

            'fictitious' =>
                null,
        ];
    }

    private function addressFromProperty(
        array $p
    ): array {

        return [
            'line1' =>
                $p['address_line1'],

            'line2' =>
                $p['address_line2'],

            'city' =>
                $p['city'],

            'state' =>
                $p['state'],

            'postal_code' =>
                $p['zip'],
        ];
    }

    private function sortIntentParties(
        array $items
    ): array {

        usort(
            $items,
            fn($a, $b) =>
                strcmp(
                    $a['name'],
                    $b['name']
                )
        );

        return $items;
    }
}

/**
 * Backwards-compatibility alias.
 *
 * Allows existing Phase 16.5 code to continue using:
 *
 *   FilingIntentAssemblerService
 */
class_alias(
    FilingIntentAssembler::class,
    __NAMESPACE__ . '\\FilingIntentAssemblerService'
);