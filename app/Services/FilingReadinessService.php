<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use App\Services\Venue\VenueResolutionOrchestratorService;
use App\Procedures\ResolveVenueProcedure;
use RuntimeException;


// Phase 16.4 — Read-only filing readiness analysis
// This service must not mutate state or emit events.

final class FilingReadinessService
{
    // -----------------------------
    // Phase 16.4 — Stable Check Keys
    // -----------------------------

    public const CHECK_LEGAL_ACTION_EXISTS            = 'legal_action.exists';
    public const CHECK_LEGAL_ACTION_STATUS            = 'legal_action.status.initiated';

    public const CHECK_PREREQ_DEMAND_SERVICE          = 'prerequisite.az_demand_service_proof.completed';

    public const CHECK_PARTY_PLAINTIFF_PRESENT        = 'party.plaintiff.present';
    public const CHECK_PARTY_DEFENDANT_PRESENT        = 'party.defendant.present';
    public const CHECK_PARTY_OCCUPANT_OR_DEFENDANT    = 'party.occupant_or_defendant.present';

    public const CHECK_PROPERTY_ATTACHED              = 'property.attached';

    public const CHECK_COURT_SNAPSHOT_PRESENT         = 'court.snapshot.present';
    public const CHECK_COURT_SNAPSHOT_FIELDS_PRESENT  = 'court.snapshot.fields.present';

    public const CHECK_FILING_CAPTION_PRESENT         = 'filing.caption.present';
    public const CHECK_FILING_REPRESENTATION_PRESENT  = 'filing.representation.present';

    public const CHECK_ACTION_TYPE_KNOWN              = 'action_type.known';

    public const CHECK_PARENT_FORCIBLE_DETAINER       = 'parent.forcible_detainer.exists';
    public const CHECK_JUDGMENT_REFERENCE_PRESENT     = 'judgment.reference.present';
    public const CHECK_SERVICE_TARGETS_PRESENT        = 'service.targets.present';

    private const PLAINTIFF_SEMANTIC_ROLES = [
        'client',
        'owner',
        'buyer',
        'lienholder',
        'hoa',
        'taxing_authority',
    ];

    private const DEFENDANT_SEMANTIC_ROLES = [
        'occupant',
        'tenant',   // semantic: renter / lessee
        'seller',
        'buyer',
        'other',
    ];

    public function __construct(private PDO $db) {}

    
    public function assess(
        string $tenantId,
        string $caseId,
        string $legalActionId
    ): array {

        $headers = [
            'tenant_id' => $tenantId,
            'case_id' => $caseId,
            'legal_action_id' => $legalActionId,
        ];

        /*
        ------------------------------------------------------------
        Step 1 — Load legal action
        ------------------------------------------------------------
        */

        $la = $this->loadLegalAction(
            $tenantId,
            $caseId,
            $legalActionId
        );

        if ($la === null) {

            return [
                ...$headers,
                'action_type' => null,
                'is_structurally_ready' => false,
                'summary' =>
                    'Not ready — structural requirements incomplete',
                'checks' => [[
                    'key' => self::CHECK_LEGAL_ACTION_EXISTS,
                    'status' => 'failed',
                    'message' =>
                        'Legal action record not found for this case',
                ]],
            ];
        }

        $actionType =
            (string)($la['action_type']
            ?? $la['type']
            ?? '');

        $checks = [];

        /*
        ------------------------------------------------------------
        Global checks (all action types)
        ------------------------------------------------------------
        */

        $checks[] =
            $this->checkLegalActionExists($la);

        $checks[] =
            $this->checkLegalActionStatusInitiated($la);

        /*
        ------------------------------------------------------------
        NEW — Venue deterministic validation
        ------------------------------------------------------------
        */

        $checks[] =
            $this->checkVenueResolvedAndCurrent(
                $tenantId,
                $caseId
            );

        /*
        ------------------------------------------------------------
        Action-type specific checklist
        ------------------------------------------------------------
        */

        switch ($actionType) {

            case 'az_forcible_detainer':

                $checks = array_merge(
                    $checks,
                    $this->checklistAzForcibleDetainer(
                        $tenantId,
                        $caseId,
                        $la
                    )
                );

                break;

            case 'az_demand_for_possession':

                $checks = array_merge(
                    $checks,
                    $this->checklistAzDemandForPossession(
                        $tenantId,
                        $caseId,
                        $la
                    )
                );

                break;

            case 'az_writ_of_restitution':

                $checks = array_merge(
                    $checks,
                    $this->checklistAzWritOfRestitution(
                        $tenantId,
                        $caseId,
                        $la
                    )
                );

                break;

            default:

                /*
                Deterministic non-blocking behavior
                */

                $checks[] = [
                    'key' =>
                        self::CHECK_ACTION_TYPE_KNOWN,

                    'status' => 'warning',

                    'message' =>
                        'No Phase 16.4 checklist exists for this action type yet',
                ];

                break;
        }

        /*
        ------------------------------------------------------------
        Evaluate readiness
        ------------------------------------------------------------
        */

        $hasFailures = false;

        foreach ($checks as $c) {

            if (($c['status'] ?? '') === 'failed') {

                $hasFailures = true;
                break;
            }
        }

        /*
        ------------------------------------------------------------
        Deterministic response
        ------------------------------------------------------------
        */

        return [
            ...$headers,

            'action_type' =>
                $actionType !== ''
                ? $actionType
                : null,

            'is_structurally_ready' =>
                !$hasFailures,

            'summary' =>
                $hasFailures
                ? 'Not ready — structural requirements incomplete'
                : 'Structurally ready for filing (not filed)',

            'checks' =>
                $checks,
        ];
    }

