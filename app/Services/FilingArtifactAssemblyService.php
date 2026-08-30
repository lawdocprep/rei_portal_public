<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\Validation\IntentHashService;
use App\Services\Exhibits\ExhibitLinkageService;

/**
 * Phase 16.6 — Filing Artifact Assembly (Hardened)
 *
 * Deterministic, preview-only artifact envelopes derived from filing intent.
 *
 * Invariants:
 *  - No persistence
 *  - No enforcement
 *  - No court simulation
 *  - No phase mutation
 */
final class FilingArtifactAssemblyService
{
    private \App\Services\Exhibits\ExhibitLinkageService $exhibitLinkageService;
    private \App\Services\Validation\IntentHashService $intentHashService;

    private const ARTIFACT_SCHEMA_VERSION = 2;

    /**
     * Allowed lifecycle stages (frozen vocabulary)
     */
    private const LIFECYCLE_STAGES = [
        'pre_filing',
        'pre_clerk_stamp',
        'post_clerk_stamp',
        'post_notice_of_hearing',
        'service_packet_ready',
    ];

    /**
     * Centralized artifact policy table.
     * Deterministic ordering is enforced here.
     */
    private const ACTION_TYPE_ARTIFACT_POLICY = [
        'az_forcible_detainer' => [
            [
                'type' => 'complaint',
                'lifecycle_stage' => 'pre_filing',
            ],
            [
                'type' => 'summons',
                'lifecycle_stage' => 'pre_clerk_stamp',
            ],
            [
                'type' => 'certificate_compulsory_arbitration',
                'lifecycle_stage' => 'pre_filing',
            ],
        ],
    ];

    public function __construct(
        private \PDO $db,
        ?\App\Services\Validation\IntentHashService $intentHashService = null,
        ?\App\Services\Exhibits\ExhibitLinkageService $exhibitLinkageService = null
    ) {
        $this->intentHashService =
            $intentHashService
            ?? new \App\Services\Validation\IntentHashService();

        $this->exhibitLinkageService =
            $exhibitLinkageService
            ?? new \App\Services\Exhibits\ExhibitLinkageService($this->db);
    }

    public function assemble(array $intent): array
    {
        $actionType = $intent['action_type'] ?? null;

        if (!is_string($actionType) || $actionType === '') {
            return [
                'artifacts' => [],
                'warnings'  => ['INVALID_ACTION_TYPE'],
            ];
        }

        if (!isset(self::ACTION_TYPE_ARTIFACT_POLICY[$actionType])) {
            return [
                'artifacts' => [],
                'warnings'  => ['NO_ARTIFACT_POLICY_DEFINED'],
            ];
        }

        $policy = self::ACTION_TYPE_ARTIFACT_POLICY[$actionType];

        if (!is_array($policy) || empty($policy)) {
            return [
                'artifacts' => [],
                'warnings'  => ['EMPTY_ARTIFACT_POLICY'],
            ];
        }

        /* ------------------------------------------------------------
         * Canonical intent hash (single authority)
         * ------------------------------------------------------------ */

        $intentHash = $this->intentHashService->compute($intent);

        if (!is_string($intentHash) || $intentHash === '') {
            return [
                'artifacts' => [],
                'warnings'  => ['INTENT_HASH_COMPUTE_FAILED'],
            ];
        }

        $artifacts = [];

        /* ------------------------------------------------------------
         * Canonical court classification
         *
         * Filing/procedural decisions must use courts.court_type_code,
         * propagated through FilingIntentAssembler.
         *
         * Expected values include:
         *   GENERAL_TRIAL
         *   LIMITED_TRIAL
         * ------------------------------------------------------------ */

        $courtTypeCode =
            $intent['court_display']['court_type_code'] ?? null;

        if (!is_string($courtTypeCode) || $courtTypeCode === '') {
            $courtTypeCode = null;
        }

        foreach ($policy as $definition) {

            if (
                !is_array($definition) ||
                empty($definition['type']) ||
                empty($definition['lifecycle_stage'])
            ) {
                return [
                    'artifacts' => [],
                    'warnings'  => ['INVALID_ARTIFACT_POLICY_DEFINITION'],
                ];
            }

            /*
             * ------------------------------------------------------------
             * Superior Court only: compulsory arbitration certificate
             *
             * GENERAL_TRIAL is the canonical court classification for
             * Superior Court.
             *
             * Do not use human-readable court_type strings for this
             * procedural decision.
             * ------------------------------------------------------------
             */

            if (
                ($definition['type'] ?? null)
                    === 'certificate_compulsory_arbitration'
                && $courtTypeCode !== 'GENERAL_TRIAL'
            ) {
                continue;
            }

            $artifacts[] = $this->artifactEnvelope(
                type: (string)$definition['type'],
                intent: $intent,
                intentHash: $intentHash,
                lifecycleStage: (string)$definition['lifecycle_stage']
            );
        }

        return [
            'artifacts' => $artifacts,
            'warnings'  => [],
        ];
    }

