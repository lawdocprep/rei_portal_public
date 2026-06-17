<?php
declare(strict_types=1);

namespace App\Services\Procedures;

use App\Services\ProceduralAction;
use App\Services\ProcedureContext;
use PDO;
use RuntimeException;

final class ResolveVenueProcedure implements ProceduralAction
{
    private const STAGE = 'az_file_forcible_detainer';

    public function run(ProcedureContext $ctx): array
    {
        $nowIso = $ctx->nowIso;

        error_log(
            'ResolveVenueProcedure RUN start case=' .
            $ctx->caseId .
            ' tenant=' .
            $ctx->tenantId
        );

        $actionKey =
            (is_string($ctx->larPayload['legal_action_key'] ?? null) &&
            $ctx->larPayload['legal_action_key'] !== '')
                ? (string)$ctx->larPayload['legal_action_key']
                : $this->inferLegalActionKeyFromRequestedAction($ctx);

                if ($actionKey === null || $actionKey === '') {
                    throw new RuntimeException('PHASE_BLOCKED:ACTION_TYPE_MISSING');
                }

        $legalActionCatalogId = $this->resolveLegalActionId(
            $ctx,
            'AZ',
            $actionKey
        );

        if ($legalActionCatalogId === null) {
            throw new RuntimeException('PHASE_BLOCKED:LEGAL_ACTION_CATALOG_MISSING');
        }

        $prop = $this->loadCaseProperty($ctx);

        if ($prop === null) {
            throw new RuntimeException('PHASE_BLOCKED:PROPERTY_MISSING');
        }

        $asOfDate = $this->loadCaseAsOfDate($ctx);

        $countyUnitId = $this->resolveCountyUnitId(
            $ctx,
            'AZ',
            $prop,
            $asOfDate
        );

        if ($countyUnitId === null) {
            throw new RuntimeException('PHASE_BLOCKED:COUNTY_UNRESOLVED');
        }

        $courtTypeCode = $this->resolveCourtTypeCode(
            is_string($ctx->larPayload['metadata']['court_type'] ?? null)
                ? (string)$ctx->larPayload['metadata']['court_type']
                : null
        );

        /*
        ------------------------------------------------------------
        Manual override → spatial/name resolver → default superior
        ------------------------------------------------------------
        */

        $override = $this->resolveManualOverride($ctx);

        if ($override !== null) {
            $jurisdictionUnitId = (string)$override['jurisdiction_unit_id'];
            $courtId = (string)$override['court_id'];
            $precinctId = (string)$override['jurisdiction_unit_id'];
        } else {
            $resolution = $this->resolveCourtAndJurisdiction(
                $ctx,
                'AZ',
                $countyUnitId,
                $courtTypeCode,
                $prop,
                $legalActionCatalogId,
                $asOfDate
            );

            if ($resolution !== null) {
                [$jurisdictionUnitId, $courtId, $precinctId] = $resolution;
            } else {
                $default = $this->resolveSuperiorCourtDefault(
                    $ctx,
                    'AZ',
                    $countyUnitId
                );

                $jurisdictionUnitId = (string)$default['jurisdiction_unit_id'];
                $courtId = (string)$default['court_id'];
                $precinctId = null;
            }
        }

        error_log(
            'ResolveVenueProcedure assignment resolved court_id=' .
            $courtId .
            ' jurisdiction_unit_id=' .
            $jurisdictionUnitId
        );

        $this->persistVenueAssignment(
            $ctx,
            $legalActionCatalogId,
            'AZ',
            $jurisdictionUnitId,
            $courtId,
            $precinctId
        );

        $this->syncLegalActionCourtSnapshot(
            $ctx,
            $courtId
        );

        $caseUpdate = $ctx->db->prepare("
            UPDATE cases
            SET court_id = :court_id
            WHERE tenant_id = :tenant_id
              AND id = :case_id
        ");

        $caseUpdate->execute([
            ':tenant_id' => $ctx->tenantId,
            ':case_id'   => $ctx->caseId,
            ':court_id'  => $courtId,
        ]);

        error_log(
            'ResolveVenueProcedure RUN success case=' .
            $ctx->caseId
        );

        return [
            'executed_at' => $nowIso,
            'executed_by' => $ctx->actorId,
            'generated_documents' => [],
            'emitted_events' => [
                [
                    'event_type' => 'venue_resolved',
                    'payload' => [
                        'case_id' => $ctx->caseId,
                        'court_id' => $courtId,
                        'jurisdiction_unit_id' => $jurisdictionUnitId,
                        'precinct_id' => $precinctId,
                        'resolved_at' => $nowIso,
                        'action_request_id' => $ctx->requestId,
                    ],
                ],
            ],
            'created_deadlines' => [],
            'new_phase' => 'az_file_forcible_detainer',
            'is_complete' => true,
            'procedural_phase_key' => 'az_resolve_venue',
        ];
    }

    private function resolveSuperiorCourtDefault(
        ProcedureContext $ctx,
        string $stateCode,
        string $countyUnitId
    ): array {
        $st = $ctx->db->prepare("
            SELECT
                c.id AS court_id,
                ju.id AS jurisdiction_unit_id
            FROM courts c
            JOIN states s
              ON s.id = c.state_id
            JOIN jurisdiction_units ju
              ON ju.id = c.jurisdiction_unit_id
            WHERE s.code = :state_code
              AND ju.id = :county_unit_id
              AND c.court_type_code = 'GENERAL_TRIAL'
            ORDER BY c.created_at ASC
            LIMIT 1
        ");

        $st->execute([
            ':state_code' => $stateCode,
            ':county_unit_id' => $countyUnitId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('SUPERIOR_COURT_NOT_FOUND');
        }

        return [
            'jurisdiction_unit_id' => (string)$row['jurisdiction_unit_id'],
            'court_id' => (string)$row['court_id'],
            'resolution_strategy' => 'default_superior_court',
        ];
    }

    private function resolveManualOverride(
        ProcedureContext $ctx
    ): ?array {
        $st = $ctx->db->prepare("
            SELECT
                c.id AS court_id,
                o.jurisdiction_unit_id
            FROM case_venue_overrides o
            JOIN courts c
              ON c.jurisdiction_unit_id = o.jurisdiction_unit_id
            WHERE o.case_id = :case
            LIMIT 1
        ");

        $st->execute([
            ':case' => $ctx->caseId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'jurisdiction_unit_id' => (string)$row['jurisdiction_unit_id'],
            'court_id' => (string)$row['court_id'],
            'resolution_strategy' => 'manual_override',
        ];
    }

    private function inferLegalActionKeyFromRequestedAction(
        ProcedureContext $ctx
    ): ?string {

        /*
        ------------------------------------------------------------
        First attempt:
        use explicit legal action key from payload
        ------------------------------------------------------------
        */

        $payloadKey =
            $ctx->larPayload['legal_action_key'] ?? null;

        if (
            is_string($payloadKey)
            && $payloadKey !== ''
        ) {
            return $payloadKey;
        }

        /*
        ------------------------------------------------------------
        Second attempt:
        resolve current legal action from database
        ------------------------------------------------------------
        */

        $st = $ctx->db->prepare("
            SELECT action_type
            FROM legal_actions
            WHERE tenant_id = :tenant
            AND case_id = :case
            ORDER BY created_at DESC
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $ctx->tenantId,
            ':case'   => $ctx->caseId,
        ]);

        $resolved =
            (string)($st->fetchColumn() ?: '');

        if ($resolved !== '') {
            return $resolved;
        }

        /*
        ------------------------------------------------------------
        Final fallback:
        legacy requested-action inference
        ------------------------------------------------------------
        */

        return match ($ctx->requestedAction) {

            'az_resolve_venue',
            'az_file_forcible_detainer',
            'az_verify_occupancy',
            'az_initial_hearing_scheduled',
            'az_initial_hearing_held',
            'az_trial_preparation',
            'az_request_writ',
            'az_writ_of_restitution',
            'az_writ_service_test'
                => 'az_forcible_detainer',

            'az_demand_for_possession'
                => 'az_trustee_sale_possession',

            default => null,
        };
    }

    private function persistVenueAssignment(
        ProcedureContext $ctx,
        string $legalActionCatalogId,
        string $stateCode,
        string $jurisdictionUnitId,
        string $courtId,
        ?string $precinctId
    ): void {
        $st = $ctx->db->prepare("
            INSERT INTO case_venue_assignments (
                id,
                case_id,
                legal_action_id,
                state_code,
                court_id,
                precinct_id,
                stage,
                assigned_by,
                assigned_at,
                jurisdiction_unit_id
            )
            VALUES (
                uuid_generate_v4(),
                :case_id,
                :legal_action_id,
                :state_code,
                :court_id,
                :precinct_id,
                :stage,
                :assigned_by,
                now(),
                :jurisdiction_unit_id
            )
            ON CONFLICT (case_id, legal_action_id, stage)
            DO UPDATE SET
                state_code = EXCLUDED.state_code,
                court_id = EXCLUDED.court_id,
                precinct_id = EXCLUDED.precinct_id,
                assigned_by = EXCLUDED.assigned_by,
                assigned_at = EXCLUDED.assigned_at,
                jurisdiction_unit_id = EXCLUDED.jurisdiction_unit_id
        ");

        $st->execute([
            ':case_id' => $ctx->caseId,
            ':legal_action_id' => $legalActionCatalogId,
            ':state_code' => $stateCode,
            ':court_id' => $courtId,
            ':precinct_id' => $precinctId,
            ':stage' => self::STAGE,
            ':assigned_by' => $ctx->actorId,
            ':jurisdiction_unit_id' => $jurisdictionUnitId,
        ]);
    }

    private function syncLegalActionCourtSnapshot(
        ProcedureContext $ctx,
        string $courtId
    ): void {
        $st = $ctx->db->prepare("
            SELECT
                c.name,
                c.court_type_code,
                s.code AS state_code,
                ju.name AS county_name
            FROM courts c
            JOIN states s
              ON s.id = c.state_id
            JOIN jurisdiction_units ju
              ON ju.id = c.jurisdiction_unit_id
            WHERE c.id = :court
            LIMIT 1
        ");

        $st->execute([
            ':court' => $courtId,
        ]);

        $court = $st->fetch(PDO::FETCH_ASSOC);

        if (!$court) {
            throw new RuntimeException('COURT_NOT_FOUND');
        }

        /*
        Use legal action id from the latest initiated action on this case.
        This avoids depending on a non-existent ctx->legalActionId.
        */
        $la = $ctx->db->prepare("
            SELECT id
            FROM legal_actions
            WHERE tenant_id = :tenant
              AND case_id = :case
            ORDER BY created_at DESC
            LIMIT 1
        ");

        $la->execute([
            ':tenant' => $ctx->tenantId,
            ':case'   => $ctx->caseId,
        ]);

        $legalActionId = $la->fetchColumn();

        if (!is_string($legalActionId) || $legalActionId === '') {
            error_log(
                'ResolveVenueProcedure legal action snapshot skipped: legal action not yet initiated'
            );
            return;
        }

        $st2 = $ctx->db->prepare("
            UPDATE legal_actions
            SET
                court_name   = :name,
                court_type   = :type,
                court_city   = NULL,
                court_state  = :state,
                court_county = :county
            WHERE tenant_id = :tenant
              AND id = :legal_action_id
        ");

        $st2->execute([
            ':tenant' => $ctx->tenantId,
            ':legal_action_id' => $legalActionId,
            ':name' => $court['name'],
            ':type' => $court['court_type_code'],
            ':state' => $court['state_code'],
            ':county' => $court['county_name'],
        ]);
    }

    private function resolveCourtTypeCode(?string $input): string
    {
        $normalized = strtolower(trim((string)$input));

        return match ($normalized) {
            'justice'  => 'LIMITED_TRIAL',
            'superior' => 'GENERAL_TRIAL',
            default    => 'GENERAL_TRIAL',
        };
    }

    private function getPropertyState(ProcedureContext $ctx): ?string
    {
        $st = $ctx->db->prepare("
            SELECT p.state
            FROM case_properties cp
            JOIN properties p
              ON p.id = cp.property_id
             AND p.tenant_id = cp.tenant_id
            WHERE cp.tenant_id = :tenant_id
              AND cp.case_id = :case_id
              AND (cp.ends_at IS NULL OR cp.ends_at > now())
            ORDER BY cp.starts_at DESC
            LIMIT 1
        ");

        $st->execute([
            ':tenant_id' => $ctx->tenantId,
            ':case_id' => $ctx->caseId,
        ]);

        $value = $st->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function loadCaseAsOfDate(ProcedureContext $ctx): string
    {
        $st = $ctx->db->prepare("
            SELECT opened_at
            FROM cases
            WHERE tenant_id = :tenant
              AND id = :case
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $ctx->tenantId,
            ':case'   => $ctx->caseId,
        ]);

        $openedAt = (string)($st->fetchColumn() ?: '');

        if ($openedAt === '') {
            throw new RuntimeException('PHASE_BLOCKED:CASE_OPENED_AT_MISSING');
        }

        $asOf = substr($openedAt, 0, 10);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
            throw new RuntimeException('PHASE_BLOCKED:INVALID_OPENED_AT');
        }

        return $asOf;
    }

    private function resolveCountyUnitId(
        ProcedureContext $ctx,
        string $stateCode,
        array $prop,
        string $asOfDate
    ): ?string {
        $lat = $prop['geo_lat'] ?? null;
        $lon = $prop['geo_lng'] ?? null;

        if ($lat !== null && $lon !== null) {
            $id = $this->resolveJurisdictionUnitByGeometry(
                $ctx,
                $stateCode,
                'county',
                null,
                (float)$lon,
                (float)$lat,
                $asOfDate
            );

            if ($id !== null) {
                return $id;
            }
        }

        $countyName = trim((string)($prop['county'] ?? ''));

        if ($countyName === '') {
            return null;
        }

        $st = $ctx->db->prepare("
            SELECT ju.id
            FROM jurisdiction_units ju
            WHERE ju.state_code = :code
              AND ju.unit_type = 'county'
              AND lower(ju.name) = lower(:name)
            LIMIT 1
        ");

        $st->execute([
            ':code' => $stateCode,
            ':name' => $countyName,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ? (string)$row['id'] : null;
    }

    private function resolveCourtAndJurisdiction(
        ProcedureContext $ctx,
        string $stateCode,
        string $countyUnitId,
        string $courtTypeCode,
        array $prop,
        string $legalActionId,
        string $asOfDate
    ): ?array {
        $mapping = $this->loadCourtTierMapping($ctx, $courtTypeCode);

        if ($mapping === null) {
            $this->auditVenueResolution(
                $ctx,
                $legalActionId,
                self::STAGE,
                $stateCode,
                $courtTypeCode,
                ['required_unit_type' => 'unknown', 'resolution_strategy' => 'unknown'],
                $prop,
                null,
                null,
                null,
                null,
                'error',
                'COURT_TYPE_UNMAPPED',
                0,
                false
            );
            throw new RuntimeException('COURT_TYPE_UNMAPPED');
        }

        $requiredUnitType = (string)$mapping['required_unit_type'];
        $requiresParent   = (bool)$mapping['requires_parent'];
        $parentUnitType   = $mapping['parent_unit_type'];
        $strategy         = (string)$mapping['resolution_strategy'];

        $lat = $prop['geo_lat'] ?? null;
        $lon = $prop['geo_lng'] ?? null;

        $parentId = null;

        if ($requiresParent) {
            if ($parentUnitType === 'county') {
                $parentId = $countyUnitId;
            } else {
                $this->auditVenueResolution(
                    $ctx,
                    $legalActionId,
                    self::STAGE,
                    $stateCode,
                    $courtTypeCode,
                    $mapping,
                    $prop,
                    null,
                    null,
                    null,
                    null,
                    'blocked',
                    'PARENT_TYPE_UNSUPPORTED',
                    0,
                    false
                );
                return null;
            }
        }

        $resolvedUnitId = null;
        $boundaryId = null;
        $datasetId = null;
        $candidatesCount = 0;
        $overlapDetected = false;

        if ($strategy === 'spatial_only' || $strategy === 'spatial_then_name') {
            $this->assertGeometryCoverage(
                $ctx,
                $stateCode,
                $requiredUnitType,
                $asOfDate
            );

            if ($lat !== null && $lon !== null) {
                $e = $this->resolveJurisdictionUnitByGeometryWithEvidence(
                    $ctx,
                    $stateCode,
                    $requiredUnitType,
                    $parentId,
                    (float)$lon,
                    (float)$lat,
                    $asOfDate
                );

                if ($e !== null) {
                    $resolvedUnitId = $e['jurisdiction_unit_id'];
                    $boundaryId = $e['boundary_id'];
                    $datasetId = $e['dataset_id'];
                    $candidatesCount = (int)$e['candidates_count'];
                    $overlapDetected = (bool)$e['overlap_detected'];
                }
            }

            if ($resolvedUnitId === null && $strategy === 'spatial_only') {
                $this->auditVenueResolution(
                    $ctx,
                    $legalActionId,
                    self::STAGE,
                    $stateCode,
                    $courtTypeCode,
                    $mapping,
                    $prop,
                    $parentId,
                    null,
                    null,
                    null,
                    'blocked',
                    'SPATIAL_NO_MATCH',
                    $candidatesCount,
                    $overlapDetected,
                    [
                        'note' => 'spatial_only requires polygon containment',
                        'as_of_date' => $asOfDate,
                    ]
                );
                return null;
            }
        }

        if ($resolvedUnitId === null && $strategy === 'spatial_then_name') {
            if ($requiredUnitType === 'county') {
                $countyName = trim((string)($prop['county'] ?? ''));

                if ($countyName !== '') {
                    $st = $ctx->db->prepare("
                        SELECT ju.id
                        FROM jurisdiction_units ju
                        WHERE ju.state_code = :code
                          AND ju.unit_type = 'county'
                          AND lower(ju.name) = lower(:name)
                        LIMIT 1
                    ");

                    $st->execute([
                        ':code' => $stateCode,
                        ':name' => $countyName,
                    ]);

                    $row = $st->fetch(PDO::FETCH_ASSOC);

                    if ($row) {
                        $resolvedUnitId = (string)$row['id'];
                    }
                }
            }

            if ($resolvedUnitId === null) {
                $this->auditVenueResolution(
                    $ctx,
                    $legalActionId,
                    self::STAGE,
                    $stateCode,
                    $courtTypeCode,
                    $mapping,
                    $prop,
                    $parentId,
                    null,
                    null,
                    null,
                    'blocked',
                    'FALLBACK_NAME_NO_MATCH',
                    0,
                    false,
                    ['as_of_date' => $asOfDate]
                );
                return null;
            }
        }

        if ($strategy === 'name_only') {
            if ($requiredUnitType !== 'county') {
                $this->auditVenueResolution(
                    $ctx,
                    $legalActionId,
                    self::STAGE,
                    $stateCode,
                    $courtTypeCode,
                    $mapping,
                    $prop,
                    $parentId,
                    null,
                    null,
                    null,
                    'blocked',
                    'NAME_ONLY_UNSUPPORTED',
                    0,
                    false,
                    ['as_of_date' => $asOfDate]
                );
                return null;
            }

            $countyName = trim((string)($prop['county'] ?? ''));

            if ($countyName === '') {
                $this->auditVenueResolution(
                    $ctx,
                    $legalActionId,
                    self::STAGE,
                    $stateCode,
                    $courtTypeCode,
                    $mapping,
                    $prop,
                    $parentId,
                    null,
                    null,
                    null,
                    'blocked',
                    'NAME_ONLY_MISSING_COUNTY',
                    0,
                    false,
                    ['as_of_date' => $asOfDate]
                );
                return null;
            }

            $st = $ctx->db->prepare("
                SELECT ju.id
                FROM jurisdiction_units ju
                WHERE ju.state_code = :code
                  AND ju.unit_type = :unit_type
                  AND lower(ju.name) = lower(:name)
                LIMIT 1
            ");

            $st->execute([
                ':code' => $stateCode,
                ':unit_type' => $requiredUnitType,
                ':name' => $countyName,
            ]);

            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->auditVenueResolution(
                    $ctx,
                    $legalActionId,
                    self::STAGE,
                    $stateCode,
                    $courtTypeCode,
                    $mapping,
                    $prop,
                    $parentId,
                    null,
                    null,
                    null,
                    'blocked',
                    'NAME_ONLY_NO_MATCH',
                    0,
                    false,
                    ['as_of_date' => $asOfDate]
                );
                return null;
            }

            $resolvedUnitId = (string)$row['id'];
        }

        $courtId = $this->resolveCourtByJurisdiction(
            $ctx,
            $stateCode,
            $resolvedUnitId,
            $courtTypeCode
        );

        if ($courtId === null) {
            $this->auditVenueResolution(
                $ctx,
                $legalActionId,
                self::STAGE,
                $stateCode,
                $courtTypeCode,
                $mapping,
                $prop,
                $parentId,
                $resolvedUnitId,
                $boundaryId,
                null,
                'blocked',
                'COURT_NOT_FOUND',
                $candidatesCount,
                $overlapDetected,
                ['as_of_date' => $asOfDate]
            );
            return null;
        }

        $precinctId = ($requiredUnitType === 'justice_precinct')
            ? $resolvedUnitId
            : null;

        $this->auditVenueResolution(
            $ctx,
            $legalActionId,
            self::STAGE,
            $stateCode,
            $courtTypeCode,
            $mapping,
            $prop,
            $parentId,
            $resolvedUnitId,
            $boundaryId,
            $courtId,
            'resolved',
            null,
            $candidatesCount,
            $overlapDetected,
            $overlapDetected
                ? [
                    'warning' => 'Multiple polygons matched; smallest-area chosen',
                    'as_of_date' => $asOfDate,
                ]
                : [
                    'as_of_date' => $asOfDate,
                ]
        );

        return [$resolvedUnitId, $courtId, $precinctId];
    }

    private function resolveJurisdictionUnitByGeometryWithEvidence(
        ProcedureContext $ctx,
        string $stateCode,
        string $unitType,
        ?string $parentId,
        float $lon,
        float $lat,
        string $asOfDate
    ): ?array {
        $sql = "
            SELECT
                ju.id AS jurisdiction_unit_id,
                b.id  AS boundary_id,
                b.dataset_id
            FROM jurisdiction_units ju
            JOIN jurisdiction_unit_boundaries b
              ON b.jurisdiction_unit_id = ju.id
             AND b.effective_from <= :as_of::date
             AND (b.effective_to IS NULL OR b.effective_to >= :as_of::date)
            WHERE ju.state_code = :code
              AND ju.unit_type = :unit_type
              AND b.boundary_geometry IS NOT NULL
              AND ST_Covers(
                    b.boundary_geometry,
                    ST_SetSRID(ST_Point(:lon, :lat), 4326)
                )
        ";

        if ($parentId !== null) {
            $sql .= " AND ju.parent_id = :parent_id ";
        }

        $sql .= " ORDER BY ST_Area(b.boundary_geometry) ASC LIMIT 2";

        $st = $ctx->db->prepare($sql);

        $params = [
            ':code' => $stateCode,
            ':unit_type' => $unitType,
            ':lon' => $lon,
            ':lat' => $lat,
            ':as_of' => $asOfDate,
        ];

        if ($parentId !== null) {
            $params[':parent_id'] = $parentId;
        }

        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            return null;
        }

        $candidatesCount = count($rows);
        $overlapDetected = $candidatesCount > 1;
        $chosen = $rows[0];

        return [
            'jurisdiction_unit_id' => (string)$chosen['jurisdiction_unit_id'],
            'boundary_id' => (string)$chosen['boundary_id'],
            'dataset_id' => $chosen['dataset_id'] !== null
                ? (string)$chosen['dataset_id']
                : null,
            'candidates_count' => $candidatesCount,
            'overlap_detected' => $overlapDetected,
        ];
    }

    private function assertGeometryCoverage(
        ProcedureContext $ctx,
        string $stateCode,
        string $unitType,
        string $asOfDate
    ): void {
        $st = $ctx->db->prepare("
            SELECT required, enforce_strategy
            FROM jurisdiction_geometry_coverage
            WHERE state_code = :s
              AND unit_type = :t
            LIMIT 1
        ");

        $st->execute([
            ':s' => $stateCode,
            ':t' => $unitType,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row || (bool)$row['required'] !== true) {
            return;
        }

        $chk = $ctx->db->prepare("
            SELECT 1
            FROM jurisdiction_units ju
            JOIN jurisdiction_unit_boundaries b
              ON b.jurisdiction_unit_id = ju.id
             AND b.effective_from <= :as_of::date
             AND (
                    b.effective_to IS NULL
                 OR b.effective_to >= :as_of::date
             )
            WHERE ju.state_code = :s
              AND ju.unit_type = :t
            LIMIT 1
        ");

        $chk->execute([
            ':s' => $stateCode,
            ':t' => $unitType,
            ':as_of' => $asOfDate,
        ]);

        if (!$chk->fetchColumn()) {
            if (($row['enforce_strategy'] ?? '') === 'warn') {
                return;
            }

            throw new RuntimeException('PHASE_BLOCKED:GEOMETRY_COVERAGE_MISSING');
        }
    }

    private function resolveJurisdictionUnitByGeometry(
        ProcedureContext $ctx,
        string $stateCode,
        string $unitType,
        ?string $parentId,
        float $lon,
        float $lat,
        string $asOfDate
    ): ?string {
        $sql = "
            SELECT ju.id
            FROM jurisdiction_units ju
            JOIN jurisdiction_unit_boundaries b
              ON b.jurisdiction_unit_id = ju.id
             AND b.effective_from <= :as_of::date
             AND (b.effective_to IS NULL OR b.effective_to >= :as_of::date)
            WHERE ju.state_code = :code
              AND ju.unit_type = :unit_type
              AND b.boundary_geometry IS NOT NULL
              AND ST_Covers(
                    b.boundary_geometry,
                    ST_SetSRID(ST_Point(:lon, :lat), 4326)
                  )
        ";

        if ($parentId !== null) {
            $sql .= " AND ju.parent_id = :parent_id ";
        }

        $sql .= "
            ORDER BY ST_Area(b.boundary_geometry) ASC
            LIMIT 1
        ";

        $st = $ctx->db->prepare($sql);

        $params = [
            ':code' => $stateCode,
            ':unit_type' => $unitType,
            ':lon' => $lon,
            ':lat' => $lat,
            ':as_of' => $asOfDate,
        ];

        if ($parentId !== null) {
            $params[':parent_id'] = $parentId;
        }

        $st->execute($params);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ? (string)$row['id'] : null;
    }

    private function auditVenueResolution(
        ProcedureContext $ctx,
        string $legalActionId,
        string $stage,
        string $stateCode,
        string $courtTypeCode,
        array $mapping,
        array $prop,
        ?string $parentId,
        ?string $resolvedUnitId,
        ?string $boundaryId,
        ?string $courtId,
        string $outcome,
        ?string $reasonCode,
        int $candidatesCount,
        bool $overlapDetected,
        array $details = []
    ): void {
        $caseSt = $ctx->db->prepare("
            SELECT opened_at
            FROM cases
            WHERE id = :case_id
              AND tenant_id = :tenant_id
            LIMIT 1
        ");

        $caseSt->execute([
            ':case_id' => $ctx->caseId,
            ':tenant_id' => $ctx->tenantId,
        ]);

        $openedAt = (string)($caseSt->fetchColumn() ?: '');
        $asOfDate = $openedAt !== '' ? substr($openedAt, 0, 10) : null;

        $datasetId = null;
        $boundaryHash = null;
        $datasetHashSha256 = null;

        if ($boundaryId !== null) {
            $snap = $ctx->db->prepare("
                SELECT
                    dataset_id,
                    boundary_hash,
                    dataset_hash_sha256
                FROM jurisdiction_unit_boundaries
                WHERE id = :bid
                LIMIT 1
            ");

            $snap->execute([':bid' => $boundaryId]);
            $row = $snap->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $datasetId = $row['dataset_id'] ?? null;
                $boundaryHash = $row['boundary_hash'] ?? null;
                $datasetHashSha256 = $row['dataset_hash_sha256'] ?? null;
            }
        }

        $resolverVersion =
            getenv('REI_PORTAL_VERSION')
            ?: (getenv('GIT_SHA') ?: null);

        $strictOverlapMode = (bool)($details['strict_overlap_mode'] ?? false);

        $st = $ctx->db->prepare("
            INSERT INTO venue_resolution_audits (
                tenant_id,
                case_id,
                legal_action_id,
                stage,
                state_code,
                court_type_code,
                required_unit_type,
                resolution_strategy,
                property_id,
                geo_lat,
                geo_lng,
                parent_id,
                resolved_jurisdiction_unit_id,
                resolved_boundary_id,
                resolved_dataset_id,
                resolved_court_id,
                as_of_date,
                outcome,
                reason_code,
                candidates_count,
                overlap_detected,
                details,
                resolved_boundary_hash,
                resolved_dataset_hash_sha256,
                resolver_version,
                strict_overlap_mode
            ) VALUES (
                :tenant, :case, :la, :stage, :state,
                :court_type, :req_unit, :strategy,
                :property_id, :lat, :lng,
                :parent_id, :unit_id, :boundary_id, :dataset_id, :court_id,
                :as_of_date,
                :outcome, :reason, :cand, :overlap, :details,
                :boundary_hash, :dataset_hash, :resolver_version, :strict_overlap
            )
        ");

        $st->execute([
            ':tenant' => $ctx->tenantId,
            ':case' => $ctx->caseId,
            ':la' => $legalActionId,
            ':stage' => $stage,
            ':state' => $stateCode,
            ':court_type' => $courtTypeCode,
            ':req_unit' => (string)($mapping['required_unit_type'] ?? ''),
            ':strategy' => (string)($mapping['resolution_strategy'] ?? ''),
            ':property_id' => $prop['id'] ?? null,
            ':lat' => $prop['geo_lat'] ?? null,
            ':lng' => $prop['geo_lng'] ?? null,
            ':parent_id' => $parentId,
            ':unit_id' => $resolvedUnitId,
            ':boundary_id' => $boundaryId,
            ':dataset_id' => $datasetId,
            ':court_id' => $courtId,
            ':as_of_date' => $asOfDate,
            ':outcome' => $outcome,
            ':reason' => $reasonCode,
            ':cand' => $candidatesCount,
            ':overlap' => $overlapDetected ? 'true' : 'false',
            ':details' => json_encode($details, JSON_UNESCAPED_SLASHES),
            ':boundary_hash' => $boundaryHash,
            ':dataset_hash' => $datasetHashSha256,
            ':resolver_version' => $resolverVersion,
            ':strict_overlap' => $strictOverlapMode ? 'true' : 'false',
        ]);
    }

    private function resolveCourtByJurisdiction(
        ProcedureContext $ctx,
        string $stateCode,
        string $jurisdictionUnitId,
        string $courtTypeCode
    ): ?string {
        $st = $ctx->db->prepare("
            SELECT crt.id
            FROM courts crt
            JOIN states s ON s.id = crt.state_id
            WHERE s.code = :code
              AND crt.jurisdiction_unit_id = :jurisdiction_unit_id
              AND crt.court_type_code = :court_type_code
            ORDER BY crt.created_at ASC
            LIMIT 1
        ");

        $st->execute([
            ':code' => $stateCode,
            ':jurisdiction_unit_id' => $jurisdictionUnitId,
            ':court_type_code' => $courtTypeCode,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ? (string)$row['id'] : null;
    }

    private function resolveLegalActionId(
        ProcedureContext $ctx,
        string $stateCode,
        string $actionKey
    ): ?string {
        $st = $ctx->db->prepare("
            SELECT lac.id
            FROM legal_action_catalog lac
            JOIN states s ON s.id = lac.state_id
            WHERE s.code = :code
              AND lac.action_key = :action_key
            LIMIT 1
        ");

        $st->execute([
            ':code' => $stateCode,
            ':action_key' => $actionKey,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ? (string)$row['id'] : null;
    }

    private function loadCourtTierMapping(
        ProcedureContext $ctx,
        string $courtTypeCode
    ): ?array {
        $st = $ctx->db->prepare("
            SELECT
                required_unit_type,
                requires_parent,
                parent_unit_type,
                resolution_strategy
            FROM court_tier_jurisdiction_requirements
            WHERE court_type_code = :code
            LIMIT 1
        ");

        $st->execute([
            ':code' => $courtTypeCode,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function loadCaseProperty(ProcedureContext $ctx): ?array
    {
        $st = $ctx->db->prepare("
            SELECT p.*
            FROM case_properties cp
            JOIN properties p
              ON p.id = cp.property_id
             AND p.tenant_id = cp.tenant_id
            WHERE cp.tenant_id = :tenant_id
              AND cp.case_id = :case_id
              AND (cp.ends_at IS NULL OR cp.ends_at > now())
            ORDER BY cp.starts_at DESC
            LIMIT 1
        ");

        $st->execute([
            ':tenant_id' => $ctx->tenantId,
            ':case_id' => $ctx->caseId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}