<?php
declare(strict_types=1);

namespace App\Services\Procedural;

use PDO;
use RuntimeException;
use App\Auth\AuthContext;
use App\Services\Events\CaseEventService;

final class PhaseTransitionsSpawnerService
{
    public function __construct(
        private PDO $db
    ) {}

    /**
     * ------------------------------------------------------------
     * Extract transition signals from procedural engine effects
     * ------------------------------------------------------------
     */
    public function extractTransitionEvents(array $engineResult): array
    {
        $events = [];

        foreach (($engineResult['effects'] ?? []) as $fx) {

            if (!is_array($fx)) {
                continue;
            }

            if (
                ($fx['type'] ?? null) === 'phase.transition.signal'
                && !empty($fx['event_type'])
                && is_string($fx['event_type'])
            ) {
                $events[] = (string)$fx['event_type'];
            }
        }

        $events = array_values(array_unique($events));

        if (!empty($events)) {
            error_log(
                'TRANSITION_SPAWNER extracted=' .
                implode(',', $events)
            );
        }

        return $events;
    }

    /**
     * ------------------------------------------------------------
     * Main orchestration entrypoint
     * ------------------------------------------------------------
     */
    public function processEngineResult(
        array $engineResult,
        string $tenantId,
        string $caseId,
        string $actorId,
        string $stateCode
    ): void {

        $transitionEvents =
            $this->extractTransitionEvents(
                $engineResult
            );

        if (empty($transitionEvents)) {
            error_log(
                'TRANSITION_SPAWNER no events — nothing to spawn'
            );
            return;
        }

        /*
        ------------------------------------------------------------
        Resolve current procedural state
        ------------------------------------------------------------
        */

        $stateSvc =
            new \App\Services\ProceduralStateService(
                $this->db
            );

        $state =
            $stateSvc->build(
                $tenantId,
                $caseId
            );

        $fromPhaseKey =
            (string)($state['current_phase_key'] ?? '');

        if ($fromPhaseKey === '') {
            error_log(
                'TRANSITION_SPAWNER missing phase'
            );
            return;
        }

        /*
        Resolve legal action key
        */

        $st = $this->db->prepare("
            SELECT action_type
            FROM legal_actions
            WHERE tenant_id = :tenant
            AND case_id = :case
            ORDER BY created_at DESC
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
        ]);

        $legalActionKey =
            (string)($st->fetchColumn() ?: '');

        /*
        Fallback before legal_action.initiated exists
        */

        if ($legalActionKey === '') {

            $st = $this->db->prepare("
                SELECT payload->>'requested_action'
                FROM case_events
                WHERE tenant_id = :tenant
                AND case_id = :case
                AND event_type = 'legal_action_request'
                ORDER BY occurred_at DESC
                LIMIT 1
            ");

            $st->execute([
                ':tenant' => $tenantId,
                ':case'   => $caseId,
            ]);

            $requestedAction =
                (string)($st->fetchColumn() ?: '');

            $legalActionKey = match ($requestedAction) {
                'az_demand_for_possession',
                'az_resolve_venue',
                'az_file_forcible_detainer'
                    => 'az_forcible_detainer',

                default => '',
            };

            error_log(
                'TRANSITION_SPAWNER fallback legal action=' .
                $legalActionKey
            );
        }

        if ($legalActionKey === '') {
            error_log(
                'TRANSITION_SPAWNER missing legal action'
            );
            return;
        }

        /*
        ------------------------------------------------------------
        Spawn transitions
        ------------------------------------------------------------
        */

        $this->spawnTransitions(
            tenantId: $tenantId,
            caseId: $caseId,
            actorId: $actorId,
            stateCode: $stateCode,
            legalActionKey: $legalActionKey,
            fromPhaseKey: $fromPhaseKey,
            transitionEvents: $transitionEvents
        );
    }