    private function determineArbitrationStatus(
        string $actionType,
        string $county,
        ?float $amount
    ): string {

        /* ------------------------------------------------------------
         * Trustee sale forcible detainer → never arbitrable
         * ------------------------------------------------------------ */

        if ($actionType === 'az_trustee_sale_fd') {
            return 'exempt';
        }

        $limit = ArizonaArbitrationLimitService::getLimit($county);

        if ($limit === null) {
            return 'not_eligible';
        }

        if ($amount !== null && $amount <= $limit) {
            return 'eligible';
        }

        return 'not_eligible';
    }

    /* ============================================================
     * Artifact Envelope Builder
     * ============================================================ */

    private function artifactEnvelope(
        string $type,
        array $intent,
        string $intentHash,
        string $lifecycleStage
    ): array {

        if (!in_array($lifecycleStage, self::LIFECYCLE_STAGES, true)) {
            throw new \RuntimeException('INVALID_LIFECYCLE_STAGE');
        }

        // Default: no structured content
        $content = [];

        // Phase 16.6 — Structured artifact modeling (composition only)
        if ($type === 'complaint') {

            // Phase 18 — Resolve deterministic exhibits
            $resolvedExhibits = $this->exhibitLinkageService
                ->materialize(
                    tenantId: (string)$intent['tenant_id'],
                    caseId: (string)$intent['case_id'],
                    legalActionId: (string)$intent['legal_action_id'],
                    actionType: (string)$intent['action_type'],
                    artifactType: 'complaint',
                    intentHash: $intentHash
                );

            $content = $this->buildStructuredComplaint(
                intent: $intent,
                resolvedExhibits: $resolvedExhibits
            );

            error_log("=== COMPLAINT CONTENT DEBUG ===");
            error_log(json_encode($content, JSON_PRETTY_PRINT));
            error_log("=== END COMPLAINT CONTENT DEBUG ===");

        } elseif ($type === 'summons') {

            $content = $this->buildStructuredSummons($intent);

        } elseif ($type === 'certificate_compulsory_arbitration') {

            $content = $this->buildStructuredCertificate($intent);
        }

        return [
            'artifact_type' => $type,
            'format' => 'structured',
            'preview_only' => true,
            'artifact_schema_version' => self::ARTIFACT_SCHEMA_VERSION,

            'metadata' => [
                'action_type' => $intent['action_type'] ?? null,
                'legal_action_id' => $intent['legal_action_id'] ?? null,
                'generated_from_intent' => true,
                'intent_hash' => $intentHash,
                'origin' => 'attorney_prepared',
                'lifecycle_stage' => $lifecycleStage,
            ],

            'content' => $content,
        ];
    }

    /* ============================================================
     * Display Normalization Helpers (Phase 16.6 Safe)
     * ============================================================ */

    private function normalizeState(?string $state): ?string
    {
        if ($state === null) {
            return null;
        }

        return match (strtoupper($state)) {
            'AZ' => 'Arizona',
            default => $state,
        };
    }