    /* ============================================================
     * Action-type checklists
     * ============================================================ */

    private function checklistAzForcibleDetainer(string $tenantId, string $caseId, array $la): array
    {
        $checks = [];

        // Prerequisite completion: az_demand_service_proof
        $checks[] = $this->checkPrerequisiteCompleted(
            $tenantId,
            $caseId,
            'az_demand_service_proof',
            self::CHECK_PREREQ_DEMAND_SERVICE
        );

        // Required parties
       $checks[] = $this->checkPlaintiffSemanticPresent($tenantId, $caseId);

        // Defendant presence (includes required fictitious defendant)
        $checks[] = $this->checkDefendantPresence($tenantId, $caseId);

        // Property association
        $checks[] = $this->checkPropertyAttached($tenantId, $caseId, $la);

        // Court snapshot (informational)
        $checks[] = $this->checkCourtSnapshotPresent($tenantId, $caseId, $la);
        $checks[] = $this->checkCourtSnapshotFields($tenantId, $caseId, $la);

        // Filing metadata presence (structural requirements; no validation)
        $checks[] = $this->checkFilingCaptionPresent($la);
        $checks[] = $this->checkFilingRepresentationPresent($la);

        return $checks;
    }

    private function checklistAzDemandForPossession(string $tenantId, string $caseId, array $la): array
    {
        $checks = [];

        // Parties
        $checks[] = $this->checkPlaintiffSemanticPresent($tenantId, $caseId);

        // occupant OR defendant acceptable (structural)
        $occ = $this->partyRoleExists($tenantId, $caseId, 'occupant');
        $def = $this->partyRoleExists($tenantId, $caseId, 'defendant');
        $checks[] = [
            'key' => self::CHECK_PARTY_OCCUPANT_OR_DEFENDANT,
            'status' => ($occ || $def) ? 'passed' : 'failed',
            'message' => ($occ || $def)
                ? 'Occupant/defendant party present'
                : 'No occupant or defendant party found',
        ];

        // Service targets present (structural only)
        $checks[] = $this->checkServiceTargetsPresent($tenantId, $caseId, $la);

        // Property association
        $checks[] = $this->checkPropertyAttached($tenantId, $caseId, $la);

        return $checks;
    }

    private function checklistAzWritOfRestitution(string $tenantId, string $caseId, array $la): array
    {
        $checks = [];

        // Parent forcible detainer linkage (structural only)
        $checks[] = $this->checkParentForcibleDetainerExists($tenantId, $caseId, $la);

        // Judgment reference present (metadata)
        $checks[] = $this->checkJudgmentReferencePresent($la);

        // Court snapshot informational
        $checks[] = $this->checkCourtSnapshotPresent($tenantId, $caseId, $la);

        return $checks;
    }

