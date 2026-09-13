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
                'court_id' =>
                    $venue['court_id'] ?? null,

                'court_name' =>
                    $venue['court_name'] ?? null,

                'court_type_code' =>
                    $venue['court_type_code'] ?? null,

                'court_type' =>
                    $venue['court_type'] ?? null,

                'court_city' =>
                    $venue['court_city'] ?? null,

                'court_state' =>
                    $venue['court_location_state']
                    ?? $venue['state_code']
                    ?? null,

                'court_county' =>
                    $venue['county_name'] ?? null,

                'court_precinct' =>
                    $venue['precinct_name'] ?? null,

                'court_address' => [
                    'line1' =>
                        $venue['court_address_line1'] ?? null,

                    'line2' =>
                        $venue['court_address_line2'] ?? null,

                    'city' =>
                        $venue['court_city'] ?? null,

                    'state' =>
                        $venue['court_location_state']
                        ?? $venue['state_code']
                        ?? null,

                    'postal_code' =>
                        $venue['court_postal_code'] ?? null,
                ],

                'clerk_name' =>
                    $venue['court_clerk_name'] ?? null,

                'filing_locations' =>
                    $venue['filing_locations'] ?? [],

                'jurisdiction_unit_id' =>
                    $venue['jurisdiction_unit_id'] ?? null,

                'precinct_id' =>
                    $venue['precinct_id'] ?? null,

                'court_case_number' =>
                    $legalAction['court_case_number'] ?? null,

                'advisory_only' =>
                    false,
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

                'court_address' => [
                    'line1' => null,
                    'line2' => null,

                    'city' =>
                        $legalAction['court_city'] ?? null,

                    'state' =>
                        $legalAction['court_state'] ?? null,

                    'postal_code' => null,
                ],

                'clerk_name' => null,

                'filing_locations' => [],

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
         *
         * Explicit filing attorney assignment is resolved through
         * legal_actions.filing_attorney_actor_id and the active
         * attorney_filing_profiles record.
         *
         * loadFilingAttorney() contains the transitional created_by
         * fallback for legacy actions where no explicit assignment exists.
         */
        $attorney = $this->loadFilingAttorney(
            tenantId: $tenantId,
            legalAction: $legalAction
        );

        /**
         * Signature block
         */
        $signature = [
            'attorney_name' =>
                $attorney['name'] ?? null,

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
            cp.name AS precinct_name,

            primary_location.location_name
                AS primary_location_name,

            primary_location.address_line1
                AS court_address_line1,

            primary_location.address_line2
                AS court_address_line2,

            primary_location.city
                AS court_city,

            primary_location.state
                AS court_location_state,

            primary_location.postal_code
                AS court_postal_code,

            primary_location.clerk_name
                AS court_clerk_name,

            filing_locations.filing_locations

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

        LEFT JOIN LATERAL (
            SELECT
                cfl.location_name,
                cfl.address_line1,
                cfl.address_line2,
                cfl.city,
                cfl.state,
                cfl.postal_code,
                cfl.clerk_name
            FROM court_filing_locations cfl
            WHERE cfl.court_id = c.id
              AND cfl.accepts_filings = true
              AND cfl.effective_from <= CURRENT_DATE
              AND (
                    cfl.effective_to IS NULL
                    OR cfl.effective_to >= CURRENT_DATE
                  )
            ORDER BY
                cfl.is_primary DESC,
                cfl.sort_order ASC,
                cfl.location_name ASC,
                cfl.id ASC
            LIMIT 1
        ) primary_location ON true

        LEFT JOIN LATERAL (
            SELECT jsonb_agg(
                jsonb_build_object(
                    'location_name', cfl.location_name,
                    'address', jsonb_build_object(
                        'line1', cfl.address_line1,
                        'line2', cfl.address_line2,
                        'city', cfl.city,
                        'state', cfl.state,
                        'postal_code', cfl.postal_code
                    ),
                    'is_primary', cfl.is_primary
                )
                ORDER BY
                    cfl.is_primary DESC,
                    cfl.sort_order ASC,
                    cfl.location_name ASC,
                    cfl.id ASC
            ) AS filing_locations
            FROM court_filing_locations cfl
            WHERE cfl.court_id = c.id
              AND cfl.accepts_filings = true
              AND cfl.effective_from <= CURRENT_DATE
              AND (
                    cfl.effective_to IS NULL
                    OR cfl.effective_to >= CURRENT_DATE
                  )
        ) filing_locations ON true

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

    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $row['filing_locations'] =
        isset($row['filing_locations'])
        && is_string($row['filing_locations'])
            ? json_decode(
                $row['filing_locations'],
                true
            )
            : [];

    if (!is_array($row['filing_locations'])) {
        $row['filing_locations'] = [];
    }

    return $row;
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
            SELECT
                la.*
            FROM legal_actions la
            WHERE la.tenant_id = :tenant
            AND la.case_id   = :case
            AND la.id        = :id
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
            ':id'     => $id,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
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

/*
    * ------------------------------------------------------------
    * Filing attorney resolution
     *
    * Primary authority:
    *   legal_actions.filing_attorney_actor_id
    *
    * Transitional fallback:
     *   legal_actions.created_by, but ONLY when that actor has an
     *   active attorney_filing_profiles row.
     *
    * We intentionally do not infer attorney status merely because
     * an actor created the legal action.
    * ------------------------------------------------------------
*/

    private function loadFilingAttorney(
        string $tenantId,
        array $legalAction
    ): array {

        $explicitActorId =
            $legalAction['filing_attorney_actor_id'] ?? null;

        $createdBy =
            $legalAction['created_by'] ?? null;

        $candidateActorIds = [];

        if (
            is_string($explicitActorId)
            && trim($explicitActorId) !== ''
        ) {
            $candidateActorIds[] = trim($explicitActorId);
        }

        if (
            is_string($createdBy)
            && trim($createdBy) !== ''
            && !in_array(
                trim($createdBy),
                $candidateActorIds,
                true
            )
        ) {
            $candidateActorIds[] = trim($createdBy);
        }

        foreach ($candidateActorIds as $actorId) {

            $profile =
                $this->loadActiveAttorneyProfile(
                    tenantId: $tenantId,
                    actorId: $actorId
                );

            if ($profile !== null) {
                return $this->mapAttorneyProfileToIntent(
                    $profile
                );
            }

            /*
            * If an explicit filing attorney was assigned but no active
            * profile exists, do NOT silently substitute created_by.
            *
            * Explicit assignment is authoritative.
            */
            if (
                is_string($explicitActorId)
                && trim($explicitActorId) !== ''
                && $actorId === trim($explicitActorId)
            ) {
                break;
            }
        }

        return [
            'actor_id' => null,
            'name' => null,
            'bar_number' => null,
            'firm_name' => null,

            'address' => [
                'line1' => null,
                'line2' => null,
                'city' => null,
                'state' => null,
                'postal_code' => null,
            ],

            'phone' => null,
            'email' => null,
        ];
    }

    private function loadActiveAttorneyProfile(
        string $tenantId,
        string $actorId
    ): ?array {

        $st = $this->db->prepare("
            SELECT
                afp.actor_id,
                afp.display_name,
                afp.bar_number,
                afp.firm_name,

                afp.address_line1,
                afp.address_line2,
                afp.city,
                afp.state,
                afp.postal_code,

                afp.phone,
                afp.email,

                afp.effective_from,
                afp.effective_to

            FROM attorney_filing_profiles afp

            JOIN actors a
            ON a.id = afp.actor_id
            AND a.tenant_id = :tenant

            WHERE afp.tenant_id = :tenant
            AND afp.actor_id = :actor
            AND afp.effective_from <= CURRENT_TIMESTAMP
            AND (
                    afp.effective_to IS NULL
                    OR afp.effective_to > CURRENT_TIMESTAMP
                )

            ORDER BY
                afp.effective_from DESC,
                afp.id DESC

            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':actor'  => $actorId,
        ]);

        $row = $st->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function mapAttorneyProfileToIntent(
        array $profile
    ): array {

        return [
            'actor_id' =>
                $profile['actor_id'] ?? null,

            'name' =>
                $profile['display_name'] ?? null,

            'bar_number' =>
                $profile['bar_number'] ?? null,

            'firm_name' =>
                $profile['firm_name'] ?? null,

            'address' => [
                'line1' =>
                    $profile['address_line1'] ?? null,

                'line2' =>
                    $profile['address_line2'] ?? null,

                'city' =>
                    $profile['city'] ?? null,

                'state' =>
                    $profile['state'] ?? null,

                'postal_code' =>
                    $profile['postal_code'] ?? null,
            ],

            'phone' =>
                $profile['phone'] ?? null,

            'email' =>
                $profile['email'] ?? null,
        ];
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

    private function loadActiveCaseParties(string $tenantId, string $caseId): array
    {
        $st = $this->db->prepare("
            SELECT
                cp.*,

                a.actor_type,

                cpc.first_name,
                cpc.middle_name,
                cpc.last_name,
                cpc.email,
                cpc.phone,
                cpc.address_line1,
                cpc.address_line2,
                cpc.city,
                cpc.state,
                cpc.postal_code

            FROM case_parties cp

            JOIN actors a
            ON a.id = cp.actor_id
            AND a.tenant_id = cp.tenant_id

            LEFT JOIN case_party_contacts cpc
            ON cpc.tenant_id = cp.tenant_id
            AND cpc.case_id   = cp.case_id
            AND cpc.actor_id  = cp.actor_id

            WHERE cp.tenant_id = :tenant
            AND cp.case_id   = :case
            AND cp.ends_at IS NULL

            ORDER BY
                cp.caption_order ASC,
                cp.starts_at ASC,
                cp.id ASC
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
            'tenant',
            'occupant',
            'seller',
        ];

        $sawOccupant = false;

        foreach ($rows as $r) {

            $role = $r['party_role'];

            $name = $this->partyDisplayName($r);

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

                if (
                    $role === 'occupant'
                    || $role === 'tenant'
                ) {
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

private function intentParty(string $role, string $name, array $row): array
    {
        return [
            'intent_role' => $role,
            'type' => 'real',

            'case_party_id' => $row['id'],
            'actor_id'      => $row['actor_id'],
            'actor_type'    => $row['actor_type'] ?? null,

            'name' => $name,

            'derived_from_roles' => [
                $row['party_role'],
            ],

            'address' => $this->partyAddress($row),

            'contact' => [
                'email' => $row['email'] ?? null,
                'phone' => $row['phone'] ?? null,
            ],

            'fictitious' => null,
        ];
    }

    private function partyAddress(array $row): ?array
    {
        $line1      = trim((string)($row['address_line1'] ?? ''));
        $line2      = trim((string)($row['address_line2'] ?? ''));
        $city       = trim((string)($row['city'] ?? ''));
        $state      = trim((string)($row['state'] ?? ''));
        $postalCode = trim((string)($row['postal_code'] ?? ''));

        if (
            $line1 === '' &&
            $line2 === '' &&
            $city === '' &&
            $state === '' &&
            $postalCode === ''
        ) {
            return null;
        }

        return [
            'line1'       => $line1 !== '' ? $line1 : null,
            'line2'       => $line2 !== '' ? $line2 : null,
            'city'        => $city !== '' ? $city : null,
            'state'       => $state !== '' ? $state : null,
            'postal_code' => $postalCode !== '' ? $postalCode : null,
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

    private function partyDisplayName(array $row): string
    {
        $first  = trim((string)($row['first_name'] ?? ''));
        $middle = trim((string)($row['middle_name'] ?? ''));
        $last   = trim((string)($row['last_name'] ?? ''));

        $parts = array_values(array_filter(
            [$first, $middle, $last],
            static fn(string $value): bool => $value !== ''
        ));

        if ($parts === []) {
            return 'Unknown';
        }

        return implode(' ', $parts);
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