    private function normalizeCourtType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        return match (strtolower($type)) {
            'superior' => 'Superior',
            'justice' => 'Justice',
            default => ucfirst($type),
        };
    }

    private function normalizeCounty(?string $county): ?string
    {
        if ($county === null) {
            return null;
        }

        $county = trim($county);

        // Remove trailing "county" if present (case-insensitive)
        $county = preg_replace('/\s+county$/i', '', $county);

        // Title-case the county name
        return ucwords(strtolower($county));
    }

    private function normalizePrecinct(?string $precinct): ?string
    {
        if ($precinct === null) {
            return null;
        }

        $precinct = trim($precinct);

        // Remove trailing "precinct" if present
        $precinct = preg_replace('/\s+precinct$/i', '', $precinct);

        // Title-case
        $precinct = ucwords(strtolower($precinct));

        return $precinct . ' Precinct';
    }

    private function buildCourtHeading(array $court): array
    {
        /*
         * Court structure decisions use the canonical machine-readable
         * classification, not the human-readable court_type value.
         */
        $courtTypeCode = $court['court_type_code'] ?? null;

        return [
            'state' => $this->normalizeState(
                $court['court_state'] ?? null
            ),

            'county' => $this->normalizeCounty(
                $court['court_county'] ?? null
            ),

            /*
             * Display value only.
             */
            'court_type' => $this->normalizeCourtType(
                $court['court_type'] ?? null
            ),

            /*
             * Arizona Justice Courts are LIMITED_TRIAL courts.
             * Superior Court (GENERAL_TRIAL) does not use a precinct
             * heading.
             */
            'precinct' => $courtTypeCode === 'LIMITED_TRIAL'
                ? $this->normalizePrecinct(
                    $court['court_precinct'] ?? null
                )
                : null,
        ];
    }

    /* ============================================================
     * Shared Defendant Augmentation (Litigation Posture)
     * ============================================================ */

    /**
     * Complaint posture rule:
     * Always include a fictitious "John Doe Occupants 1–10" defendant,
     * even when real occupants are known.
     *
     * This must be applied consistently across all artifacts that
     * participate in bundle caption invariants.
     */
    private function defendantsWithFictitious(array $intent): array
    {
        $realDefendants = $intent['defendants'] ?? [];

        if (!is_array($realDefendants)) {
            $realDefendants = [];
        }

        $fictitious = $this->buildFictitiousOccupant($intent);

        $hasFictitious = false;

        foreach ($realDefendants as $d) {
            if (
                is_array($d)
                && (($d['type'] ?? null) === 'fictitious')
            ) {
                $hasFictitious = true;
                break;
            }
        }

        $defendants = $realDefendants;

        if (!$hasFictitious && $fictitious !== null) {
            $defendants[] = $fictitious;
        }

        // Deterministic ordering:
        // real first, fictitious last;
        // stable within category by name
        usort(
            $defendants,
            function ($a, $b) {

                $a = is_array($a) ? $a : [];
                $b = is_array($b) ? $b : [];

                $aF = ($a['type'] ?? null) === 'fictitious';
                $bF = ($b['type'] ?? null) === 'fictitious';

                if ($aF === $bF) {
                    return strcmp(
                        (string)($a['name'] ?? ''),
                        (string)($b['name'] ?? '')
                    );
                }

                return $aF ? 1 : -1;
            }
        );

        return $defendants;
    }

    private function buildFictitiousOccupant(array $intent): ?array
    {
        $address = null;

        if (
            !empty($intent['property']['address'])
            && is_array($intent['property']['address'])
        ) {
            $address = $intent['property']['address'];
        }

        return [
            'intent_role' => 'defendant',
            'type' => 'fictitious',
            'name' => 'John Doe Occupants 1–10',
            'address' => $address,

            'fictitious' => [
                'kind' => 'occupant',
                'reason' => 'unknown occupants',
            ],
        ];
    }

    /* ============================================================
     * Property Formatting (Complaint fields expect string)
     * ============================================================ */

    private function extractStreetAddressString(array $intent): ?string
    {
        // Primary canonical source: intent.property.address
        if (
            !empty($intent['property']['address'])
            && is_array($intent['property']['address'])
        ) {
            return $this->formatAddressOneLine(
                $intent['property']['address']
            );
        }

        // Fallback (legacy safety): first defendant address
        $defendants = $intent['defendants'] ?? [];

        if (is_array($defendants)) {
            foreach ($defendants as $d) {
                if (
                    is_array($d)
                    && !empty($d['address'])
                    && is_array($d['address'])
                ) {
                    return $this->formatAddressOneLine(
                        $d['address']
                    );
                }
            }
        }

        return null;
    }

    private function formatAddressOneLine(array $addr): ?string
    {
        $line1 = isset($addr['line1'])
            ? trim((string)$addr['line1'])
            : '';

        $line2 = isset($addr['line2'])
            ? trim((string)$addr['line2'])
            : '';

        $city = isset($addr['city'])
            ? trim((string)$addr['city'])
            : '';

        $state = isset($addr['state'])
            ? trim((string)$addr['state'])
            : '';

        $zip = isset($addr['postal_code'])
            ? trim((string)$addr['postal_code'])
            : '';

        if ($line1 === '') {
            return null;
        }

        $street = $line1;

        if ($line2 !== '') {
            $street .= ', ' . $line2;
        }

        $local = trim(
            implode(
                ' ',
                array_filter([
                    $city !== '' ? $city . ',' : '',
                    $state,
                    $zip,
                ])
            )
        );

        return trim(
            $street
            . ($local !== '' ? ' ' . $local : '')
        );
    }

    /* ============================================================
     * Structured Summons Builder (Phase 16.6 Safe)
     * ============================================================ */

    private function buildStructuredSummons(array $intent): array
    {
        $plaintiffs = $intent['plaintiffs'] ?? [];
        $defendants = $this->defendantsWithFictitious($intent);
        $court = $intent['court_display'] ?? [];

        return [
            'document_structure_version' => 1,
            'action_type' => $intent['action_type'],

            'attorney_block' => [
                'attorney_name' => null,
                'bar_number' => null,
                'firm_name' => null,
                'address' => null,
                'phone' => null,
                'email' => null,
                'attorneys_for' => 'Plaintiff',
            ],

            'court_heading' => $this->buildCourtHeading($court),

            'caption' => [
                'plaintiffs' => $plaintiffs,
                'defendants' => $defendants,
                'case_number' => null,
                'title' => 'Summons',
                'subtitle' => 'Forcible Entry and Detainer',
            ],

            'service_block' => [
                'recipient_label' => 'FROM THE STATE OF ARIZONA TO:',
                'recipient_name' => null,
                'recipient_address' => null,
            ],

            'statutory_notice' => [
                'warning_present' => true,
                'response_time_in_state_days' => 20,
                'response_time_out_of_state_days' => 30,

                'service_methods' => [
                    'registered_process_server',
                    'sheriff',
                    'publication',
                ],
            ],

            'clerk_information' => [
                'filing_locations' => [
                    'Phoenix',
                    'Mesa',
                    'Surprise',
                ],
            ],

            'accommodation_notice' => [
                'ada_accommodation_required' => true,
                'interpreter_request_required' => true,
            ],

            'eviction_notice' => [
                'telephonic_hearing_available' => true,
            ],

            'signature_block' => [
                'signed_and_sealed_date' => null,
                'clerk' => 'Maricopa County Superior Court Clerk',
                'deputy_clerk_signature' => null,
            ],
        ];
    }

    /* ============================================================
     * Structured Certificate Builder (Phase 16.6 Safe)
     * ============================================================ */

    private function buildStructuredCertificate(array $intent): array
    {
        $plaintiffs = $intent['plaintiffs'] ?? [];
        $defendants = $this->defendantsWithFictitious($intent);
        $court = $intent['court_display'] ?? [];

        $county = $court['court_county'] ?? null;
        $actionType = $intent['action_type'] ?? null;
        $amount = $intent['amount_in_controversy'] ?? null;

        /* ------------------------------------------------------------
         * Determine arbitration status
         * ------------------------------------------------------------ */

        /*
        * ------------------------------------------------------------
        * Forcible detainer proceedings are non-arbitrable for purposes
        * of this filing certificate.
        *
        * Avoid invoking monetary arbitration-limit logic when the
        * action type itself deterministically establishes exemption.
        * ------------------------------------------------------------
        */

        if ($actionType === 'az_forcible_detainer') {

            $status = 'exempt';

        } else {

            $status = $this->determineArbitrationStatus(
                (string)$actionType,
                (string)$county,
                is_numeric($amount)
                    ? (float)$amount
                    : null
            );
        }

        $subjectToArbitration =
            ($status === 'eligible');

        return [
            'document_structure_version' => 2,
            'action_type' => $intent['action_type'],

            'attorney_block' => [
                'attorney_name' => null,
                'bar_number' => null,
                'firm_name' => null,
                'address' => null,
                'phone' => null,
                'email' => null,
                'attorneys_for' => 'Plaintiff',
            ],

            'court_heading' =>
                $this->buildCourtHeading($court),

            'caption' => [
                'plaintiffs' => $plaintiffs,
                'defendants' => $defendants,
                'case_number' => null,
            ],

            'certificate_body' => [
                'relief_type' => 'non_monetary',

                /*
                 * Explicit status stored in bundle.
                 */
                'arbitration_status' => $status,

                'subject_to_compulsory_arbitration' =>
                    $subjectToArbitration,

                'rule_reference' =>
                    'Rule 72, Ariz.R.Civ.P.',
            ],

            'signature_block' => [
                'submitted_date' => null,
                'law_firm_name' => null,
                'attorney_name' => null,
                'address' => null,
                'phone' => null,
            ],

            'filing_acknowledgment' => [
                'filed_date' => null,

                'clerk_name' =>
                    $county
                        ? $county . ' County Superior Court Clerk'
                        : null,

                'clerk_address' => [
                    'line1' =>
                        $court['court_address_line1'] ?? null,

                    'city' =>
                        $court['court_city'] ?? null,

                    'state' =>
                        $court['court_state'] ?? null,

                    'postal_code' => null,
                ],

                'deputy_signature' => null,
            ],
        ];
    }

    /* ============================================================
     * Structured Complaint Builder (Phase 16.6 Safe)
     * ============================================================ */

    private function buildStructuredComplaint(
        array $intent,
        array $resolvedExhibits = []
    ): array {

        $plaintiffs = $intent['plaintiffs'] ?? [];
        $defendants = $this->defendantsWithFictitious($intent);
        $court = $intent['court_display'] ?? [];

        /* ============================================================
         * PROPERTY NORMALIZATION
         * ============================================================ */

        $streetAddress =
            $this->extractStreetAddressString($intent);

        $legalDescription =
            is_string(
                $intent['property']['legal_description'] ?? null
            )
                ? $intent['property']['legal_description']
                : null;

        /* ============================================================
         * DEMAND (Phase 17 field alignment)
         * ============================================================ */

        $demand = $intent['demand'] ?? [];

        $demandMethod =
            $demand['method']
            ?? $demand['demand_method']
            ?? null;

        $demandDate =
            $demand['date']
            ?? $demand['demand_date']
            ?? null;

        $statutoryBasis =
            $demand['statutory_basis']
            ?? [
                'A.R.S. §12-1173',
                'A.R.S. §12-1173.01(A)(2)',
            ];

        /* ============================================================
         * SIGNATURE (Phase 17 validator expects attorney_name)
         * ============================================================ */

        $signature =
            $intent['signature'] ?? [];

        $attorney =
            $intent['attorney'] ?? [];

        $attorneyName =
            $signature['attorney_name']
            ?? $signature['signed_by']
            ?? $attorney['name']
            ?? null;

        $signedDate =
            $signature['signed_date']
            ?? date('Y-m-d');

        return [
            'document_structure_version' => 3,

            'court_heading' =>
                $this->buildCourtHeading($court),

            'caption' => [
                'plaintiffs' =>
                    $plaintiffs,

                'defendants' =>
                    $defendants,

                'case_number' =>
                    null,

                'title' =>
                    'Complaint',

                'statutory_reference' =>
                    'A.R.S. §12-1171 et seq.',
            ],

            'sections' => [

                /* ====================================================
                 * REQUIRED: PROPERTY ADDRESS
                 * ==================================================== */

                'property_address' => [
                    'street_address' =>
                        $streetAddress,

                    'legal_description' =>
                        $legalDescription,
                ],

                /* ====================================================
                 * REQUIRED: DEMAND
                 * ==================================================== */

                'demand' => [
                    'method' =>
                        $demandMethod,

                    'date' =>
                        $demandDate,

                    'statutory_basis' =>
                        $statutoryBasis,
                ],

                /* ====================================================
                 * REQUIRED: RELIEF
                 * ==================================================== */

                'relief' => [
                    'possession_requested' =>
                        true,

                    'writ_of_restitution_requested' =>
                        true,

                    'reasonable_rental_value_requested' =>
                        true,

                    'attorneys_fees_requested' =>
                        true,
                ],

                /* ====================================================
                 * REQUIRED: EXHIBITS
                 *
                 * Phase 18:
                 * Deterministically populated from finalized
                 * case_documents via ExhibitLinkageService.
                 * ==================================================== */

                'exhibits' =>
                    $resolvedExhibits,

                /* ====================================================
                 * REQUIRED: SIGNATURE
                 * ==================================================== */

                'signature' => [
                    'attorney_name' =>
                        $attorneyName,

                    'signed_date' =>
                        $signedDate,
                ],
            ],
        ];
    }
}