    /**
     * ------------------------------------------------------------
     * Core transition spawning logic
     * ------------------------------------------------------------
     */
    private function spawnTransitions(
        string $tenantId,
        string $caseId,
        string $actorId,
        string $stateCode,
        string $legalActionKey,
        string $fromPhaseKey,
        array $transitionEvents
    ): void {

        if (empty($transitionEvents)) {
            return;
        }

        $nowIso = gmdate('c');

        foreach ($transitionEvents as $eventType) {

            error_log(
                'TRANSITION_LOOKUP state=' . $stateCode .
                ' legal_action=' . $legalActionKey .
                ' from_phase=' . $fromPhaseKey .
                ' event=' . $eventType
            );

            $st = $this->db->prepare("
                SELECT
                    to_requested_action,
                    auto_approve,
                    auto_execute
                FROM phase_transitions
                WHERE is_active = true
                AND state_code = :state
                AND legal_action_key = :lak
                AND from_phase_key = :phase
                AND on_event_type = :event
            ");

            $st->execute([
                ':state' => $stateCode,
                ':lak'   => $legalActionKey,
                ':phase' => $fromPhaseKey,
                ':event' => $eventType,
            ]);

            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                error_log(
                    'TRANSITION_SPAWNER no transition for ' .
                    $eventType
                );

                continue;
            }

            foreach ($rows as $row) {

                $requestedAction =
                    (string)$row['to_requested_action'];

                if ($requestedAction === '') {
                    continue;
                }

                if ($this->requestExists(
                    tenantId: $tenantId,
                    caseId: $caseId,
                    requestedAction: $requestedAction
                )) {
                    error_log(
                        'TRANSITION_SPAWNER duplicate skipped ' .
                        $requestedAction
                    );

                    continue;
                }

                $spawnedRequestId = $this->createRequest(
                    tenantId: $tenantId,
                    caseId: $caseId,
                    actorId: $actorId,
                    requestedAction: $requestedAction,
                    autoApprove: (bool)$row['auto_approve'],
                    nowIso: $nowIso
                );

                error_log(
                    'TRANSITION_SPAWNER request_id=' .
                    $spawnedRequestId .
                    ' requested_action=' .
                    $requestedAction
                );

                error_log(
                    'TRANSITION_SPAWNER created ' .
                    $requestedAction
                );

                if ((bool)$row['auto_execute']) {

                    error_log(
                        'TRANSITION_SPAWNER auto-executing ' .
                        $requestedAction .
                        ' request_id=' .
                        $spawnedRequestId
                    );

                    $executor =
                        new \App\Services\CaseActionExecutorService(
                            $this->db
                        );

                    $systemCtx = new \App\Auth\AuthContext(
                        tenantId: $tenantId,
                        actorId: $actorId,
                        actorType: 'service',
                        serviceContext: 'automation',
                        scopes: ['case_actions:execute'],
                        roles: ['system_admin'],
                        jti: null,
                        claims: [
                            'source' => 'PhaseTransitionsSpawnerService',
                            'auto_execute' => true,
                        ]
                    );

                    $executor->execute(
                        $spawnedRequestId,
                        $systemCtx
                    );
                }
            }
        }
    }

    /**
     * ------------------------------------------------------------
     * Request existence guard
     * ------------------------------------------------------------
     */
    private function requestExists(
        string $tenantId,
        string $caseId,
        string $requestedAction
    ): bool {

        $st = $this->db->prepare("
            SELECT 1
            FROM case_events
            WHERE tenant_id = :tenant
            AND case_id = :case
            AND event_type = 'legal_action_request'
            AND payload->>'requested_action' = :action
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
            ':action' => $requestedAction,
        ]);

        return (bool)$st->fetchColumn();
    }

    /**
     * ------------------------------------------------------------
     * Create legal action request
     * ------------------------------------------------------------
     */
    private function createRequest(
        string $tenantId,
        string $caseId,
        string $actorId,
        string $requestedAction,
        bool $autoApprove,
        string $nowIso
    ): string {

        $status = $autoApprove
            ? 'approved'
            : 'pending';

        $spawnToken = bin2hex(random_bytes(16));

        $payload = [
            'requested_action'       => $requestedAction,
            'status'                 => $status,
            'title'                  => $this->defaultTitleForAction($requestedAction),
            'metadata'               => new \stdClass(),
            'auto_spawned'           => true,
            'auto_spawned_at'        => $nowIso,
            'transition_spawn_token' => $spawnToken,
        ];

        if ($autoApprove) {
            $payload['auto_approved_by'] = $actorId;
            $payload['auto_approved_at'] = $nowIso;
        }

        $events = new CaseEventService($this->db);

        $events->emitEvent(
            'legal_action_request',
            $payload,
            $tenantId,
            $caseId,
            $actorId
        );

        $st = $this->db->prepare("
            SELECT id
            FROM case_events
            WHERE tenant_id = :tenant
            AND case_id = :case
            AND event_type = 'legal_action_request'
            AND payload->>'transition_spawn_token' = :spawn_token
            ORDER BY occurred_at DESC, created_at DESC
            LIMIT 1
        ");

        $st->execute([
            ':tenant'      => $tenantId,
            ':case'        => $caseId,
            ':spawn_token' => $spawnToken,
        ]);

        $requestId = (string)($st->fetchColumn() ?: '');

        if ($requestId === '') {
            throw new RuntimeException('LEGAL_ACTION_REQUEST_CREATE_FAILED');
        }

        error_log(
            'TRANSITION_SPAWNER request_id=' .
            $requestId .
            ' requested_action=' .
            $requestedAction
        );

        return $requestId;
    }



    /**
     * ------------------------------------------------------------
     * UUID helper
     * ------------------------------------------------------------
     */
    private function generateUuid(): string
    {
        return $this->db
            ->query("SELECT uuid_generate_v4()")
            ->fetchColumn();
    }

    private function defaultTitleForAction(string $action): string
    {
        return match ($action) {
            'az_resolve_venue' => 'Resolve Venue',
            'az_demand_for_possession' => 'Demand for Possession',
            'az_file_forcible_detainer' => 'File Forcible Detainer',
            default => ucwords(str_replace('_', ' ', $action)),
        };
    }
}