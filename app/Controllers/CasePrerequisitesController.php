<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Auth\CapabilityResolver;
use App\Services\PhaseActionResolver;
use App\Services\ProceduralStateService;
use PDO;
use Throwable;

final class CasePrerequisitesController
{
    private const CAP_ATTEST = 'procedure:attest_prerequisite';

    public function __construct(
        private PDO $db
    ) {}

    /**
     * POST /cases/{id}/prerequisites/{action_type}/satisfy
     */
    public function satisfy(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $tenantId = (string)$ctx->tenantId;
        $actorId  = (string)$ctx->actorId;
        $roles    = $ctx->roles ?? [];

        $caseId = $req['params']['id'] ?? $req['params']['case_id'] ?? null;
        $actionType = $req['params']['action_type'] ?? null;

        if (!is_string($caseId) || !$this->isUuid($caseId)) {
            return $this->json(400, ['error' => 'INVALID_CASE_ID']);
        }
        if (!is_string($actionType) || $actionType === '' || !$this->isActionKey($actionType)) {
            return $this->json(400, ['error' => 'INVALID_ACTION_TYPE']);
        }

        // ------------------------------------------------------------
        // Authorization: roles OR delegated capability
        // ------------------------------------------------------------
        $roleAllowed = (bool)array_intersect($roles, [
            'system_admin','tenant_admin','legal_admin','attorney','paralegal','pro_se',
        ]);

        $capAllowed = false;
        if (!$roleAllowed) {
            $capResolver = new CapabilityResolver($this->db);
            $capAllowed = $capResolver->hasCapability(
                tenantId: $tenantId,
                caseId: $caseId,
                actorId: $actorId,
                capability: self::CAP_ATTEST
            );
        }

        if (!$roleAllowed && !$capAllowed) {
            return $this->json(403, ['error' => 'FORBIDDEN']);
        }

        // ------------------------------------------------------------
        // Body
        // ------------------------------------------------------------
        $body = $req['body'] ?? [];
        if (!is_array($body)) $body = [];

        $phaseKey = $body['phase_key'] ?? null;
        if (!is_string($phaseKey) || $phaseKey === '') {
            return $this->json(400, ['error' => 'PHASE_KEY_REQUIRED']);
        }

        $occurredAt = $body['occurred_at'] ?? null;
        if (!is_string($occurredAt) || $occurredAt === '' || !$this->isIsoTimestamp($occurredAt)) {
            return $this->json(400, ['error' => 'INVALID_OCCURRED_AT']);
        }

        $attestation = $body['attestation'] ?? null;
        if (!is_string($attestation) || trim($attestation) === '') {
            return $this->json(400, ['error' => 'ATTESTATION_REQUIRED']);
        }

        $delivery = is_array($body['delivery'] ?? null) ? $body['delivery'] : null;
        $evidence = is_array($body['evidence'] ?? null) ? $body['evidence'] : null;

        $deliveryMethod = null;
        $deliveryRecipientName = null;
        $deliveryAddress = null;
        $deliveryTrackingNumber = null;
        $deliveryServerName = null;

        if (is_array($delivery)) {
            $deliveryMethod = isset($delivery['method']) && is_string($delivery['method']) ? trim($delivery['method']) : null;
            $deliveryRecipientName = isset($delivery['recipient_name']) && is_string($delivery['recipient_name']) ? trim($delivery['recipient_name']) : null;
            $deliveryAddress = isset($delivery['address']) && is_string($delivery['address']) ? trim($delivery['address']) : null;
            $deliveryTrackingNumber = isset($delivery['tracking_number']) && is_string($delivery['tracking_number']) ? trim($delivery['tracking_number']) : null;
            $deliveryServerName = isset($delivery['server_name']) && is_string($delivery['server_name']) ? trim($delivery['server_name']) : null;
        }

        $docs = [];
        if (is_array($evidence) && isset($evidence['documents']) && is_array($evidence['documents'])) {
            foreach ($evidence['documents'] as $d) {
                if (!is_array($d)) continue;

                $docId = $d['document_id'] ?? null;             // documents.id
                $verId = $d['document_version_id'] ?? null;     // optional document_versions.id

                if (!is_string($docId) || !$this->isUuid($docId)) continue;
                if ($verId !== null && (!is_string($verId) || !$this->isUuid($verId))) continue;

                $docs[] = ['document_id' => $docId, 'document_version_id' => $verId];
            }
        }

        // ------------------------------------------------------------
        // Determine jurisdiction + current phase
        // ------------------------------------------------------------
        $procedural = new ProceduralStateService($this->db);
        $ps = $procedural->build($tenantId, $caseId);
        if (isset($ps['error'])) {
            if ($ps['error'] === 'CASE_NOT_FOUND') return $this->json(404, ['error' => 'CASE_NOT_FOUND']);
            return $this->json(400, ['error' => (string)$ps['error']]);
        }

        $stateCode = (string)($ps['state_code'] ?? 'AZ');
        $currentPhase = (string)($ps['current_phase_key'] ?? '');

        if ($currentPhase !== '' && $phaseKey !== $currentPhase) {
            return $this->json(409, [
                'error' => 'PHASE_MISMATCH',
                'details' => [
                    'current_phase_key' => $currentPhase,
                    'provided_phase_key' => $phaseKey,
                ],
            ]);
        }

        // ------------------------------------------------------------
        // Ensure action exists in phase policy
        // ------------------------------------------------------------
        if (!$this->actionExistsInPhasePolicy($phaseKey, $actionType, $stateCode)) {
            return $this->json(409, [
                'error' => 'ACTION_NOT_IN_PHASE_POLICY',
                'details' => [
                    'phase_key' => $phaseKey,
                    'action_type' => $actionType,
                    'state_code' => $stateCode,
                ],
            ]);
        }

        // ------------------------------------------------------------
        // Requirements enforcement (Phase 14.4)
        // ------------------------------------------------------------
        $reqRow = $this->loadRequirement($phaseKey, $actionType, $stateCode);
        if ($reqRow !== null) {
            $missing = [];

            $allowedMethods = $this->normalizePgTextArray($reqRow['allowed_delivery_methods'] ?? null);

            $requiresEvidence = (bool)$reqRow['requires_evidence'];
            $minDocs = (int)($reqRow['min_documents'] ?? 1);

            $requiresDelivery = (bool)$reqRow['requires_delivery'];
            $requiresTrackingFlag = (bool)($reqRow['requires_tracking_number'] ?? false);

            // Evidence base rule
            if ($requiresEvidence) {
                if (count($docs) < max(1, $minDocs)) {
                    $missing[] = 'MISSING_EVIDENCE_DOCUMENT';
                }
            }

            if ($requiresDelivery) {
                if ($deliveryMethod === null || $deliveryMethod === '') {
                    $missing[] = 'MISSING_DELIVERY_METHOD';
                } else {
                    if (is_array($allowedMethods) && count($allowedMethods) > 0 && !in_array($deliveryMethod, $allowedMethods, true)) {
                        $missing[] = 'DELIVERY_METHOD_NOT_ALLOWED';
                    }

                    // Phase 14.4: tracking required for certified_mail/courier even if flag is false
                    $needsTracking = $requiresTrackingFlag || in_array($deliveryMethod, ['certified_mail','courier'], true);
                    if ($needsTracking) {
                        if ($deliveryTrackingNumber === null || $deliveryTrackingNumber === '') {
                            $missing[] = 'MISSING_TRACKING_NUMBER';
                        }
                    } else {
                        // Phase 14.4: for personal_delivery/posting, require at least 1 evidence doc (attestation/affidavit)
                        if (in_array($deliveryMethod, ['personal_delivery','posting'], true)) {
                            if (count($docs) < max(1, $minDocs)) {
                                $missing[] = 'MISSING_ATTESTATION_DOCUMENT';
                            }
                        }
                    }
                }
            }

            if (count($missing) > 0) {
                return $this->json(409, [
                    'error' => 'PREREQUISITE_VALIDATION_FAILED',
                    'details' => [
                        'missing' => $missing,
                        'phase_key' => $phaseKey,
                        'action_type' => $actionType,
                    ],
                ]);
            }
        }

        // ------------------------------------------------------------
        // Validate evidence documents belong to the case (Phase 14.4 correct join)
        // docs[].document_id is documents.id, which must be reachable via:
        // case_documents.current_version_id -> document_versions.document_id
        // ------------------------------------------------------------
        foreach ($docs as $d) {
            if (!$this->documentBelongsToCaseByDocumentsId($tenantId, $caseId, $d['document_id'])) {
                return $this->json(409, [
                    'error' => 'EVIDENCE_DOCUMENT_NOT_IN_CASE',
                    'details' => ['document_id' => $d['document_id']],
                ]);
            }
        }

        // ------------------------------------------------------------
        // Persist compliance + evidence links
        // ------------------------------------------------------------
        $complianceId = null;

        try {
            $this->db->beginTransaction();

            $st = $this->db->prepare("
                INSERT INTO case_prerequisite_compliance (
                    tenant_id, case_id, phase_key, action_type,
                    occurred_at,
                    delivery_method, delivery_recipient_name, delivery_address,
                    delivery_tracking_number, delivery_server_name,
                    notes,
                    attested_by_actor_id, attestation_text
                )
                VALUES (
                    :tenant, :case_id, :phase_key, :action_type,
                    :occurred_at,
                    :delivery_method, :delivery_recipient_name, :delivery_address,
                    :delivery_tracking_number, :delivery_server_name,
                    :notes,
                    :actor, :attestation
                )
                RETURNING id
            ");

            $st->execute([
                ':tenant' => $tenantId,
                ':case_id' => $caseId,
                ':phase_key' => $phaseKey,
                ':action_type' => $actionType,
                ':occurred_at' => $occurredAt,

                ':delivery_method' => $deliveryMethod,
                ':delivery_recipient_name' => $deliveryRecipientName,
                ':delivery_address' => $deliveryAddress,
                ':delivery_tracking_number' => $deliveryTrackingNumber,
                ':delivery_server_name' => $deliveryServerName,

                ':notes' => isset($body['notes']) && is_string($body['notes']) ? $body['notes'] : null,

                ':actor' => $actorId,
                ':attestation' => $attestation,
            ]);

            $cid = $st->fetchColumn();
            if (!is_string($cid) || !$this->isUuid($cid)) {
                $this->db->rollBack();
                return $this->json(500, ['error' => 'COMPLIANCE_INSERT_FAILED']);
            }
            $complianceId = $cid;

            if (count($docs) > 0) {
                $st2 = $this->db->prepare("
                    INSERT INTO case_prerequisite_compliance_evidence (
                        tenant_id, compliance_id, document_id, document_version_id
                    )
                    VALUES (:tenant, :compliance, :doc, :ver)
                    ON CONFLICT DO NOTHING
                ");

                foreach ($docs as $d) {
                    $st2->execute([
                        ':tenant' => $tenantId,
                        ':compliance' => $complianceId,
                        ':doc' => $d['document_id'],
                        ':ver' => $d['document_version_id'],
                    ]);
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[PREREQUISITE SATISFY ERROR] ' . $e->getMessage());
            return $this->json(500, ['error' => 'INTERNAL_ERROR']);
        }

        // ------------------------------------------------------------
        // Return updated eligibility snapshot
        // ------------------------------------------------------------
        $resolver = new PhaseActionResolver($this->db, $procedural);
        $eligibility = $resolver->resolve($tenantId, $caseId, $roles);

        return $this->json(200, [
            'case_id' => $caseId,
            'phase_key' => $phaseKey,
            'action_type' => $actionType,
            'compliance_id' => $complianceId,
            'attested_under_capability' => $capAllowed ? self::CAP_ATTEST : null,
            'eligibility' => $eligibility,
        ]);
    }

    /* ============================================================
     * DB helpers
     * ============================================================ */

    private function actionExistsInPhasePolicy(string $phaseKey, string $actionType, string $stateCode): bool
    {
        $st = $this->db->prepare("
            SELECT 1
            FROM procedural_phase_actions
            WHERE phase_key = :phase
              AND action_type = :act
              AND (jurisdiction_state IS NULL OR jurisdiction_state = :state)
            LIMIT 1
        ");
        $st->execute([
            ':phase' => $phaseKey,
            ':act' => $actionType,
            ':state' => $stateCode,
        ]);

        return (bool)$st->fetchColumn();
    }

    private function loadRequirement(string $phaseKey, string $actionType, string $stateCode): ?array
    {
        $st = $this->db->prepare("
            SELECT
                requires_evidence,
                min_documents,
                requires_delivery,
                allowed_delivery_methods,
                requires_tracking_number,
                jurisdiction_state
            FROM procedural_action_requirements
            WHERE phase_key = :phase
              AND action_type = :act
              AND (jurisdiction_state = :state OR jurisdiction_state IS NULL)
            ORDER BY (jurisdiction_state IS NULL) ASC
            LIMIT 1
        ");
        $st->execute([
            ':phase' => $phaseKey,
            ':act' => $actionType,
            ':state' => $stateCode,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Evidence document ownership check (Phase 14.4):
     * docs.id must be reachable via case_documents.current_version_id -> document_versions.document_id.
     */
    private function documentBelongsToCaseByDocumentsId(string $tenantId, string $caseId, string $documentId): bool
    {
        $st = $this->db->prepare("
            SELECT 1
            FROM case_documents cd
            JOIN document_versions dv ON dv.id = cd.current_version_id
            WHERE cd.tenant_id = :tenant
              AND cd.case_id = :case_id
              AND dv.document_id = :doc
            LIMIT 1
        ");
        $st->execute([
            ':tenant' => $tenantId,
            ':case_id' => $caseId,
            ':doc' => $documentId,
        ]);

        return (bool)$st->fetchColumn();
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

    /* ============================================================
     * Validation helpers
     * ============================================================ */

    private function isUuid(string $v): bool
    {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $v);
    }

    private function isActionKey(string $v): bool
    {
        return (bool)preg_match('/^[a-zA-Z0-9_:\\-]{3,100}$/', $v);
    }

    private function isIsoTimestamp(string $v): bool
    {
        // expects Zulu timestamps like 2026-01-19T10:00:00Z
        return (bool)preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?Z$/', $v);
    }

    /* ============================================================
     * Response helpers
     * ============================================================ */

    private function unauthorized(): array { return $this->json(401, ['error' => 'AUTH_REQUIRED']); }

    private function json(int $status, array $body): array
    {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
        ];
    }
}
