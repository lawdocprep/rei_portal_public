<?php
declare(strict_types=1);

namespace App\Services;

use App\Auth\AuthContext;
use App\Services\ProceduralAction;
use App\Services\ProcedureContext;
use App\Services\Events\CaseEventService;
use PDO;
use RuntimeException;

final class CaseActionExecutorService
{
    private CaseEventService $events;

    public function __construct(private PDO $db)
    {
        $this->events = new CaseEventService($db);
    }

public function execute(string $requestId, AuthContext $ctx): array
{
    $tenantId = (string)$ctx->tenantId;
    $actorId  = (string)$ctx->actorId;
    $roles    = $ctx->roles ?? [];

    $isSystemAdmin = in_array('system_admin', $roles, true);

    if (!$isSystemAdmin) {
        $canExecute = (bool)array_intersect($roles, [
            'tenant_admin','legal_admin','attorney','paralegal','pro_se',
        ]);
        if (!$canExecute) {
            throw new RuntimeException('INSUFFICIENT_ROLE');
        }
    }

    $startedTx = false;

    if (!$this->db->inTransaction()) {
        $this->db->beginTransaction();
        $startedTx = true;
    }
    try {
        // Load LAR from case_events payload
        $st = $this->db->prepare("
            SELECT id, case_id, payload
            FROM case_events
            WHERE tenant_id = :tenant_id
              AND id = :id
              AND event_type = 'legal_action_request'
            LIMIT 1
        ");
        $st->execute([':tenant_id' => $tenantId, ':id' => $requestId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('NOT_FOUND');

        $payload = [];
        if (is_string($row['payload'] ?? null)) {
            $tmp = json_decode($row['payload'], true);
            if (is_array($tmp)) $payload = $tmp;
        }

        $status = is_string($payload['status'] ?? null)
            ? $payload['status']
            : 'pending';

        $requestedAction = is_string($payload['requested_action'] ?? null)
            ? $payload['requested_action']
            : '';

        $caseId = (string)$row['case_id'];

        error_log(
            'EXECUTOR_EXECUTE request_id=' .
            $requestId .
            ' requested_action=' .
            $requestedAction
        );

        // --------------------------------------------------
        // Option C: Hard idempotency once delivery begins
        // Applies only to demand-generation actions.
        // Do not block downstream auto-executed actions such as az_resolve_venue.
        // --------------------------------------------------
        if (
            $requestedAction === 'az_demand_for_possession'
            && $this->hasAnyDeliveryEvents(
                tenantId: $tenantId,
                caseId: $caseId,
                requestId: $requestId
            )
        ) {
            throw new RuntimeException('ACTION_REQUEST_ALREADY_EXECUTED');
        }
        

        // BEFORE procedure run: only a fallback (result doesn't exist yet)
        $phaseKey =
            (is_string($payload['procedural_phase_key'] ?? null) && $payload['procedural_phase_key'] !== '')
                ? (string)$payload['procedural_phase_key']
                : (
                    (is_string($payload['phase_key'] ?? null) && $payload['phase_key'] !== '')
                        ? (string)$payload['phase_key']
                        : $requestedAction
                );
        $payload['procedural_phase_key'] = $phaseKey;



        // ✅ True idempotency: only short-circuit when the request is actually complete
        if (isset($payload['execution']) && is_array($payload['execution'])) {
            $exec = $payload['execution'];
            $isComplete = (bool)($exec['is_complete'] ?? false);

            if ($status === 'completed' || $isComplete) {
                if ($startedTx) {
                    $this->db->commit();
                }

                return $this->shapeResponse($requestId, $caseId, $exec);
            }
            // else: approved but incomplete => allow re-execution
        }

        // Auto-promote for system_admin
        if ($isSystemAdmin && $status !== 'approved' && $status !== 'completed') {
            $payload['status'] = 'approved';
            $payload['reviewed_by'] = $actorId;
            $payload['reviewed_at'] = gmdate('c');
            $this->persistLarPayload($tenantId, $requestId, $payload);
            $status = 'approved';
        }

        if ($status !== 'approved') {
            throw new RuntimeException('INVALID_STATUS');
        }

        // Enforce Phase 13 hard gates for AZ trustee sale possession workflow
        $this->enforceAzTrusteeSalePossessionGates(
            tenantId: $tenantId,
            caseId: $caseId,
            actorId: $actorId,
            roles: $roles,
            requestedAction: $requestedAction
        );


        $procedure = $this->resolveProcedure($requestedAction);
        $nowIso = gmdate('c');

        // --------------------------------------------------
        // 0) Execute procedure (ONCE)
        // --------------------------------------------------
        $result = $procedure->run(new ProcedureContext(
            db: $this->db,
            tenantId: $tenantId,
            caseId: $caseId,
            actorId: $actorId,
            requestId: $requestId,
            requestedAction: $requestedAction,
            nowIso: $nowIso,
            larPayload: $payload
        ));

        /*
        // AFTER procedure run: procedure result wins
        if (is_string($result['procedural_phase_key'] ?? null) && $result['procedural_phase_key'] !== '') {
            $phaseKey = (string)$result['procedural_phase_key'];
            $payload['procedural_phase_key'] = $phaseKey;
        }

        /* ============================================================
         * Phase 14.4 – Deliverable 4.2
         * Target expansion + delivery task creation
         * IMPORTANT: phaseKey is procedural; actionType is requestedAction.
         * ============================================================ */
        if ($requestedAction === 'az_demand_for_possession') {

            $targetSvc = new CaseActionTargetService($this->db);

            $hasDeliveryEvents = $this->hasAnyDeliveryEvents(
                tenantId: $tenantId,
                caseId: $caseId,
                requestId: $requestId
            );

            if (!$hasDeliveryEvents) {
                $targets = $targetSvc->replaceTargetsFromLarPayload(
                    tenantId: $tenantId,
                    caseId: $caseId,
                    requestId: $requestId,
                    phaseKey: $phaseKey,
                    actionType: $requestedAction,
                    larPayload: $payload
                );
            } else {
                $targets = $targetSvc->loadExistingTargets(
                    tenantId: $tenantId,
                    caseId: $caseId,
                    requestId: $requestId
                );
            }

            $taskSvc = new DemandDeliveryTaskService($this->db);

            $tasks = $taskSvc->ensureTasksForTargets(
                tenantId: $tenantId,
                caseId: $caseId,
                actionRequestId: $requestId,
                phaseKey: $phaseKey,
                actionType: $requestedAction,
                stateCode: 'AZ',
                targets: $targets,
                generatedDocuments: is_array($result['generated_documents'] ?? null)
                    ? (array)$result['generated_documents']
                    : []
            );

            $result['service_tasks'] = $tasks;

            if (empty($tasks)) {
                throw new RuntimeException('NO_SERVICE_TASKS_CREATED');
            }
        }


        // ✅ Correct case meta (no hardcoding)
        [$stateCode, $legalActionKey] = $this->loadCaseMetaFromRequest(
            tenantId: $tenantId,
            larPayload: $payload
        );

        $result['phase_key']   = $phaseKey;
        $result['action_type'] = $requestedAction;

        /**
         * Step 1: procedure-level completion signal
         */
        $procedureComplete = (bool)($result['is_complete'] ?? false);

        /**
         * Step 2: document expectation diagnostics (Phase 14.5)
         */
        $isComplete = $procedureComplete;

        if ($procedureComplete) {
            $missingDocuments = [];

            $st = $this->db->prepare("
                SELECT
                    ade.document_type_key,
                    ade.source,
                    ade.must_be_final,
                    ade.blocks_completion,
                    cd.id     AS case_document_id,
                    cd.status AS document_status
                FROM action_document_expectations ade
                LEFT JOIN case_documents cd
                  ON cd.case_id = :case
                 AND cd.document_type_key = ade.document_type_key
                WHERE ade.state_code = :state
                  AND ade.legal_action_key = :lak
                  AND ade.phase_key = :phase
                  AND ade.requested_action = :action
                  AND ade.required = true
                  AND ade.blocks_completion = true
            ");

            $st->execute([
                ':state'  => $stateCode,
                ':lak'    => $legalActionKey,
                ':phase'  => $phaseKey,
                ':action' => $requestedAction,
                ':case'   => $caseId,
            ]);

            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row2) {
                $isMissing = $row2['case_document_id'] === null;
                $notFinal  = ($row2['must_be_final'] ?? false) && (($row2['document_status'] ?? null) !== 'final');

                if ($isMissing || $notFinal) {
                    $missingDocuments[] = [
                        'document_type_key' => $row2['document_type_key'],
                        'source'            => $row2['source'],
                        'reason'            => $isMissing
                            ? 'document_not_uploaded'
                            : 'document_not_final',
                        'current_status'    => $row2['document_status'], // null if missing
                    ];
                }
            }

            if (!empty($missingDocuments)) {
                $isComplete = false;

                $result['is_complete'] = false;
                $result['blocked_reason'] = 'missing_required_documents';
                $result['missing_documents'] = $missingDocuments;
            }
        }

        /**
         * Step 3: persist execution bundle
         */
        $payload['execution'] = $result;

        /**
         * Step 4: status transition
         */
        if ($isComplete) {
            $payload['status']      = 'completed';
            $payload['executed_by'] = $actorId;
            $payload['executed_at'] = $nowIso;
        } else {
            $payload['status']           = 'approved';
            $payload['last_executed_by'] = $actorId;
            $payload['last_executed_at'] = $nowIso;
        }


        // Persist updated LAR payload (execution bundle + status)
        $this->persistLarPayload($tenantId, $requestId, $payload);

        // Collect emitted event types (for transition spawning)
        $eventTypes = [];
        foreach (($result['emitted_events'] ?? []) as $e) {
            if (is_array($e) && !empty($e['event_type'])) {
                $eventTypes[] = (string)$e['event_type'];
            }
        }
        $eventTypes = array_values(array_unique($eventTypes));

        error_log(
            "SPAWN_DEBUG events=" . implode(',', $eventTypes) .
            " phase=" . $phaseKey
        );

        // Debugging phase 20
        
        error_log(
            'SPAWN_DEBUG fromPhase=' . $phaseKey .
            ' events=' . implode(',', $eventTypes) .
            ' action=' . $requestedAction
        );

        // --------------------------------------------------
        // Phase / event commit (ProceduralStateService-driven)
        // --------------------------------------------------

        // 1) Persist emitted events into case_events (idempotent per action_request_id + event_type)
        foreach (($result['emitted_events'] ?? []) as $e) {
            if (!is_array($e)) continue;

            $evtType = $e['event_type'] ?? null;
            if (!is_string($evtType) || $evtType === '') continue;

            $evtPayload = $e['payload'] ?? [];
            if (!is_array($evtPayload)) $evtPayload = [];

            $evtPayload['action_request_id'] ??= $requestId;

            $chk = $this->db->prepare("
                SELECT 1
                FROM case_events
                WHERE tenant_id = :tenant
                AND case_id = :case
                AND event_type = :etype
                AND payload->>'action_request_id' = :arid
                LIMIT 1
            ");
            $chk->execute([
                ':tenant' => $tenantId,
                ':case'   => $caseId,
                ':etype'  => $evtType,
                ':arid'   => $requestId,
            ]);

            if (!$chk->fetchColumn()) {
                $eventId = $this->events->emitEvent(
                    $evtType,
                    $evtPayload,
                    $tenantId,
                    $caseId,
                    $actorId
                );

                if (!is_string($eventId) || $eventId === '') {
                    throw new RuntimeException('CASE_EVENT_WRITE_FAILED');
                }
            }
        }

        // 2) Apply legal effects via canonical event engine (replay-safe, idempotent)
        // IMPORTANT: merge any transition-signal events returned by the engine
        $engine = new ProceduralEventEngineService($this->db);

        $spawner =
            new \App\Services\Procedural\PhaseTransitionsSpawnerService(
                $this->db
            );

        foreach (($result['emitted_events'] ?? []) as $e) {

            if (!is_array($e) || empty($e['event_type'])) {
                continue;
            }

            $eventType =
                (string)$e['event_type'];

            $eventPayload =
                is_array($e['payload'] ?? null)
                ? $e['payload']
                : [];

            $engineResult = $engine->applySingleEvent(
                tenantId: $tenantId,
                caseId: $caseId,
                eventType: $eventType,
                payload: $eventPayload,
                occurredAtIso: $nowIso,
                replayedByActorId: $actorId,
                dryRun: false
            );

            $spawner->processEngineResult(
                engineResult: $engineResult,
                tenantId: $tenantId,
                caseId: $caseId,
                actorId: $actorId,
                stateCode: $stateCode
            );

        }

        $eventTypes = array_values(array_unique($eventTypes));

        error_log(
            'TRANSITION_SPAWN_COMPLETE events=' .
            implode(',', $eventTypes)
        );

 // Debug for venue_resolution

        if (!empty($eventTypes)) {
            error_log('TRANSITION_EVENTS: ' . implode(',', $eventTypes));
        }


        // Remove phase blocking except when shifting to new phase - deleted

        // 5) Optional audit event for “phase completed” (also idempotent)
        if ($isComplete && !empty($result['new_phase'])) {
            $nextPhaseKey = (string)$result['new_phase'];

            $auditType = 'phase_completed';
            $auditPayload = [
                'action_request_id'     => $requestId,
                'completed_phase_key'   => $phaseKey,
                'next_phase_key'        => $nextPhaseKey,
                'requested_action'      => $requestedAction,
            ];

            $chk = $this->db->prepare("
                SELECT 1
                  FROM case_events
                 WHERE tenant_id = :tenant
                   AND case_id = :case
                   AND event_type = :etype
                   AND payload->>'action_request_id' = :arid
                   AND payload->>'completed_phase_key' = :phase
                 LIMIT 1
            ");
            $chk->execute([
                ':tenant' => $tenantId,
                ':case'   => $caseId,
                ':etype'  => $auditType,
                ':arid'   => $requestId,
                ':phase'  => $phaseKey,
            ]);

            if (!$chk->fetchColumn()) {

                $eventId = $this->events->emitEvent(
                    $auditType,
                    $auditPayload,
                    $tenantId,
                    $caseId,
                    $actorId
                );

                if (!is_string($eventId) || $eventId === '') {
                    throw new RuntimeException('AUDIT_EVENT_WRITE_FAILED');
                }
            }
        }

        // 4) Spawn follow-on action requests
/*
        --------------------------------------------------
        DEBUG — always log collected events
        --------------------------------------------------
        */

        error_log(
            "SPAWN_DEBUG collected events=" .
            implode(',', $eventTypes) .
            " phase=" . $phaseKey
        );

        if ($startedTx) {
            $this->db->commit();
        }

        return $this->shapeResponse($requestId, $caseId, $result);
    } catch (\Throwable $e) {
        if ($startedTx && $this->db->inTransaction()) {
            $this->db->rollBack();
        }

        if ($e instanceof RuntimeException) {
            throw $e;
        }

        throw new RuntimeException('EXECUTION_FAILED: ' . $e->getMessage(), 0, $e);
    }
}


    private function loadCaseMetaFromRequest(
        string $tenantId,
        array $larPayload
    ): array {
        /*
        ------------------------------------------------------------
        Phase 20 — Deterministic legal action routing
        ------------------------------------------------------------
        AZ-only scope
        */

        /*
        ------------------------------------------------------------
        Preferred: explicit legal_action_key in payload
        ------------------------------------------------------------
        */

        if (
            is_string($larPayload['legal_action_key'] ?? null) &&
            $larPayload['legal_action_key'] !== ''
        ) {
            return ['AZ', (string)$larPayload['legal_action_key']];
        }

        /*
        ------------------------------------------------------------
        Fallback: infer from requested_action
        ------------------------------------------------------------
        */

        $requestedAction = (string)($larPayload['requested_action'] ?? '');

        if ($requestedAction === '') {
            throw new RuntimeException('LEGAL_ACTION_KEY_UNRESOLVABLE');
        }

        /*
        ------------------------------------------------------------
        Arizona Forcible Detainer workflow
        ------------------------------------------------------------
        */

        if (in_array($requestedAction, [

            // Venue / filing lifecycle
            'az_resolve_venue',
            'az_file_forcible_detainer',

            // Hearing / trial lifecycle
            'az_initial_hearing_scheduled',
            'az_initial_hearing_held',
            'az_trial_preparation',

            // Post-judgment lifecycle
            'az_request_writ',
            'az_writ_of_restitution',
            'az_writ_service_test',

            // Utility / validation hooks
            'az_verify_occupancy',

        ], true)) {
            return ['AZ', 'az_forcible_detainer'];
        }

        /*
        ------------------------------------------------------------
        Arizona Trustee Sale Possession workflow
        ------------------------------------------------------------
        */

        if ($requestedAction === 'az_demand_for_possession') {
            return ['AZ', 'az_trustee_sale_possession'];
        }

        /*
        ------------------------------------------------------------
        Safety guard
        ------------------------------------------------------------
        */

        throw new RuntimeException(
            'LEGAL_ACTION_KEY_UNRESOLVABLE:' . $requestedAction
        );
    }


private function assertNoBlockingDeadlines(
    string $tenantId,
    string $caseId,
    string $phaseKey,
    string $currentObligationKey
): void {
    $st = $this->db->prepare("
        SELECT 1
        FROM case_procedural_deadlines
        WHERE tenant_id = :tenant
          AND case_id   = :case
          AND required  = true
          AND status IN ('pending','blocked')
          AND blocks_phase_key = :phase
          AND obligation_key <> :current_obligation
        LIMIT 1
    ");

    $st->execute([
        ':tenant'             => $tenantId,
        ':case'               => $caseId,
        ':phase'              => $phaseKey,
        ':current_obligation' => $currentObligationKey,
    ]);

    if ($st->fetchColumn()) {
        throw new RuntimeException('PHASE_BLOCKED');
    }
}

    private function enforceAzTrusteeSalePossessionGates(
        string $tenantId,
        string $caseId,
        string $actorId,
        array $roles,
        string $requestedAction
    ): void {

        /*
        ------------------------------------------------------------
        Only gate Arizona workflow actions
        ------------------------------------------------------------
        */

        if (!str_starts_with($requestedAction, 'az_')) {
            return;
        }

        /*
        ------------------------------------------------------------
        Gate: purchaser must own property before demand
        ------------------------------------------------------------
        */

        if ($requestedAction === 'az_demand_for_possession') {

            if (!$this->actorOwnsCaseProperty(
                $tenantId,
                $caseId,
                $actorId
            )) {
                throw new RuntimeException('PHASE_BLOCKED');
            }

            return;
        }

        /*
        ------------------------------------------------------------
        Venue resolution must always be allowed to execute
        ------------------------------------------------------------
        */

        if ($requestedAction === 'az_resolve_venue') {
            return;
        }

        /*
        ------------------------------------------------------------
        Gate bundle for filing forcible detainer
        ------------------------------------------------------------
        */

        if ($requestedAction === 'az_file_forcible_detainer') {

            /*
            --------------------------------------------------------
            Trustee deed recorded
            --------------------------------------------------------
            */

            if (
                $this->countCaseEvents(
                    $tenantId,
                    $caseId,
                    'trustee_deed_recorded'
                ) < 1
            ) {
                throw new RuntimeException('PHASE_BLOCKED');
            }

            /*
            --------------------------------------------------------
            Demand delivered
            --------------------------------------------------------
            */

            if (
                $this->countDemandDeliveries(
                    $tenantId,
                    $caseId
                ) < 1
            ) {
                throw new RuntimeException('PHASE_BLOCKED');
            }

            /*
            --------------------------------------------------------
            Resolve correct legal action ID
            (THIS IS THE CRITICAL FIX)
            --------------------------------------------------------
            */

            $legalActionId = $this->resolveLegalActionId(
                'AZ',
                'az_forcible_detainer'
            );

            if ($legalActionId === null) {
                throw new RuntimeException(
                    'PHASE_BLOCKED:LEGAL_ACTION_CATALOG_MISSING'
                );
            }

            /*
            --------------------------------------------------------
            Venue must be resolved before filing
            --------------------------------------------------------
            */

            if (
                !$this->hasVenueAssignment(
                    $caseId,
                    $legalActionId,
                    'az_file_forcible_detainer'
                )
            ) {
                throw new RuntimeException('PHASE_BLOCKED');
            }

            /*
            --------------------------------------------------------
            Purchaser must be scoped to case
            --------------------------------------------------------
            */

            if (
                !$this->purchaserEntityIsScoped(
                    $tenantId,
                    $caseId,
                    $actorId,
                    $roles
                )
            ) {
                throw new RuntimeException('PHASE_BLOCKED');
            }

            return;
        }

        /*
        ------------------------------------------------------------
        All other AZ workflow actions
        ------------------------------------------------------------
        */

    }

    private function actorOwnsCaseProperty(
        string $tenantId,
        string $caseId,
        string $actorId
    ): bool {
        $st = $this->db->prepare("
            SELECT 1
            FROM case_properties cp
            JOIN property_relationships pr
            ON pr.property_id = cp.property_id
            WHERE cp.case_id = :case_id
            AND pr.tenant_id = :tenant_id
            AND pr.actor_id = :actor_id
            AND pr.role = 'owner'
            AND pr.ends_at IS NULL
            LIMIT 1
        ");

        $st->execute([
            ':tenant_id' => $tenantId,
            ':case_id'   => $caseId,
            ':actor_id'  => $actorId,
        ]);

        return (bool)$st->fetchColumn();
    }

    private function resolveLegalActionId(string $stateCode, string $actionKey): ?string
    {
        $st = $this->db->prepare("
            SELECT lac.id
            FROM legal_action_catalog lac
            JOIN states s ON s.id = lac.state_id
            WHERE s.code = :code
              AND lac.action_key = :action_key
            LIMIT 1
        ");
        $st->execute([':code' => $stateCode, ':action_key' => $actionKey]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? (string)$row['id'] : null;
    }

    private function hasVenueAssignment(string $caseId, string $legalActionId, string $stage): bool
    {
        $st = $this->db->prepare("
            SELECT 1
            FROM case_venue_assignments
            WHERE case_id = :case_id
              AND legal_action_id = :legal_action_id
              AND stage = :stage
            LIMIT 1
        ");
        $st->execute([
            ':case_id' => $caseId,
            ':legal_action_id' => $legalActionId,
            ':stage' => $stage,
        ]);
        return (bool)$st->fetchColumn();
    }

    private function purchaserEntityIsScoped(string $tenantId, string $caseId, string $actorId, array $roles): bool
    {
        $st = $this->db->prepare("
            SELECT payload->>'purchaser_entity_id' AS purchaser_entity_id
            FROM case_events
            WHERE tenant_id = :tenant_id
              AND case_id = :case_id
              AND event_type = 'foreclosure_sale'
            ORDER BY occurred_at DESC
            LIMIT 1
        ");
        $st->execute([':tenant_id' => $tenantId, ':case_id' => $caseId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;

        $purchaserEntityId = is_string($row['purchaser_entity_id'] ?? null) ? $row['purchaser_entity_id'] : null;
        if (!$purchaserEntityId) return false;

        $st2 = $this->db->prepare("
            SELECT tenant_id, owner_actor_id
            FROM purchaser_entities
            WHERE id = :id
            LIMIT 1
        ");
        $st2->execute([':id' => $purchaserEntityId]);
        $p = $st2->fetch(PDO::FETCH_ASSOC);
        if (!$p) return false;

        if ((string)$p['tenant_id'] !== $tenantId) return false;

        $privileged = (bool)array_intersect($roles, ['system_admin','tenant_admin','legal_admin']);
        if (!$privileged && (string)$p['owner_actor_id'] !== $actorId) return false;

        return true;
    }

    private function countDemandDeliveries(string $tenantId, string $caseId): int
    {
        $st = $this->db->prepare("
            SELECT COUNT(*) AS c
            FROM demand_delivery_events
            WHERE tenant_id = :tenant_id
              AND case_id = :case_id
        ");
        $st->execute([':tenant_id' => $tenantId, ':case_id' => $caseId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return (int)($row['c'] ?? 0);
    }

    private function countCaseEvents(string $tenantId, string $caseId, string $eventType): int
    {
        $st = $this->db->prepare("
            SELECT COUNT(*) AS c
            FROM case_events
            WHERE tenant_id = :tenant_id
              AND case_id = :case_id
              AND event_type = :event_type
        ");
        $st->execute([
            ':tenant_id' => $tenantId,
            ':case_id' => $caseId,
            ':event_type' => $eventType,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return (int)($row['c'] ?? 0);
    }

    private function resolveProcedure(string $requestedAction): ProceduralAction
    {
        return match ($requestedAction) {

            // --------------------------------------------------
            // Prefiling actions
            // --------------------------------------------------

            'az_verify_occupancy'
                => new \App\Services\Procedures\VerifyOccupancyProcedure(),

            'az_demand_for_possession'
                => new \App\Services\Procedures\DemandForPossessionProcedure(),

            /*
            --------------------------------------------------
            CRITICAL: Venue resolution
            Required before filing and for court snapshot
            --------------------------------------------------
            */

            'az_resolve_venue'
                => new \App\Services\Procedures\ResolveVenueProcedure(),

            // --------------------------------------------------
            // Filing / litigation actions
            // --------------------------------------------------

            'az_file_forcible_detainer'
                => new \App\Services\Procedures\ForcibleDetainerFilingProcedure(),

            // --------------------------------------------------
            // Post-judgment / enforcement lifecycle
            // --------------------------------------------------

            'az_request_writ',
            'az_writ_of_restitution'
                => new \App\Services\Procedures\WritOfRestitutionProcedure(),

            // --------------------------------------------------
            // Hearing / trial lifecycle
            // --------------------------------------------------

            'az_initial_hearing_scheduled'
                => new \App\Services\Procedures\InitialHearingScheduledProcedure(),

            'az_initial_hearing_held'
                => new \App\Services\Procedures\InitialHearingHeldProcedure(),

            'az_trial_preparation'
                => new \App\Services\Procedures\TrialPreparationProcedure(),

            // --------------------------------------------------
            // Test / utility hooks
            // --------------------------------------------------

            'az_writ_service_test'
                => new \App\Services\Procedures\WritServiceTestProcedure(),

            default => throw new \RuntimeException(
                'TEMPLATE_NOT_FOUND: ' . $requestedAction
            ),
        };
    }


    private function persistLarPayload(string $tenantId, string $requestId, array $payload): void
    {
        $payloadJson = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $upd = $this->db->prepare("
            UPDATE case_events
            SET
                payload = CAST(:payload AS jsonb),

                event_hash = encode(
                    digest(
                        COALESCE(previous_event_hash, '') ||
                        case_id::text ||
                        case_sequence::text ||
                        event_type ||
                        jsonb_strip_nulls(CAST(:payload AS jsonb))::text,
                        'sha256'
                    ),
                    'hex'
                )

            WHERE tenant_id = :tenant_id
            AND id = :id
        ");

        $upd->execute([
            ':payload'   => $payloadJson,
            ':tenant_id' => $tenantId,
            ':id'        => $requestId,
        ]);
    }

    private function shapeResponse(string $requestId, string $caseId, array $bundle): array
    {
        return [
            'action_request_id' => $requestId,
            'case_id' => $caseId,
            'executed_at' => $bundle['executed_at'] ?? null,
            'executed_by' => $bundle['executed_by'] ?? null,
            'generated_documents' => $bundle['generated_documents'] ?? [],
            'emitted_events' => $bundle['emitted_events'] ?? [],
            'created_deadlines' => $bundle['created_deadlines'] ?? [],
            'new_phase' => $bundle['new_phase'] ?? null,
            'is_complete' => $bundle['is_complete'] ?? false,
        ];
    }


    private function hasAnyDeliveryEvents(
        string $tenantId,
        string $caseId,
        string $requestId
    ): bool {
        $st = $this->db->prepare("
            SELECT 1
            FROM demand_delivery_events
            WHERE tenant_id = :tenant
            AND case_id = :case
            AND action_request_id = :arid
            AND service_task_id IS NOT NULL
            LIMIT 1
        ");
        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
            ':arid'   => $requestId,
        ]);
        return (bool)$st->fetchColumn();
    }

    private function uuid(): string
    {
        return (string)$this->db
            ->query("SELECT uuid_generate_v4()")
            ->fetchColumn();
    }

}
