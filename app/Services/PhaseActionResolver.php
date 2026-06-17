<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class PhaseActionResolver
{
    public function __construct(
        private PDO $db,
        private ProceduralStateService $proceduralState
    ) {}

    /**
     * Phase 14.4:
     * - Phase-level deadline blocking
     * - In-phase prerequisite ordering (required+blocking)
     * - Satisfaction sources:
     *   (A) system: completed legal_action_request events (case_events)
     *   (B) manual: case_prerequisite_compliance (+ evidence rows)
     *
     * HARD RULE (14.4): if an action requires delivery proof, "system-completed"
     * is only treated as satisfied if demand_delivery_events contains a valid proof
     * record for the completing action_request_id:
     *   - certified_mail/courier -> tracking_number required
     *   - personal_delivery/posting -> proof_document_id required
     *
     * Manual satisfaction is validated against procedural_action_requirements and
     * requires evidence docs for personal/posting (attestation/affidavit) and tracking
     * for mail/courier.
     */
    public function resolve(string $tenantId, string $caseId, array $roles = []): array
    {
        $state = $this->proceduralState->build($tenantId, $caseId);
        if (isset($state['error'])) {
            return ['error' => $state['error']];
        }

        $currentPhase = (string)($state['current_phase_key'] ?? '');
        $stateCode    = (string)($state['state_code'] ?? 'AZ');

        // Phase-level deadline blocking (from ProceduralStateService)
        $deadlineBlocks = $this->deadlineBlocksCurrentPhase($state);

        // Phase policy
        $phaseActions = $this->loadPhaseActions($currentPhase, $stateCode);

        // LARs by action type (includes request_id for proof joins)
        $larByAction = $this->loadLarByAction($tenantId, $caseId);

        // Manual compliance (+ evidence count)
        $manual = $this->loadManualCompliance($tenantId, $caseId);

        // Requirements for actions in this phase (jurisdiction filtered)
        $requirements = $this->loadActionRequirements($currentPhase, $stateCode);

        // Satisfaction helper
        $isSatisfied = function (string $phaseKey, string $actionType) use ($tenantId, $caseId, $larByAction, $manual, $requirements): array {
            $req = $requirements[$actionType] ?? null;

            // 1) System-completed LAR (with Phase 14.4 proof enforcement when required)
            $lar = $larByAction[$actionType] ?? null;
            if (is_array($lar) && ($lar['status'] ?? null) === 'completed') {
                $requestId = (string)($lar['request_id'] ?? '');

                // Phase 14.3 pragmatic default: no requirement row => completed is sufficient
                if ($req === null) {
                    return ['ok' => true, 'source' => 'system', 'missing' => []];
                }

                // Delivery proof enforcement (14.4)
                if ((bool)($req['requires_delivery'] ?? false)) {
                    $proof = $this->systemDeliveryProofOk($tenantId, $caseId, $requestId, $req);

                    if (!$proof['ok']) {
                        // Allow manual compliance to satisfy even if the system record is incomplete
                        $mc = $manual[$phaseKey][$actionType] ?? null;
                        if ($mc !== null) {
                            $manualSat = $this->manualSatisfactionCheck($mc, $req);
                            if ($manualSat['ok']) {
                                return ['ok' => true, 'source' => 'manual', 'missing' => []];
                            }
                        }

                        return ['ok' => false, 'source' => 'system', 'missing' => $proof['missing']];
                    }
                }

                // Evidence requirement enforcement (14.4 pragmatic):
                // If requires_evidence is true, we require manual evidence rows unless your codebase
                // has a system evidence table. This prevents "completed" from bypassing evidence rules.
                if ((bool)($req['requires_evidence'] ?? false)) {
                    $mc = $manual[$phaseKey][$actionType] ?? null;
                    if ($mc === null) {
                        return ['ok' => false, 'source' => 'system', 'missing' => ['MISSING_EVIDENCE_DOCUMENT']];
                    }
                    $manualSat = $this->manualSatisfactionCheck($mc, $req);
                    if (!$manualSat['ok']) {
                        return ['ok' => false, 'source' => 'system', 'missing' => $manualSat['missing']];
                    }
                }

                return ['ok' => true, 'source' => 'system', 'missing' => []];
            }

            // 2) Manual compliance
            $mc = $manual[$phaseKey][$actionType] ?? null;
            if ($mc === null) {
                return ['ok' => false, 'source' => null, 'missing' => ['NO_COMPLIANCE_RECORD']];
            }

            // No requirements row => Phase 14.3 default (manual record sufficient)
            if ($req === null) {
                return ['ok' => true, 'source' => 'manual', 'missing' => []];
            }

            $manualSat = $this->manualSatisfactionCheck($mc, $req);
            return ['ok' => $manualSat['ok'], 'source' => 'manual', 'missing' => $manualSat['missing']];
        };

        // Determine in-phase prerequisites: required + blocking
        $gating = [];
        foreach ($phaseActions as $a) {
            $required = (bool)($a['required'] ?? false);
            $blocking = (bool)($a['blocking'] ?? false);
            if ($required && $blocking) {
                $gating[] = [
                    'action_type' => (string)$a['action_type'],
                    'order' => (int)$a['order'],
                ];
            }
        }

        $allowed = [];
        $blocked = [];
        $mandatory = [];
        $autoGen = [];

        foreach ($phaseActions as $a) {
            $actionType = (string)$a['action_type'];
            $order      = (int)$a['order'];
            $required   = (bool)$a['required'];
            $auto       = (bool)$a['auto_generate'];

            $selfSat = $isSatisfied($currentPhase, $actionType);

            // Determine prerequisites earlier in order that must be satisfied first
            $missingPrereqs = [];
            foreach ($gating as $p) {
                if ($p['order'] >= $order) continue;

                $preType = (string)$p['action_type'];
                $preSat  = $isSatisfied($currentPhase, $preType);
                if (!$preSat['ok']) {
                    $missingPrereqs[] = [
                        'action_type' => $preType,
                        'missing' => $preSat['missing'],
                        'source' => $preSat['source'],
                    ];
                }
            }

            // Phase-level deadline blocks everything
            if ($deadlineBlocks !== null) {
                $blocked[] = [
                    'action_type' => $actionType,
                    'reason' => 'PHASE_BLOCKED_BY_DEADLINES',
                    'details' => $deadlineBlocks,
                ];
                if ($required && !$selfSat['ok']) {
                    $mandatory[] = $actionType;
                    if ($auto) $autoGen[] = $actionType;
                }
                continue;
            }

            // Missing prerequisites block this action
            if (count($missingPrereqs) > 0) {
                $blocked[] = [
                    'action_type' => $actionType,
                    'reason' => 'MISSING_PREREQUISITE',
                    'details' => [
                        'missing_prerequisites' => $missingPrereqs,
                    ],
                ];
                if ($required && !$selfSat['ok']) {
                    $mandatory[] = $actionType;
                    if ($auto) $autoGen[] = $actionType;
                }
                continue;
            }

            // Otherwise allow it
            $allowed[] = [
                'action_type' => $actionType,
                'already_satisfied' => $selfSat['ok'],
                'satisfied_source' => $selfSat['source'],
            ];

            if ($required && !$selfSat['ok']) {
                $mandatory[] = $actionType;
                if ($auto) $autoGen[] = $actionType;
            }
        }

        $mandatory = array_values(array_unique($mandatory));
        $autoGen   = array_values(array_unique($autoGen));

        return [
            'case_id' => $caseId,
            'tenant_id' => $tenantId,
            'state_code' => $stateCode,

            'current_phase_key' => $currentPhase,
            'completed_phase_keys' => $state['completed_phase_keys'] ?? [],

            'allowed_actions' => $allowed,
            'blocked_actions' => $blocked,
            'mandatory_next_actions' => $mandatory,
            'auto_generate_actions' => $autoGen,

            'blocking_obligations' => $state['blocking_obligations'] ?? [],
            'explain' => $state['explain'] ?? [],
        ];
    }

    /* ============================================================
     * Phase 14.4 Proof / Evidence Checks
     * ============================================================ */

    /**
     * Manual compliance check against procedural_action_requirements.
     *
     * Phase 14.4 rules:
     * - If requires_evidence: evidence_count >= min_documents (default 1)
     * - If requires_delivery:
     *   - delivery_method required
     *   - must be allowed if allowed_delivery_methods provided
     *   - if requires_tracking_number OR method is certified_mail/courier: tracking required
     *   - if method is personal_delivery/posting: require at least 1 evidence doc
     *     (attestation/affidavit upload) even if requires_tracking_number is false
     */
    private function manualSatisfactionCheck(array $mc, array $req): array
    {
        $missing = [];

        if ((bool)($req['requires_evidence'] ?? false)) {
            $minDocs = (int)($req['min_documents'] ?? 1);
            $docCount = (int)($mc['evidence_count'] ?? 0);
            if ($docCount < $minDocs) {
                $missing[] = 'MISSING_EVIDENCE_DOCUMENT';
            }
        }

        if ((bool)($req['requires_delivery'] ?? false)) {
            $method = $mc['delivery_method'] ?? null;
            if (!is_string($method) || trim($method) === '') {
                $missing[] = 'MISSING_DELIVERY_METHOD';
            } else {
                $allowed = $req['allowed_delivery_methods'] ?? null;
                if (is_array($allowed) && count($allowed) > 0) {
                    if (!in_array($method, $allowed, true)) {
                        $missing[] = 'DELIVERY_METHOD_NOT_ALLOWED';
                    }
                }

                $needsTracking = (bool)($req['requires_tracking_number'] ?? false)
                    || in_array($method, ['certified_mail', 'courier'], true);

                if ($needsTracking) {
                    $trk = $mc['delivery_tracking_number'] ?? null;
                    if (!is_string($trk) || trim($trk) === '') {
                        $missing[] = 'MISSING_TRACKING_NUMBER';
                    }
                } else {
                    if (in_array($method, ['personal_delivery', 'posting'], true)) {
                        $docCount = (int)($mc['evidence_count'] ?? 0);
                        $min = max(1, (int)($req['min_documents'] ?? 1));
                        if ($docCount < $min) {
                            $missing[] = 'MISSING_ATTESTATION_DOCUMENT';
                        }
                    }
                }
            }
        }

        return ['ok' => count($missing) === 0, 'missing' => $missing];
    }

    /**
     * System delivery proof check: enforce demand_delivery_events proof for the LAR.
     *
     * Relies on schema:
     * - demand_delivery_events.tenant_id, case_id, action_request_id
     * - demand_delivery_events.delivery_method
     * - demand_delivery_events.tracking_number (for certified_mail/courier)
     * - demand_delivery_events.proof_document_id (FK to documents.id for personal/posting)
     * - demand_delivery_events.delivered_at (ordering)
     */
    private function systemDeliveryProofOk(string $tenantId, string $caseId, string $requestId, array $req): array
    {
        if ($requestId === '') {
            return ['ok' => false, 'missing' => ['MISSING_ACTION_REQUEST_ID']];
        }

        // Phase 14.4 Deliverable 4.2: if we have per-occupant targets, require proof for
        // ALL required targets (not just a single latest delivery row).
        $actionType = is_string($req['action_type'] ?? null) ? (string)$req['action_type'] : '';

        $targets = [];
        if ($actionType !== '') {
            $stT = $this->db->prepare("
                SELECT id, required
                FROM case_occupant_action_targets
                WHERE tenant_id = :tenant
                  AND case_id = :case_id
                  AND action_request_id = :rid
                  AND action_type = :act
                ORDER BY created_at ASC
            ");
            $stT->execute([
                ':tenant' => $tenantId,
                ':case_id' => $caseId,
                ':rid' => $requestId,
                ':act' => $actionType,
            ]);
            $targets = $stT->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $allowed = $req['allowed_delivery_methods'] ?? null;

        // Helper: validate a single delivery row
        $validateRow = function (array $row) use ($allowed, $req): array {
            $missing = [];

            $status = (string)($row['delivery_status'] ?? '');
            if ($status !== 'delivered') {
                return ['ok' => false, 'missing' => ['DELIVERY_NOT_COMPLETED']];
            }

            $method = (string)($row['delivery_method'] ?? '');
            if ($method === '') {
                return ['ok' => false, 'missing' => ['MISSING_DELIVERY_METHOD']];
            }

            if (is_array($allowed) && count($allowed) > 0) {
                if (!in_array($method, $allowed, true)) {
                    return ['ok' => false, 'missing' => ['DELIVERY_METHOD_NOT_ALLOWED']];
                }
            }

            $needsTracking = (bool)($req['requires_tracking_number'] ?? false)
                || in_array($method, ['certified_mail', 'courier'], true);

            if ($needsTracking) {
                $trk = $row['tracking_number'] ?? null;
                if (!is_string($trk) || trim($trk) === '' || strtoupper(trim($trk)) === 'PENDING') {
                    $missing[] = 'MISSING_TRACKING_NUMBER';
                }
                return ['ok' => count($missing) === 0, 'missing' => $missing];
            }

            if (in_array($method, ['personal_service', 'personal_delivery', 'posting'], true)) {
                // v3 schema uses proof_case_document_id
                $proof = $row['proof_case_document_id'] ?? ($row['proof_document_id'] ?? null);
                if (!is_string($proof) || trim($proof) === '') {
                    $missing[] = 'MISSING_PROOF_DOCUMENT';
                }
                return ['ok' => count($missing) === 0, 'missing' => $missing];
            }

            return ['ok' => false, 'missing' => ['UNKNOWN_DELIVERY_METHOD']];
        };

        // If we have required targets, validate each.
        if (count($targets) > 0) {
            $missingAll = [];

            $stD = $this->db->prepare("
                SELECT
                    delivery_method,
                    proof_document_id,
                    proof_case_document_id,
                    tracking_number,
                    delivery_status
                FROM demand_delivery_events
                WHERE tenant_id = :tenant
                  AND case_id = :case_id
                  AND action_request_id = :rid
                  AND target_id = :tid
                ORDER BY created_at DESC
                LIMIT 1
            ");

            foreach ($targets as $t) {
                $required = (bool)($t['required'] ?? false);
                if (!$required) continue;

                $tid = (string)($t['id'] ?? '');
                if ($tid === '') {
                    $missingAll[] = 'MISSING_TARGET_ID';
                    continue;
                }

                $stD->execute([
                    ':tenant' => $tenantId,
                    ':case_id' => $caseId,
                    ':rid' => $requestId,
                    ':tid' => $tid,
                ]);
                $row = $stD->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    $missingAll[] = 'MISSING_DELIVERY_EVENT';
                    continue;
                }

                $ok = $validateRow($row);
                if (!$ok['ok']) {
                    foreach (($ok['missing'] ?? []) as $m) {
                        $missingAll[] = (string)$m;
                    }
                }
            }

            $missingAll = array_values(array_unique($missingAll));
            return ['ok' => count($missingAll) === 0, 'missing' => $missingAll];
        }

        // Fallback: legacy behavior (single delivery row)
        $st = $this->db->prepare("
            SELECT
                delivery_method,
                proof_document_id,
                proof_case_document_id,
                tracking_number,
                delivery_status
            FROM demand_delivery_events
            WHERE tenant_id = :tenant_id
              AND case_id = :case_id
              AND action_request_id = :rid
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $st->execute([
            ':tenant_id' => $tenantId,
            ':case_id' => $caseId,
            ':rid' => $requestId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'missing' => ['MISSING_DELIVERY_EVENT']];
        }

        $out = $validateRow($row);
        return ['ok' => (bool)$out['ok'], 'missing' => $out['missing'] ?? []];
    }

    /* ============================================================
     * Loaders
     * ============================================================ */

    private function loadPhaseActions(string $phaseKey, string $stateCode): array
    {
        $st = $this->db->prepare("
            SELECT
                phase_key,
                action_type,
                required,
                auto_generate,
                blocking,
                \"order\",
                jurisdiction_state,
                jurisdiction_county,
                court_type
            FROM procedural_phase_actions
            WHERE phase_key = :phase_key
              AND (jurisdiction_state IS NULL OR jurisdiction_state = :state_code)
            ORDER BY \"order\" ASC, action_type ASC
        ");
        $st->execute([
            ':phase_key' => $phaseKey,
            ':state_code' => $stateCode,
        ]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * action_type => ['status'=>string, 'request_id'=>uuid]
     */
    private function loadLarByAction(string $tenantId, string $caseId): array
    {
        $st = $this->db->prepare("
            SELECT id, payload
            FROM case_events
            WHERE tenant_id = :tenant_id
              AND case_id = :case_id
              AND event_type = 'legal_action_request'
        ");
        $st->execute([
            ':tenant_id' => $tenantId,
            ':case_id' => $caseId,
        ]);

        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $payload = json_decode((string)($row['payload'] ?? '{}'), true) ?: [];
            $act = $payload['requested_action'] ?? null;
            if (!is_string($act) || $act === '') continue;

            $status = $payload['status'] ?? 'pending';
            if (!is_string($status)) $status = 'pending';

            $rank = $this->statusRank($status);
            $prev = $out[$act] ?? null;

            if ($prev === null || $rank > $this->statusRank((string)($prev['status'] ?? 'pending'))) {
                $out[$act] = [
                    'status' => $status,
                    'request_id' => (string)($row['id'] ?? ''),
                ];
            }
        }

        return $out;
    }

    private function statusRank(string $s): int
    {
        return match ($s) {
            'completed' => 3,
            'approved'  => 2,
            'pending'   => 1,
            'rejected'  => 0,
            default     => 0,
        };
    }

    /**
     * phase_key => action_type => compliance row + evidence_count
     */
    private function loadManualCompliance(string $tenantId, string $caseId): array
    {
        $st = $this->db->prepare("
            SELECT
                id,
                phase_key,
                action_type,
                occurred_at,
                delivery_method,
                delivery_tracking_number
            FROM case_prerequisite_compliance
            WHERE tenant_id = :tenant_id
              AND case_id = :case_id
            ORDER BY attested_at DESC
        ");
        $st->execute([
            ':tenant_id' => $tenantId,
            ':case_id' => $caseId,
        ]);

        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) === 0) return [];

        $ids = array_map(fn($r) => (string)$r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));

        $st2 = $this->db->prepare("
            SELECT compliance_id, COUNT(*)::int AS n
            FROM case_prerequisite_compliance_evidence
            WHERE tenant_id = ?
              AND compliance_id IN ($in)
            GROUP BY compliance_id
        ");
        $st2->execute(array_merge([$tenantId], $ids));

        $counts = [];
        while ($r = $st2->fetch(PDO::FETCH_ASSOC)) {
            $counts[(string)$r['compliance_id']] = (int)($r['n'] ?? 0);
        }

        // Keep the newest compliance per (phase_key, action_type)
        $out = [];
        foreach ($rows as $r) {
            $phase = (string)$r['phase_key'];
            $act   = (string)$r['action_type'];
            $id    = (string)$r['id'];
            if (!isset($out[$phase][$act])) {
                $r['evidence_count'] = $counts[$id] ?? 0;
                $out[$phase][$act] = $r;
            }
        }

        return $out;
    }

    /**
     * action_type => requirement row (jurisdiction_state preferred over NULL).
     */
    private function loadActionRequirements(string $phaseKey, string $stateCode): array
    {
        $st = $this->db->prepare("
            SELECT
                action_type,
                requires_evidence,
                min_documents,
                requires_delivery,
                allowed_delivery_methods,
                requires_tracking_number,
                jurisdiction_state
            FROM procedural_action_requirements
            WHERE phase_key = :phase_key
              AND (jurisdiction_state = :state_code OR jurisdiction_state IS NULL)
            ORDER BY (jurisdiction_state IS NULL) ASC
        ");
        $st->execute([
            ':phase_key' => $phaseKey,
            ':state_code' => $stateCode,
        ]);

        $out = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $act = (string)$r['action_type'];
            if (isset($out[$act])) continue;

            $r['allowed_delivery_methods'] = $this->normalizePgTextArray($r['allowed_delivery_methods'] ?? null);

            // Phase 14.4: if policy allows certified_mail but doesn't list courier yet,
            // treat courier as an equivalent tracking-based method (unless policy is null/empty).
            if (is_array($r['allowed_delivery_methods']) && count($r['allowed_delivery_methods']) > 0) {
                if (in_array('certified_mail', $r['allowed_delivery_methods'], true)
                    && !in_array('courier', $r['allowed_delivery_methods'], true)) {
                    $r['allowed_delivery_methods'][] = 'courier';
                }
            }

            $out[$act] = $r;
        }

        return $out;
    }

    private function normalizePgTextArray(mixed $v): ?array
    {
        if ($v === null) return null;
        if (is_array($v)) return $v;
        if (!is_string($v)) return null;

        $s = trim($v);
        if ($s === '{}') return [];

        if (strlen($s) >= 2 && $s[0] === '{' && $s[strlen($s) - 1] === '}') {
            $inner = substr($s, 1, -1);
            if ($inner === '') return [];
            $parts = explode(',', $inner);
            $parts = array_map(fn($p) => trim($p, "\" "), $parts);
            $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
            return $parts;
        }

        return null;
    }

    /**
     * If current phase is blocked by required deadlines, return details object, else null.
     */
    private function deadlineBlocksCurrentPhase(array $proceduralState): ?array
    {
        $cur = (string)($proceduralState['current_phase_key'] ?? '');
        $obs = $proceduralState['blocking_obligations'] ?? [];
        if (!is_array($obs) || $cur === '') return null;

        $blocking = [];
        foreach ($obs as $o) {
            if (!is_array($o)) continue;
            $blocks = $o['blocks_phase_key'] ?? null;
            if ($blocks === $cur) {
                $blocking[] = $o;
            }
        }

        if (count($blocking) === 0) return null;

        return [
            'phase_key' => $cur,
            'blocking_obligations' => $blocking,
        ];
    }
}
