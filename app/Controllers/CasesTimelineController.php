<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;

final class CasesTimelineController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * GET /cases/{case_id}/timeline
     * ============================================================ */
    public function getTimeline(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        try {
            $tenantId = (string) $ctx->tenantId;
            $caseId = $req['params']['case_id'] ?? $req['params']['id'] ?? null;

            if (!is_string($caseId) || $caseId === '') {
                return $this->badRequest('Missing case_id');
            }

            // 1) Load case (tenant isolation)
            $case = $this->loadCase($caseId, $tenantId);
            if ($case === null) {
                return $this->notFound('Case not found');
            }

            // 2) Load tenant timeline policy + permissions
            $policy = $this->loadTenantPolicy($tenantId);
            $phasePerms = $this->loadTenantPhasePermissions($tenantId);

            // Visible phases: if empty => allow all
            $visiblePhases = $policy['visible_phases'] ?? [];
            if (!is_array($visiblePhases)) $visiblePhases = [];

            // Feature flag: include derived deadlines only if explicitly enabled
            $defaultFilters = $policy['default_filters'] ?? [];
            if (!is_array($defaultFilters)) $defaultFilters = [];

            $includeDerivedDeadlines =
                (bool)($req['query']['include_derived_deadlines'] ?? $defaultFilters['include_derived_deadlines'] ?? false);

            $items = [];

            // 3) Derived case milestones (stable)
            if (!empty($case['created_at'])) {
                $items[] = $this->makeItem([
                    'id'          => 'evt_case_filed',
                    'type'        => 'case_event',
                    'event_type'  => 'case_filed',
                    'title'       => 'Case Filed',
                    'description' => null,
                    'occurred_at' => (string)$case['created_at'],
                    'status'      => 'completed',
                    'metadata'    => [
                        'source' => 'derived',
                        'actor'  => ['actor_id' => 'system', 'display' => 'System'],
                    ],
                ]);
            }

            if (!empty($case['closed_at'])) {
                $items[] = $this->makeItem([
                    'id'          => 'evt_case_closed',
                    'type'        => 'case_event',
                    'event_type'  => 'case_closed',
                    'title'       => 'Case Closed',
                    'description' => null,
                    'occurred_at' => (string)$case['closed_at'],
                    'status'      => 'completed',
                    'metadata'    => [
                        'source' => 'derived',
                        'actor'  => ['actor_id' => 'system', 'display' => 'System'],
                    ],
                ]);
            }

            // 4) Persisted case events (canonical store = case_events.payload)
            $caseEvents = $this->loadCaseEvents($caseId, $tenantId);
            foreach ($caseEvents as $evt) {
                $payload = $evt['payload'] ?? [];
                if (!is_array($payload)) $payload = [];

                $title = (string)($payload['title'] ?? $payload['label'] ?? $this->humanizeEventType((string)$evt['event_type']));
                $desc  = isset($payload['description']) ? (string)$payload['description'] : null;

                $items[] = $this->makeItem([
                    'id'          => 'ce_' . (string)$evt['id'],
                    'type'        => 'case_event',
                    'event_type'  => (string)$evt['event_type'],
                    'title'       => $title,
                    'description' => $desc,
                    'occurred_at' => (string)$evt['occurred_at'],
                    'status'      => (string)($payload['status'] ?? 'completed'),
                    // Put payload into metadata; keep `payload` nested too for callers that expect it
                    'metadata'    => array_merge($payload, [
                        'source'   => 'case_events',
                        'actor_id' => $evt['actor_id'] ?? null,
                        'payload'  => $payload,
                    ]),
                ]);
            }

            // 4.5) Procedural action requests (stable timeline anchors)
            $actions = $this->loadProceduralActionRequests($caseId, $tenantId);
            foreach ($actions as $a) {
                $items[] = $this->makeItem([
                    'id'          => 'pa_' . $a['action_request_id'],
                    'type'        => 'procedural_action',
                    'event_type'  => $a['action_type'],
                    'title'       => $this->humanizeEnum($a['action_type']),
                    'description' => null,
                    'occurred_at' => $a['created_at'],
                    'status'      => $a['completed_at'] !== null
                        ? 'completed'
                        : ($a['has_service_activity'] ? 'in_progress' : 'pending'),
                    'metadata'    => [
                        'source'            => 'procedural_action_request',
                        'action_request_id' => $a['action_request_id'],
                        'phase_key'         => $a['phase_key'],
                        'target_count'      => $a['target_count'],
                        'payload'           => $a['payload'],
                    ],
                ]);
            }

            // 5) Process service events (+ evidence)
            $serviceEvents = $this->loadProcessServiceEvents($caseId, $tenantId);
            foreach ($serviceEvents as $evt) {
                $method  = $evt['method'] ?? null;
                $outcome = $evt['outcome'] ?? null;

                $title = 'Process Service';
                if (is_string($method) && $method !== '') {
                    $title .= ' • ' . $this->humanizeEnum($method);
                }

                $descParts = [];
                if (is_string($outcome) && $outcome !== '') $descParts[] = 'Outcome: ' . $this->humanizeEnum($outcome);
                if (!empty($evt['metadata']['served_by'])) $descParts[] = 'Served by: ' . (string)$evt['metadata']['served_by'];
                if (!empty($evt['metadata']['served_name'])) $descParts[] = 'Served name: ' . (string)$evt['metadata']['served_name'];

                // Try to expose a single "proof_document_id" for UI drill-down
                $proofDocumentId = null;
                foreach (($evt['evidence'] ?? []) as $e) {
                    if (!empty($e['id']) && is_string($e['id'])) {
                        $proofDocumentId = $e['id'];
                        break;
                    }
                }

                $items[] = $this->makeItem([
                    'id'          => 'pse_' . (string)$evt['id'],
                    'type'        => 'process_service',
                    'event_type'  => 'process_service',
                    'title'       => $title,
                    'description' => count($descParts) ? implode(' • ', $descParts) : null,
                    'occurred_at' => (string)($evt['occurred_at'] ?? ''),
                    'status'      => ($outcome === 'completed' || $outcome === 'served') ? 'completed' : 'pending',
                    'metadata'    => [
                        'source'            => 'process_service_events',
                        'service_method'    => $method,
                        'outcome'           => $outcome,
                        'metadata'          => $evt['metadata'] ?? [],
                        'evidence'          => $evt['evidence'] ?? [],
                        'proof_document_id' => $proofDocumentId,
                    ],
                ]);
            }

            // 6) Persisted procedural deadlines (court/judge/AI ingested) — authoritative
            $deadlines = $this->loadCaseProceduralDeadlines($caseId, $tenantId);
            foreach ($deadlines as $d) {
                $dueAt = (string)($d['due_at'] ?? '');
                if ($dueAt === '') continue;

                $payload = $this->jsonToArray($d['payload'] ?? null);

                $items[] = $this->makeItem([
                    'id'            => (string)$d['id'],
                    'type'          => 'deadline',
                    'event_type'    => (string)($d['obligation_key'] ?? 'deadline'),
                    'title'         => (string)($d['display_name'] ?? 'Deadline'),
                    'description'   => null,
                    'occurred_at'   => $dueAt, // use due date for grouping/sorting in linear view
                    'effective_date'=> $this->toDateOnly($dueAt),
                    'status'        => $this->mapDeadlineUiStatus((string)($d['status'] ?? 'pending'), $dueAt),
                    'metadata'      => [
                        'source'           => 'case_procedural_deadlines',
                        // IMPORTANT: used by hybrid phase resolution
                        'phase_key'        => (string)($d['phase_key'] ?? ''),
                        'obligation_key'   => (string)($d['obligation_key'] ?? ''),
                        'required'         => (bool)($d['required'] ?? true),
                        'blocks_phase_key' => $d['blocks_phase_key'] ?? null,
                        'completed_at'     => $d['completed_at'] ?? null,
                        'completed_by'     => $d['completed_by'] ?? null,
                        'payload'          => $payload,
                        // Convenience surface for UI
                        'source_kind'       => $payload['source'] ?? null,
                        'source_document_id'=> $payload['source_document_id'] ?? null,
                        // If explicit satisfaction exists, surface it
                        'satisfaction'      => is_array($payload['satisfaction'] ?? null) ? $payload['satisfaction'] : null,
                    ],
                ]);
            }

            // 7) OPTIONAL derived deadlines (legacy / generic civil rules) — OFF by default
            if ($includeDerivedDeadlines) {
                foreach ($this->computeDeadlines($case, $items) as $deadline) {
                    $items[] = $this->makeItem([
                        'id'            => (string)$deadline['id'],
                        'type'          => 'deadline',
                        'event_type'    => (string)($deadline['deadline_type'] ?? 'deadline'),
                        'title'         => (string)($deadline['label'] ?? 'Deadline'),
                        'description'   => null,
                        'occurred_at'   => (string)($deadline['created_at'] ?? $deadline['due_at'] ?? ''),
                        'effective_date'=> $this->toDateOnly((string)($deadline['due_at'] ?? '')),
                        'status'        => ($deadline['status'] ?? null) === 'missed' ? 'overdue' : 'pending',
                        'metadata'      => [
                            'source'        => 'derived_deadline',
                            'deadline_type' => $deadline['deadline_type'] ?? null,
                            'due_at'        => $deadline['due_at'] ?? null,
                            'jurisdiction'  => $deadline['jurisdiction'] ?? null,
                            'rule_source'   => $deadline['rule_source'] ?? null,
                            'derived_from'  => $deadline['derived_from'] ?? null,
                            'calculation'   => $deadline['calculation'] ?? null,
                        ],
                    ]);
                }
            }

            // 8) Phase resolution + policy enforcement (hard filter)
            // NOTE: We resolve the phase per item, then apply visibility + permissions.
            $filtered = [];
            foreach ($items as $item) {
                $phaseKey = $this->resolvePhaseKey($item);

                if (!empty($visiblePhases) && !in_array($phaseKey, $visiblePhases, true)) {
                    continue;
                }

                if (!$this->canViewPhase($phaseKey, $ctx, $phasePerms)) {
                    continue;
                }

                $item['metadata'] ??= [];
                $item['metadata']['phase'] = [
                    'key'   => $phaseKey,
                    'label' => $this->phaseLabel($phaseKey),
                    'order' => $this->phaseOrder($phaseKey),
                ];

                $filtered[] = $item;
            }

            // 9) Sort timeline (chronological ascending)
            usort($filtered, function ($a, $b) {
                $ta = !empty($a['effective_date'])
                    ? ((string)$a['effective_date']) . 'T00:00:00Z'
                    : (string)($a['occurred_at'] ?? '');
                $tb = !empty($b['effective_date'])
                    ? ((string)$b['effective_date']) . 'T00:00:00Z'
                    : (string)($b['occurred_at'] ?? '');

                $cmp = strcmp($ta, $tb);
                if ($cmp !== 0) return $cmp;

                return strcmp((string)$a['id'], (string)$b['id']);
            });


            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => [
                    'case_id'  => $caseId,
                    'timezone' => $case['timezone'] ?? 'UTC',
                    'items'    => $filtered,
                    'warnings' => [
                        'derived_deadlines_enabled' => $includeDerivedDeadlines,
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            error_log('[CasesTimelineController] ' . $e->getMessage());
            error_log($e->getTraceAsString());

            return [
                'status' => 500,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => [
                    'error' => 'TIMELINE_INTERNAL_ERROR',
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    private function loadProceduralActionRequests(string $caseId, string $tenantId): array
    {
        /**
         * This query treats procedural action requests as the canonical anchor.
         *
         * Assumptions (validated in Phase 14.5):
         * - Action requests are event-backed (case_events)
         * - Targets are materialized at request time
         * - Completion is signaled via canonical completion events
         *
         * We intentionally DO NOT depend on legal_actions.
         */

        $sql = "
            WITH action_requests AS (
                SELECT
                    ce.id                AS event_id,
                    ce.occurred_at       AS created_at,
                    ce.payload->>'action_request_id' AS action_request_id,
                    ce.payload->>'action_type'       AS action_type,
                    ce.payload->>'phase_key'         AS phase_key,
                    ce.payload                            AS payload
                FROM case_events ce
                WHERE ce.case_id   = :case_id
                AND ce.tenant_id = :tenant_id
                AND ce.event_type = 'legal_action_request.created'
            ),

            completion_events AS (
                SELECT
                    ce.payload->>'action_request_id' AS action_request_id,
                    ce.occurred_at                   AS completed_at
                FROM case_events ce
                WHERE ce.case_id   = :case_id
                AND ce.tenant_id = :tenant_id
                AND ce.event_type IN (
                    'prefiling.demand_for_possession.completed'
                )
            ),

            target_counts AS (
                SELECT
                    t.action_request_id,
                    COUNT(*) AS target_count
                FROM legal_action_request_targets t
                WHERE t.case_id   = :case_id
                AND t.tenant_id = :tenant_id
                GROUP BY t.action_request_id
            ),

            service_activity AS (
                SELECT DISTINCT
                    t.action_request_id
                FROM demand_delivery_tasks t
                WHERE t.case_id   = :case_id
                AND t.tenant_id = :tenant_id
            )

            SELECT
                ar.action_request_id,
                ar.action_type,
                COALESCE(ar.phase_key, 'az_prefiling') AS phase_key,
                ar.created_at,
                ce.completed_at,
                COALESCE(tc.target_count, 0)         AS target_count,
                (sa.action_request_id IS NOT NULL)   AS has_service_activity,
                ar.payload
            FROM action_requests ar
            LEFT JOIN completion_events ce
                ON ce.action_request_id = ar.action_request_id
            LEFT JOIN target_counts tc
                ON tc.action_request_id = ar.action_request_id
            LEFT JOIN service_activity sa
                ON sa.action_request_id = ar.action_request_id
            ORDER BY ar.created_at ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'case_id'   => $caseId,
            'tenant_id' => $tenantId,
        ]);

        $out = [];

        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'action_request_id'    => (string)$r['action_request_id'],
                'action_type'          => (string)$r['action_type'],
                'phase_key'            => (string)$r['phase_key'],
                'created_at'           => (string)$r['created_at'],
                'completed_at'         => $r['completed_at'] !== null ? (string)$r['completed_at'] : null,
                'target_count'         => (int)$r['target_count'],
                'has_service_activity' => (bool)$r['has_service_activity'],
                'payload'              => $this->jsonToArray($r['payload'] ?? null),
            ];
        }

        return $out;
    }


    /* ============================================================
     * Tenant policy + permissions
     * ============================================================ */

    private function loadTenantPolicy(string $tenantId): array
    {
        $st = $this->db->prepare(
            "SELECT
                tenant_id,
                default_view_mode,
                visible_phases,
                default_expanded_phases,
                default_filters
             FROM tenant_timeline_policies
             WHERE tenant_id = :tenant_id"
        );
        $st->execute(['tenant_id' => $tenantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'tenant_id' => $tenantId,
                'default_view_mode' => 'procedural',
                'visible_phases' => [],
                'default_expanded_phases' => [],
                'default_filters' => [],
            ];
        }

        return [
            'tenant_id' => (string)$row['tenant_id'],
            'default_view_mode' => (string)($row['default_view_mode'] ?? 'procedural'),
            'visible_phases' => $this->pgTextArrayToPhpArray($row['visible_phases'] ?? null),
            'default_expanded_phases' => $this->pgTextArrayToPhpArray($row['default_expanded_phases'] ?? null),
            'default_filters' => $this->jsonToArray($row['default_filters'] ?? null),
        ];
    }

    /**
     * Returns:
     *   [
     *     'phase_key' => ['can_view_roles' => [...], 'can_create_roles' => [...], ...],
     *     ...
     *   ]
     */
    private function loadTenantPhasePermissions(string $tenantId): array
    {
        $st = $this->db->prepare(
            "SELECT
                phase,
                can_view_roles,
                can_create_roles,
                can_edit_roles,
                can_delete_roles
             FROM tenant_phase_permissions
             WHERE tenant_id = :tenant_id"
        );
        $st->execute(['tenant_id' => $tenantId]);

        $out = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $phase = (string)$r['phase'];
            $out[$phase] = [
                'can_view_roles'   => $this->pgTextArrayToPhpArray($r['can_view_roles'] ?? null),
                'can_create_roles' => $this->pgTextArrayToPhpArray($r['can_create_roles'] ?? null),
                'can_edit_roles'   => $this->pgTextArrayToPhpArray($r['can_edit_roles'] ?? null),
                'can_delete_roles' => $this->pgTextArrayToPhpArray($r['can_delete_roles'] ?? null),
            ];
        }

        return $out;
    }

    private function canViewPhase(string $phaseKey, AuthContext $ctx, array $phasePerms): bool
    {
        if (!isset($phasePerms[$phaseKey])) {
            return true;
        }

        $allowedRoles = $phasePerms[$phaseKey]['can_view_roles'] ?? [];
        if (!is_array($allowedRoles) || count($allowedRoles) === 0) {
            return true;
        }

        $userRoles = $ctx->roles ?? [];
        foreach ($allowedRoles as $r) {
            if (in_array((string)$r, $userRoles, true)) {
                return true;
            }
        }

        return false;
    }

    /* ============================================================
     * Phase resolver (HYBRID)
     * - If metadata.phase_key exists, it wins (procedural engine).
     * - Otherwise fall back to legacy civil heuristics.
     * ============================================================ */

    private function resolvePhaseKey(array $item): string
    {
        $type      = (string)($item['type'] ?? '');
        $eventType = (string)($item['event_type'] ?? '');

        $metadata = $item['metadata'] ?? null;
        $payload = [];

        if (is_array($metadata)) {
            // Prefer nested payload if present
            if (isset($metadata['payload']) && is_array($metadata['payload'])) {
                $payload = $metadata['payload'];
            } else {
                $payload = $metadata;
            }

            // PROCEDURAL OVERRIDE: explicit metadata phase key wins
            if (!empty($metadata['phase_key']) && is_string($metadata['phase_key'])) {
                return (string)$metadata['phase_key'];
            }

            // Legacy explicit override
            if (!empty($payload['phase_override']) && is_string($payload['phase_override'])) {
                return (string)$payload['phase_override'];
            }
        }

        // Deadlines: if no explicit phase_key, fall back conservatively
        if ($type === 'deadline') {
            if (str_contains($eventType, 'answer')) return 'response';
            return 'response';
        }

        // Process service default lane (unless overridden)
        if ($type === 'process_service') {
            return 'response';
        }

        // Case milestones
        if ($eventType === 'case_filed') return 'filing';
        if ($eventType === 'case_closed') return 'post_judgment';

        // Manual/legal kind mapping
        if (is_array($payload) && !empty($payload['legal_kind']) && is_string($payload['legal_kind'])) {
            $kind = $payload['legal_kind'];

            if ($kind === 'transaction') return 'transactions';
            if ($kind === 'appeal') return 'appeals';
            if ($kind === 'trial') return 'trial';
            if ($kind === 'judgment') return 'judgment';
            if ($kind === 'service') return 'response';

            if ($kind === 'discovery') return 'discovery';
            if (in_array($kind, ['rfa', 'rfp', 'interrogatory', 'deposition'], true)) return 'discovery';

            if ($kind === 'motion') return 'motions';
        }

        // Event type mapping (case_events.event_type)
        $et = $eventType;

        if (preg_match('/^(property_acquired|property_disposed|deed_recorded|lease_signed|tenant_move_in|tenant_move_out|foreclosure_sale)$/', $et)) {
            return 'transactions';
        }

        if (preg_match('/^(intake_|admin_|compliance_|conflict_check|engagement_)/', $et)) {
            return 'internal_ops';
        }
        if (preg_match('/^(demand_|notice_)/', $et)) {
            return 'pre_filing';
        }

        if (preg_match('/(complaint_filed|petition_filed|case_filed)/', $et)) return 'filing';
        if (preg_match('/(service_of_process|appearance_filed|answer_filed)/', $et)) return 'response';

        if (preg_match('/^(rfp_|interrogatories_|requests_for_admission_|deposition_)/', $et)) return 'discovery';

        if (preg_match('/^(motion_for_default|motion_for_default_judgment|motion_to_vacate_default|motion_to_vacate_judgment)$/', $et)) {
            return 'judgment';
        }

        if (preg_match('/^motion_/', $et) || str_contains($et, 'motion')) return 'motions';

        if (preg_match('/^(trial_|verdict_)/', $et)) return 'trial';

        if (preg_match('/(judgment_entered|order_entered|default_entered|verdict_entered)/', $et)) return 'judgment';

        if (preg_match('/^(writ_|eviction_|garnishment_)/', $et)) return 'post_judgment';

        if (preg_match('/^(appeal_|appellate_|mandate_)/', $et)) return 'appeals';

        return 'motions';
    }

    private function phaseOrder(string $phaseKey): int
    {
        return match ($phaseKey) {
            'transactions' => 0,
            'internal_ops' => 1,
            'pre_filing'   => 2,
            'filing'       => 3,
            'response'     => 4,
            'discovery'    => 5,
            'motions'      => 6,
            'trial'        => 7,
            'judgment'     => 8,
            'post_judgment'=> 9,
            'appeals'      => 10,
            default        => 99,
        };
    }

    private function phaseLabel(string $phaseKey): string
    {
        return match ($phaseKey) {
            'transactions' => 'Transactions',
            'internal_ops' => 'Internal Ops',
            'pre_filing'   => 'Pre-Filing',
            'filing'       => 'Filing',
            'response'     => 'Response',
            'discovery'    => 'Discovery',
            'motions'      => 'Motions',
            'trial'        => 'Trial',
            'judgment'     => 'Judgment',
            'post_judgment'=> 'Post-Judgment',
            'appeals'      => 'Appeals',
            default        => 'Other',
        };
    }

    /* ============================================================
     * Data loaders
     * ============================================================ */

    private function loadCase(string $caseId, string $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT *
               FROM cases
              WHERE id = :id
                AND tenant_id = :tenant'
        );
        $stmt->execute(['id' => $caseId, 'tenant' => $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function loadCaseEvents(string $caseId, string $tenantId): array
    {
        $st = $this->db->prepare(
            'SELECT id, tenant_id, case_id, event_type, occurred_at, actor_id, payload, created_at
               FROM case_events
              WHERE case_id = :case_id
                AND tenant_id = :tenant
              ORDER BY occurred_at ASC'
        );

        $st->execute([
            'case_id' => $caseId,
            'tenant'  => $tenantId,
        ]);

        $out = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $payload = $this->jsonToArray($r['payload'] ?? null);
            $out[] = [
                'id' => (string)$r['id'],
                'event_type' => (string)$r['event_type'],
                'occurred_at' => (string)$r['occurred_at'],
                'actor_id' => $r['actor_id'] ?? null,
                'payload' => $payload,
            ];
        }
        return $out;
    }

    private function loadProcessServiceEvents(string $caseId, string $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                pse.id,
                pse.service_method,
                pse.outcome,
                pse.service_date AS occurred_at,
                pse.metadata
            FROM process_service_events pse
            JOIN cases c ON c.id = pse.case_id
            WHERE pse.case_id = :case_id
              AND c.tenant_id = :tenant'
        );

        $stmt->execute([
            'case_id' => $caseId,
            'tenant'  => $tenantId,
        ]);

        $out = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'id' => (string)$row['id'],
                'method' => $row['service_method'] ?? null,
                'outcome' => $row['outcome'] ?? null,
                'occurred_at' => $row['occurred_at'] ?? null,
                'metadata' => $this->jsonToArray($row['metadata'] ?? null),
                'evidence' => $this->loadEvidence((string)$row['id']),
            ];
        }

        return $out;
    }

    private function loadEvidence(string $serviceEventId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                d.id,
                d.document_type,
                d.status,
                d.created_at
             FROM documents d
             WHERE d.process_service_event_id = :id'
        );

        $stmt->execute(['id' => $serviceEventId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function loadCaseProceduralDeadlines(string $caseId, string $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                id,
                case_id,
                phase_key,
                obligation_key,
                display_name,
                due_at,
                required,
                blocks_phase_key,
                status,
                completed_at,
                completed_by,
                payload
             FROM case_procedural_deadlines
             WHERE tenant_id = :tenant
               AND case_id = :case
             ORDER BY due_at ASC"
        );

        $stmt->execute(['tenant' => $tenantId, 'case' => $caseId]);

        $out = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $r;
        }
        return $out;
    }

    /* ============================================================
     * Derived deadlines (legacy / generic civil rules)
     * ============================================================ */

    private function computeDeadlines(array $case, array $timelineItems): array
    {
        // Minimal example: Answer Due = 16 days after service completed
        $deadlines = [];

        $serviceCompleted = null;
        foreach ($timelineItems as $item) {
            if (
                ($item['type'] ?? null) === 'process_service'
                && (
                    (($item['metadata']['outcome'] ?? null) === 'completed')
                    || (($item['metadata']['outcome'] ?? null) === 'served')
                )
            ) {
                $serviceCompleted = $item;
                break;
            }
        }

        if ($serviceCompleted !== null) {
            $occurredAt = (string)($serviceCompleted['occurred_at'] ?? '');
            if ($occurredAt !== '') {
                $due = (new \DateTimeImmutable($occurredAt))
                    ->modify('+16 days')
                    ->setTime(23, 59, 59);

                $deadlines[] = [
                    'id' => 'ddl_answer_due_' . substr((string)$serviceCompleted['id'], 0, 8),
                    'type' => 'deadline',
                    'deadline_type' => 'answer_due',
                    'label' => 'Answer Due',
                    'due_at' => $due->format(DATE_ATOM),
                    'status' => ($due < new \DateTimeImmutable()) ? 'missed' : 'pending',
                    'jurisdiction' => [
                        'state' => $case['state'] ?? null,
                        'court_name' => $case['court_name'] ?? null,
                    ],
                    'rule_source' => [
                        'authority' => 'court_rule',
                        'citation' => 'ARCP 12(a)',
                    ],
                    'derived_from' => [
                        'trigger_event_type' => 'service_completed',
                        'trigger_event_id' => (string)$serviceCompleted['id'],
                        'trigger_occurred_at' => $occurredAt,
                    ],
                    'calculation' => [
                        'method' => 'fixed_days',
                        'days' => 16,
                        'notes' => 'Derived from service completion date.',
                    ],
                    'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ];
            }
        }

        return $deadlines;
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function makeItem(array $in): array
    {
        // Output contract aligned to ui/src/api/cases.timeline.ts
        return [
            'id' => (string)$in['id'],
            'type' => (string)$in['type'], // case_event | process_service | deadline | document | note | system
            'title' => (string)$in['title'],
            'description' => $in['description'] ?? null,
            'occurred_at' => (string)($in['occurred_at'] ?? ''),
            'effective_date' => $in['effective_date'] ?? null,
            'status' => $in['status'] ?? null, // completed | pending | overdue | cancelled
            'metadata' => $in['metadata'] ?? [],
            'event_type' => $in['event_type'] ?? null,
        ];
    }

    private function mapDeadlineUiStatus(string $dbStatus, string $dueAtIso): string
    {
        // DB status: pending|completed|waived|not_applicable
        // UI status: pending|overdue|completed
        if ($dbStatus === 'completed' || $dbStatus === 'waived' || $dbStatus === 'not_applicable') {
            return 'completed';
        }

        try {
            $due = new \DateTimeImmutable($dueAtIso);
            if ($due < new \DateTimeImmutable('now')) return 'overdue';
        } catch (\Throwable $e) {
            // ignore parse errors, keep pending
        }

        return 'pending';
    }

    private function jsonToArray(mixed $v): array
    {
        if (is_array($v)) return $v;
        if (!is_string($v) || $v === '') return [];
        $d = json_decode($v, true);
        return is_array($d) ? $d : [];
    }

    /**
     * Postgres text[] may arrive as "{a,b}" or already decoded depending on PDO/driver.
     */
    private function pgTextArrayToPhpArray(mixed $v): array
    {
        if (is_array($v)) return array_values(array_filter(array_map('strval', $v)));
        if (!is_string($v) || $v === '') return [];

        $s = trim($v);
        if ($s === '{}' ) return [];
        if ($s[0] === '{' && str_ends_with($s, '}')) {
            $inner = substr($s, 1, -1);
            if (trim($inner) === '') return [];
            $parts = str_getcsv($inner);
            return array_values(array_filter(array_map(function ($x) {
                $x = trim((string)$x);
                $x = trim($x, "\"");
                return $x;
            }, $parts), fn($x) => $x !== ''));
        }

        return [$s];
    }

    private function toDateOnly(string $iso): ?string
    {
        if ($iso === '') return null;
        $t = strtotime($iso);
        if ($t === false) return null;
        return gmdate('Y-m-d', $t);
    }

    private function humanizeEnum(string $v): string
    {
        $v = str_replace(['_', '-'], ' ', $v);
        $v = strtolower($v);
        return ucwords($v);
    }

    private function humanizeEventType(string $v): string
    {
        if ($v === '') return 'Event';
        return $this->humanizeEnum($v);
    }

    private function unauthorized(): array
    {
        return [
            'status' => 401,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => ['error' => 'AUTH_REQUIRED'],
        ];
    }

    private function badRequest(string $msg): array
    {
        return [
            'status' => 400,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => ['error' => $msg],
        ];
    }

    private function notFound(string $msg): array
    {
        return [
            'status' => 404,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => ['error' => $msg],
        ];
    }
}