    /* ============================================================
     * Global checks
     * ============================================================ */

    private function checkLegalActionExists(array $la): array
    {
        return [
            'key' => self::CHECK_LEGAL_ACTION_EXISTS,
            'status' => !empty($la['id']) ? 'passed' : 'failed',
            'message' => !empty($la['id']) ? 'Legal action exists' : 'Legal action missing',
        ];
    }

    private function checkLegalActionStatusInitiated(array $la): array
    {
        $status = (string)($la['action_status'] ?? $la['status'] ?? '');
        $ok = in_array($status, ['initiated', 'prepared'], true);

        return [
            'key' => self::CHECK_LEGAL_ACTION_STATUS,
            'status' => $ok ? 'passed' : 'failed',
            'message' => $ok
                ? 'Legal action status is initiated/prepared'
                : 'Legal action status is not initiated/prepared',
        ];
    }

    /* ============================================================
     * Checklist items
     * ============================================================ */

    private function checkPrerequisiteCompleted(
        string $tenantId,
        string $caseId,
        string $obligationKey,
        string $checkKey
    ): array {
        try {
            $st = $this->db->prepare("
                SELECT status, completed_at
                FROM case_procedural_deadlines
                WHERE tenant_id = :tenant
                  AND case_id = :case
                  AND obligation_key = :ok
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $st->bindValue(':tenant', $tenantId);
            $st->bindValue(':case', $caseId);
            $st->bindValue(':ok', $obligationKey);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return [
                    'key' => $checkKey,
                    'status' => 'failed',
                    'message' => "Prerequisite not found: {$obligationKey}",
                ];
            }

            $status = (string)($row['status'] ?? '');
            $completedAt = $row['completed_at'] ?? null;
            $isDone = ($status === 'completed') || ($completedAt !== null && (string)$completedAt !== '');

            return [
                'key' => $checkKey,
                'status' => $isDone ? 'passed' : 'failed',
                'message' => $isDone
                    ? "Prerequisite satisfied: {$obligationKey}"
                    : "Prerequisite incomplete: {$obligationKey}",
            ];
        } catch (PDOException) {
            // Must not break readiness analysis if schema differs; return warning rather than throw.
            return [
                'key' => $checkKey,
                'status' => 'warning',
                'message' => "Prerequisite check unavailable (schema/query): {$obligationKey}",
            ];
        }
    }


