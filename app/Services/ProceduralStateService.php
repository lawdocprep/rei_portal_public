<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class ProceduralStateService
{
    public function __construct(
        private PDO $db
    ) {}

    /**
     * Build canonical procedural state for a case.
     *
     * Option A contract:
     * - Server resolves phase state deterministically.
     * - UI does not infer blocking/current/completed.
     */
    public function build(string $tenantId, string $caseId): array
    {
        $case = $this->loadCase($tenantId, $caseId);
        if ($case === null) {
            return ['error' => 'CASE_NOT_FOUND'];
        }

        // Identify jurisdiction + legal action key (defensive field names)
        $stateCode = (string)($case['state_code'] ?? $case['state'] ?? 'AZ');
        $legalActionKey = (string)($case['legal_action_key'] ?? $case['legal_action'] ?? $case['track_key'] ?? '');

        if ($legalActionKey === '') {
            // If your cases table doesn’t carry this yet, you can also
            // derive from a case_event payload or default per tenant.
            $legalActionKey = 'az_trustee_sale_possession';
        }

        // Load authoritative graph + history + deadlines
        $transitions = $this->loadActivePhaseTransitions($tenantId, $stateCode, $legalActionKey);
        $events      = $this->loadCaseEvents($tenantId, $caseId);
        $deadlines   = $this->loadDeadlines($tenantId, $caseId);

        // Build graph nodes/edges
        $graph = $this->buildGraph($transitions);

        // Resolve best root + simulate path through observed events
        [$currentPhase, $completedPhases, $matchedSteps] =
            $this->resolveCurrentPhase($graph, $events);

        // Compute blocking map (by blocks_phase_key)
        $blocking = $this->computeBlocking($deadlines);

        // Compute phase statuses
        $phases = $this->computePhaseStatuses($graph, $currentPhase, $completedPhases, $blocking);

        // Next transitions out of current phase (explainable)
        $next = $this->computeNextTransitions($graph, $currentPhase, $blocking);

        // Flatten blocking obligations for UI quick display
        $blockingObligations = $this->flattenBlocking($blocking, $deadlines);

        return [
            'case_id' => $caseId,
            'tenant_id' => $tenantId,
            'state_code' => $stateCode,
            'legal_action_key' => $legalActionKey,

            'current_phase_key' => $currentPhase,
            'completed_phase_keys' => array_values(array_unique($completedPhases)),

            'phases' => $phases,
            'blocking_obligations' => $blockingObligations,
            'next_transitions' => $next,

            // Debug/traceability for court-grade explainability
            'explain' => [
                'matched_transition_steps' => $matchedSteps,
                'transition_count' => count($transitions),
                'event_count' => count($events),
                'deadline_count' => count($deadlines),
            ],
        ];
    }

    /* ============================================================
     * Graph build
     * ============================================================ */

    private function buildGraph(array $transitions): array
    {
        $nodes = [];
        $edgesFrom = [];
        $pred = [];

        foreach ($transitions as $t) {
            $from = (string)$t['from_phase_key'];
            $to   = (string)$t['to_requested_action']; // treated as next phase key

            $nodes[$from] = true;
            $nodes[$to]   = true;

            $edgesFrom[$from] ??= [];
            $edgesFrom[$from][] = $t;

            $pred[$to] ??= [];
            $pred[$to][$from] = true;
        }

        // Roots = nodes that never appear as "to"
        $roots = [];
        foreach (array_keys($nodes) as $n) {
            if (!isset($pred[$n]) || count($pred[$n]) === 0) {
                $roots[] = $n;
            }
        }

        // Stable order: prefer lexical if no better ordering exists
        sort($roots);
        $allNodes = array_keys($nodes);
        sort($allNodes);

        return [
            'nodes' => $allNodes,
            'roots' => $roots,
            'edges_from' => $edgesFrom,
            'predecessors' => $pred,
        ];
    }

    /**
     * Try each root and simulate advancing through the event stream by matching on_event_type.
     * Choose the root that matches the most transitions.
     */
    private function resolveCurrentPhase(array $graph, array $events): array
    {
        $roots = $graph['roots'];
        if (count($roots) === 0) {
            // Graph is degenerate; choose a stable default
            $fallback = $graph['nodes'][0] ?? 'unknown';
            return [$fallback, [], []];
        }

        // ✅ Phase 14.5 invariant: brand-new cases start in az_prefiling (not lexical root order)
        $preferredRoot = in_array('az_prefiling', $roots, true) ? 'az_prefiling' : $roots[0];

        $best = [
            'matched'   => -1,
            'current'   => $preferredRoot,
            'completed' => [],
            'steps'     => [],
        ];


        foreach ($roots as $root) {
            $cur = $root;
            $completed = [];
            $steps = [];
            $matched = 0;

            foreach ($events as $e) {
                $et = (string)$e['event_type'];
                $candidate = $this->matchTransition($graph, $cur, $et, $e['payload'] ?? []);

                if ($candidate !== null) {
                    $completed[] = $cur;
                    $steps[] = [
                        'from' => $cur,
                        'on_event_type' => $et,
                        'to' => (string)$candidate['to_requested_action'],
                        'transition_id' => (string)$candidate['id'],
                    ];
                    $cur = (string)$candidate['to_requested_action'];
                    $matched++;
                }
            }

            
            if ($matched > $best['matched']
                || (
                    $matched === $best['matched']
                    && $best['matched'] === 0
                    && $root === $preferredRoot
                )
            ) {
                $best = [
                    'matched'   => $matched,
                    'current'   => $cur,
                    'completed' => $completed,
                    'steps'     => $steps,
                ];
            }

        }

        return [$best['current'], $best['completed'], $best['steps']];
    }

    private function matchTransition(array $graph, string $fromPhase, string $eventType, array $payload): ?array
    {
        $edgesFrom = $graph['edges_from'][$fromPhase] ?? [];
        foreach ($edgesFrom as $t) {
            if ((string)$t['on_event_type'] !== $eventType) {
                continue;
            }

            // Conditions are stored as jsonb; treat as "subset match" for now.
            // If conditions is empty => passes.
            $conds = $t['conditions'] ?? [];
            if (!is_array($conds)) $conds = [];

            if ($this->conditionsPass($conds, $payload)) {
                return $t;
            }
        }
        return null;
    }

    private function conditionsPass(array $conditions, array $payload): bool
    {
        if (count($conditions) === 0) return true;

        // Minimal subset check: every key/value in conditions must exist in payload
        foreach ($conditions as $k => $v) {
            if (!array_key_exists($k, $payload)) return false;
            if ($payload[$k] !== $v) return false;
        }
        return true;
    }

    private function computeBlocking(array $deadlines): array
    {
        // blockedPhaseKey => [deadlineId => true]
        $blocked = [];

        foreach ($deadlines as $d) {
            $blocksPhase = $d['blocks_phase_key'] ?? null;
            if (!is_string($blocksPhase) || $blocksPhase === '') continue;

            $required = (bool)($d['required'] ?? true);
            if (!$required) continue;

            $status = (string)($d['status'] ?? 'pending');
            $isBlockingStatus = in_array($status, ['pending', 'overdue'], true);

            if ($isBlockingStatus) {
                $blocked[$blocksPhase] ??= [];
                $blocked[$blocksPhase][(string)$d['id']] = true;
            }
        }

        return $blocked;
    }

    private function computePhaseStatuses(
        array $graph,
        string $currentPhase,
        array $completedPhases,
        array $blocking
    ): array {
        $completedSet = array_fill_keys($completedPhases, true);
        $pred = $graph['predecessors'];

        // Simple deterministic ordering: BFS from roots + leftover nodes
        $ordered = $this->orderPhases($graph);

        $out = [];
        foreach ($ordered as $phaseKey) {
            $isCompleted = isset($completedSet[$phaseKey]);
            $isCurrent   = ($phaseKey === $currentPhase);
            $blockedByIds = array_keys($blocking[$phaseKey] ?? []);

            // Determine if prerequisites are met
            $pre = array_keys($pred[$phaseKey] ?? []);
            $preMet = true;
            foreach ($pre as $p) {
                if (!isset($completedSet[$p])) {
                    $preMet = false;
                    break;
                }
            }

            $status = 'future';
            if ($isCompleted) {
                $status = 'completed';
            } elseif ($isCurrent) {
                $status = count($blockedByIds) ? 'blocked' : 'current';
            } elseif ($preMet) {
                $status = count($blockedByIds) ? 'blocked' : 'available';
            } else {
                $status = 'future';
            }

            $out[] = [
                'key' => $phaseKey,
                'status' => $status, // completed | current | available | blocked | future
                'blocked_by_deadline_ids' => $blockedByIds,
                'predecessors' => $pre,
            ];
        }

        return $out;
    }

    private function orderPhases(array $graph): array
    {
        $roots = $graph['roots'];
        $edgesFrom = $graph['edges_from'];

        $seen = [];
        $q = $roots;
        $ordered = [];

        while (count($q)) {
            $n = array_shift($q);
            if (isset($seen[$n])) continue;
            $seen[$n] = true;
            $ordered[] = $n;

            foreach (($edgesFrom[$n] ?? []) as $t) {
                $to = (string)$t['to_requested_action'];
                if (!isset($seen[$to])) {
                    $q[] = $to;
                }
            }
        }

        // Append any disconnected nodes
        foreach ($graph['nodes'] as $n) {
            if (!isset($seen[$n])) $ordered[] = $n;
        }

        return $ordered;
    }

    private function computeNextTransitions(array $graph, string $currentPhase, array $blocking): array
    {
        $edgesFrom = $graph['edges_from'][$currentPhase] ?? [];
        $out = [];

        foreach ($edgesFrom as $t) {
            $to = (string)$t['to_requested_action'];
            $blockedBy = array_keys($blocking[$to] ?? []);

            $out[] = [
                'transition_id' => (string)$t['id'],
                'from_phase_key' => (string)$t['from_phase_key'],
                'on_event_type' => (string)$t['on_event_type'],
                'to_phase_key' => $to,
                'auto_approve' => (bool)$t['auto_approve'],
                'auto_execute' => (bool)$t['auto_execute'],
                'conditions' => is_array($t['conditions']) ? $t['conditions'] : [],
                'is_blocked' => count($blockedBy) > 0,
                'blocked_by_deadline_ids' => $blockedBy,
            ];
        }

        return $out;
    }

    private function flattenBlocking(array $blocking, array $deadlines): array
    {
        $byId = [];
        foreach ($deadlines as $d) {
            $byId[(string)$d['id']] = $d;
        }

        $out = [];
        foreach ($blocking as $phaseKey => $idSet) {
            foreach (array_keys($idSet) as $ddlId) {
                $d = $byId[$ddlId] ?? null;
                if (!is_array($d)) continue;

                $out[] = [
                    'deadline_id' => $ddlId,
                    'blocks_phase_key' => $phaseKey,
                    'obligation_key' => (string)($d['obligation_key'] ?? ''),
                    'display_name' => (string)($d['display_name'] ?? ''),
                    'due_at' => (string)($d['due_at'] ?? ''),
                    'status' => (string)($d['status'] ?? ''),
                    'required' => (bool)($d['required'] ?? true),
                ];
            }
        }

        return $out;
    }

    /* ============================================================
     * Data loaders
     * ============================================================ */

    private function loadCase(string $tenantId, string $caseId): ?array
    {
        $st = $this->db->prepare(
            "SELECT *
               FROM cases
              WHERE id = :id
                AND tenant_id = :tenant"
        );
        $st->execute([':id' => $caseId, ':tenant' => $tenantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function loadCaseEvents(string $tenantId, string $caseId): array
    {
        $st = $this->db->prepare(
            "SELECT event_type, occurred_at, payload
               FROM case_events
              WHERE tenant_id = :tenant
                AND case_id = :case
              ORDER BY occurred_at ASC"
        );
        $st->execute([':tenant' => $tenantId, ':case' => $caseId]);

        $out = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'event_type' => (string)$r['event_type'],
                'occurred_at' => (string)$r['occurred_at'],
                'payload' => $this->jsonToArray($r['payload'] ?? null),
            ];
        }
        return $out;
    }

    private function loadDeadlines(string $tenantId, string $caseId): array
    {
        $st = $this->db->prepare(
            "SELECT
                id,
                phase_key,
                obligation_key,
                display_name,
                due_at,
                required,
                blocks_phase_key,
                status,
                payload
             FROM case_procedural_deadlines
             WHERE tenant_id = :tenant
               AND case_id = :case"
        );
        $st->execute([':tenant' => $tenantId, ':case' => $caseId]);

        $out = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[] = [
                'id' => (string)$r['id'],
                'phase_key' => (string)$r['phase_key'],
                'obligation_key' => (string)$r['obligation_key'],
                'display_name' => (string)$r['display_name'],
                'due_at' => (string)$r['due_at'],
                'required' => (bool)$r['required'],
                'blocks_phase_key' => $r['blocks_phase_key'] ?? null,
                'status' => (string)$r['status'],
                'payload' => $this->jsonToArray($r['payload'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * Merge tenant overrides (tenant_id set) over global defaults (tenant_id null).
     * Prefer tenant-specific rows when lookup keys match.
     */
    private function loadActivePhaseTransitions(string $tenantId, string $stateCode, string $legalActionKey): array
    {
        $st = $this->db->prepare(
            "SELECT
                id,
                tenant_id,
                state_code,
                legal_action_key,
                from_phase_key,
                on_event_type,
                to_requested_action,
                auto_approve,
                auto_execute,
                conditions
             FROM phase_transitions
             WHERE is_active = true
               AND state_code = :state
               AND legal_action_key = :lak
               AND (tenant_id IS NULL OR tenant_id = :tenant)"
        );

        $st->execute([
            ':state' => $stateCode,
            ':lak' => $legalActionKey,
            ':tenant' => $tenantId,
        ]);

        $rows = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                'id' => (string)$r['id'],
                'tenant_id' => $r['tenant_id'],
                'state_code' => (string)$r['state_code'],
                'legal_action_key' => (string)$r['legal_action_key'],
                'from_phase_key' => (string)$r['from_phase_key'],
                'on_event_type' => (string)$r['on_event_type'],
                'to_requested_action' => (string)$r['to_requested_action'],
                'auto_approve' => (bool)$r['auto_approve'],
                'auto_execute' => (bool)$r['auto_execute'],
                'conditions' => $this->jsonToArray($r['conditions'] ?? null),
            ];
        }

        // Deduplicate: tenant-specific overrides win for the same (from_phase, on_event, to_requested_action)
        $byKey = [];
        foreach ($rows as $t) {
            $k = $t['from_phase_key'] . '|' . $t['on_event_type'] . '|' . $t['to_requested_action'];
            $isTenant = !empty($t['tenant_id']);
            if (!isset($byKey[$k])) {
                $byKey[$k] = $t;
                continue;
            }
            if ($isTenant) {
                $byKey[$k] = $t;
            }
        }

        return array_values($byKey);
    }

    private function jsonToArray($val): array
    {
        if (is_array($val)) return $val;
        if (!is_string($val) || $val === '') return [];
        $d = json_decode($val, true);
        return is_array($d) ? $d : [];
    }
}