    private function partyRoleExists(string $tenantId, string $caseId, string $role): bool
    {
        // Try common column name: party_role
        try {
            $st = $this->db->prepare("
                SELECT 1
                FROM case_parties
                WHERE tenant_id = :tenant
                  AND case_id = :case
                  AND party_role = :role
                LIMIT 1
            ");
            $st->execute([':tenant' => $tenantId, ':case' => $caseId, ':role' => $role]);
            return (bool)$st->fetchColumn();
        } catch (PDOException) {
            // Fallback column name: role
        }

        try {
            $st = $this->db->prepare("
                SELECT 1
                FROM case_parties
                WHERE tenant_id = :tenant
                  AND case_id = :case
                  AND role = :role
                LIMIT 1
            ");
            $st->execute([':tenant' => $tenantId, ':case' => $caseId, ':role' => $role]);
            return (bool)$st->fetchColumn();
        } catch (PDOException) {
            // If we can’t query parties, treat as not present (structural failure)
            return false;
        }
    }

private function checkPropertyAttached(string $tenantId, string $caseId, array $la): array
{
    try {
        $st = $this->db->prepare("
            SELECT 1
            FROM case_properties
            WHERE tenant_id = :tenant
              AND case_id   = :case
              AND (ends_at IS NULL OR ends_at > now())
            LIMIT 1
        ");
        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
        ]);

        $ok = (bool)$st->fetchColumn();

        return [
            'key' => self::CHECK_PROPERTY_ATTACHED,
            'status' => $ok ? 'passed' : 'failed',
            'message' => $ok
                ? 'Property associated with case'
                : 'Missing required property association for case',
        ];
    } catch (PDOException) {
        // Phase 16.4 must never explode on schema differences
        return [
            'key' => self::CHECK_PROPERTY_ATTACHED,
            'status' => 'warning',
            'message' => 'Unable to evaluate property association (schema/query)',
        ];
    }
}


    private function checkCourtSnapshotPresent(string $tenantId, string $caseId, array $la): array
    {
        // Informational only; must never block readiness
        $present = false;

        // Option A: embedded on legal action payload/court fields
        if (!empty($la['court_snapshot']) || !empty($la['court'])) {
            $present = true;
        }

        // Option B: query a table if your schema has one (optional)
        if (!$present) {
            try {
                $st = $this->db->prepare("
                    SELECT 1
                    FROM legal_action_court_snapshots
                    WHERE tenant_id = :tenant
                      AND case_id = :case
                      AND legal_action_id = :la
                    LIMIT 1
                ");
                $st->execute([
                    ':tenant' => $tenantId,
                    ':case' => $caseId,
                    ':la' => (string)($la['id'] ?? ''),
                ]);
                $present = (bool)$st->fetchColumn();
            } catch (PDOException) {
                // Silent; informational only
            }
        }

        return [
            'key' => self::CHECK_COURT_SNAPSHOT_PRESENT,
            'status' => $present ? 'passed' : 'warning',
            'message' => $present ? 'Court snapshot present' : 'Court snapshot not recorded (informational only)',
        ];
    }

    private function checkCourtSnapshotFields(string $tenantId, string $caseId, array $la): array
    {
        // Informational only
        $fieldsOk = false;

        $court = $la['court'] ?? $la['court_snapshot'] ?? null;
        if (is_array($court)) {
            $name = (string)($court['name'] ?? '');
            $county = (string)($court['county'] ?? '');
            $state = (string)($court['state'] ?? '');
            $fieldsOk = ($name !== '' && $county !== '' && $state !== '');
        }

        return [
            'key' => self::CHECK_COURT_SNAPSHOT_FIELDS_PRESENT,
            'status' => $fieldsOk ? 'passed' : 'warning',
            'message' => $fieldsOk
                ? 'Court snapshot includes court name, county, and state'
                : 'Court snapshot missing fields (informational only)',
        ];
    }

    private function checkFilingCaptionPresent(array $la): array
    {
        $payload = $this->decodeJsonField($la['payload'] ?? null);
        $caption = null;

        if (is_array($payload)) {
            if (isset($payload['filing']) && is_array($payload['filing'])) {
                $caption = $payload['filing']['caption'] ?? null;
            }
            $caption ??= $payload['caption'] ?? null;
        }

        $ok = is_string($caption) && trim($caption) !== '';

        return [
            'key' => self::CHECK_FILING_CAPTION_PRESENT,
            'status' => $ok ? 'passed' : 'failed',
            'message' => $ok ? 'Filing caption metadata present' : 'Missing filing caption metadata',
        ];
    }

    private function checkFilingRepresentationPresent(array $la): array
    {
        $payload = $this->decodeJsonField($la['payload'] ?? null);

        $ok = false;
        if (is_array($payload)) {
            $filing = $payload['filing'] ?? null;
            if (is_array($filing)) {
                $ok = !empty($filing['attorney']) || !empty($filing['pro_se']);
            } else {
                $ok = !empty($payload['attorney']) || !empty($payload['pro_se']);
            }
        }

        return [
            'key' => self::CHECK_FILING_REPRESENTATION_PRESENT,
            'status' => $ok ? 'passed' : 'failed',
            'message' => $ok
                ? 'Attorney/pro-se indicator present'
                : 'Missing attorney/pro-se indicator',
        ];
    }

    private function checkServiceTargetsPresent(string $tenantId, string $caseId, array $la): array
    {
        // Structural only: targets exist somewhere.
        // If schema differs, return warning rather than breaking.

        try {
            // If legal action has an action_request_id column, use it. Otherwise, best-effort via latest action_request for case.
            $requestId = (string)($la['action_request_id'] ?? '');
            if ($requestId === '') {
                $st = $this->db->prepare("
                    SELECT id
                    FROM legal_action_requests
                    WHERE tenant_id = :tenant
                      AND case_id = :case
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                $st->execute([':tenant' => $tenantId, ':case' => $caseId]);
                $requestId = (string)($st->fetchColumn() ?: '');
            }

            if ($requestId === '') {
                return [
                    'key' => self::CHECK_SERVICE_TARGETS_PRESENT,
                    'status' => 'failed',
                    'message' => 'No associated action request found to evaluate service targets',
                ];
            }

            $st = $this->db->prepare("
                SELECT 1
                FROM legal_action_request_targets
                WHERE tenant_id = :tenant
                  AND case_id = :case
                  AND action_request_id = :rid
                LIMIT 1
            ");
            $st->execute([':tenant' => $tenantId, ':case' => $caseId, ':rid' => $requestId]);

            $ok = (bool)$st->fetchColumn();

            return [
                'key' => self::CHECK_SERVICE_TARGETS_PRESENT,
                'status' => $ok ? 'passed' : 'failed',
                'message' => $ok ? 'Service targets present' : 'Missing service targets',
            ];
        } catch (PDOException) {
            return [
                'key' => self::CHECK_SERVICE_TARGETS_PRESENT,
                'status' => 'warning',
                'message' => 'Service target check unavailable (schema/query); informational only',
            ];
        }
    }

    private function checkParentForcibleDetainerExists(string $tenantId, string $caseId, array $la): array
    {
        $parentId = (string)($la['parent_legal_action_id'] ?? '');
        if ($parentId === '') {
            return [
                'key' => self::CHECK_PARENT_FORCIBLE_DETAINER,
                'status' => 'failed',
                'message' => 'Missing parent legal action linkage',
            ];
        }

        try {
            $st = $this->db->prepare("
                SELECT action_type
                FROM legal_actions
                WHERE tenant_id = :tenant
                  AND case_id = :case
                  AND id = :id
                LIMIT 1
            ");
            $st->execute([':tenant' => $tenantId, ':case' => $caseId, ':id' => $parentId]);
            $type = (string)($st->fetchColumn() ?: '');

            $ok = ($type === 'az_forcible_detainer');

            return [
                'key' => self::CHECK_PARENT_FORCIBLE_DETAINER,
                'status' => $ok ? 'passed' : 'failed',
                'message' => $ok
                    ? 'Parent forcible detainer action linked'
                    : 'Parent action is missing or not az_forcible_detainer',
            ];
        } catch (PDOException) {
            return [
                'key' => self::CHECK_PARENT_FORCIBLE_DETAINER,
                'status' => 'warning',
                'message' => 'Parent action check unavailable (schema/query); informational only',
            ];
        }
    }

private function checkDefendantPresence(string $tenantId, string $caseId): array
{
    try {
        $st = $this->db->prepare("
            SELECT
                COUNT(*) FILTER (
                    WHERE party_role = 'occupant'
                ) AS occupant_count,

                COUNT(*) FILTER (
                    WHERE party_role = 'occupant'
                      AND is_fictitious = true
                ) AS fictitious_occupant_count
            FROM case_parties
            WHERE tenant_id = :tenant
              AND case_id   = :case
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $occupants            = (int)($row['occupant_count'] ?? 0);
        $fictitiousOccupants  = (int)($row['fictitious_occupant_count'] ?? 0);

        /*
         * Phase 16.4 structural rule:
         *
         * A case is structurally capable of forming defendants if:
         *  - at least one occupant exists, OR
         *  - at least one fictitious occupant exists
         *
         * "Defendant" is a derived filing role, not a stored party_role.
         */

        if ($occupants === 0 && $fictitiousOccupants === 0) {
            return [
                'key' => self::CHECK_PARTY_DEFENDANT_PRESENT,
                'status' => 'failed',
                'message' =>
                    'No occupants found to form defendants (real or fictitious)',
            ];
        }

        // Informational: only fictitious occupant exists
        if ($occupants === 0 && $fictitiousOccupants > 0) {
            return [
                'key' => self::CHECK_PARTY_DEFENDANT_PRESENT,
                'status' => 'passed',
                'message' =>
                    'Only fictitious occupant is present; named defendants may be added at filing',
            ];
        }

        // Fully satisfied
        return [
            'key' => self::CHECK_PARTY_DEFENDANT_PRESENT,
            'status' => 'passed',
            'message' =>
                'Occupant(s) present and can be designated as defendants at filing',
        ];

    } catch (PDOException) {
        // Phase 16.4 must never crash on schema issues
        return [
            'key' => self::CHECK_PARTY_DEFENDANT_PRESENT,
            'status' => 'warning',
            'message' =>
                'Unable to evaluate occupant availability for defendants (schema/query)',
        ];
    }
}

    private function checkPlaintiffSemanticPresent(string $tenantId, string $caseId): array
    {
        try {
            $in = "'" . implode("','", self::PLAINTIFF_SEMANTIC_ROLES) . "'";
            $st = $this->db->query("
                SELECT 1
                FROM case_parties
                WHERE tenant_id = '{$tenantId}'
                AND case_id = '{$caseId}'
                AND party_role IN ({$in})
                LIMIT 1
            ");

            $ok = (bool)$st->fetchColumn();

            return [
                'key' => self::CHECK_PARTY_PLAINTIFF_PRESENT,
                'status' => $ok ? 'passed' : 'failed',
                'message' => $ok
                    ? 'Plaintiff-side party present'
                    : 'No plaintiff-side party identified',
            ];
        } catch (PDOException) {
            return [
                'key' => self::CHECK_PARTY_PLAINTIFF_PRESENT,
                'status' => 'warning',
                'message' => 'Unable to evaluate plaintiff-side parties (schema/query)',
            ];
        }
    }

    private function checkJudgmentReferencePresent(array $la): array
    {
        $payload = $this->decodeJsonField($la['payload'] ?? null);
        $ref = null;

        if (is_array($payload)) {
            $ref = $payload['judgment_reference'] ?? ($payload['judgment']['reference'] ?? null);
        }

        $ok = is_string($ref) && trim($ref) !== '';

        return [
            'key' => self::CHECK_JUDGMENT_REFERENCE_PRESENT,
            'status' => $ok ? 'passed' : 'failed',
            'message' => $ok ? 'Judgment reference metadata present' : 'Missing judgment reference metadata',
        ];
    }

    /* ============================================================
     * Storage/loading helpers
     * ============================================================ */

    private function loadLegalAction(string $tenantId, string $caseId, string $legalActionId): ?array
    {
        try {
            $st = $this->db->prepare("
                SELECT *
                FROM legal_actions
                WHERE tenant_id = :tenant
                  AND case_id = :case
                  AND id = :id
                LIMIT 1
            ");
            $st->bindValue(':tenant', $tenantId);
            $st->bindValue(':case', $caseId);
            $st->bindValue(':id', $legalActionId);
            $st->execute();

            $row = $st->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (PDOException) {
            return null;
        }
    }

    private function decodeJsonField(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function ensureVenueResolvedAndCurrent(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $actorId
    ): void {

        /*
        ------------------------------------------------------------
        Step 1 — Verify venue exists
        ------------------------------------------------------------
        */

        $st = $this->db->prepare("
            SELECT dataset_id
            FROM case_venue_assignments
            WHERE tenant_id = :tenant
            AND case_id = :case
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case' => $caseId
        ]);

        $assignedDataset = $st->fetchColumn();

        if (!$assignedDataset) {
            throw new RuntimeException(
                'VENUE_NOT_RESOLVED'
            );
        }

        /*
        ------------------------------------------------------------
        Step 2 — Determine current active boundary dataset
        ------------------------------------------------------------
        */

        $st2 = $this->db->query("
            SELECT id
            FROM jurisdiction_boundary_datasets
            WHERE is_active = true
            LIMIT 1
        ");

        $currentDataset = $st2->fetchColumn();

        if (!$currentDataset) {
            throw new RuntimeException(
                'BOUNDARY_DATASET_NOT_AVAILABLE'
            );
        }

        /*
        ------------------------------------------------------------
        Step 3 — Re-resolve venue if dataset changed
        ------------------------------------------------------------
        */

        if ($assignedDataset !== $currentDataset) {

            /*
            Deterministic re-resolution.
            Safe under retries.
            */

            $orchestrator =
                new VenueResolutionOrchestratorService(
                    $this->db,
                    new ResolveVenueProcedure($this->db)
                );

            $orchestrator->revalidateIfDatasetChanged(
                tenantId: $tenantId,
                caseId: $caseId,
                legalActionId: $legalActionId,
                actorId: $actorId
            );
        }
    }
}
