<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class DemandDeliveryTaskService
{
    public function __construct(
        private PDO $db
    ) {}

    /**
     * Phase 15:
     * - Fan-out: create one service_task + demand_delivery_event per (target, delivery_method)
     * - Create only primary methods up front (personal_service, certified_mail)
     * - Never create posting up front; posting is conditional on personal_service failure.
     *
     * Returns an array of delivery event rows (id + service_task_id + method metadata),
     * one per created (target, method) pair.
     */

    /*
 * NOTE:
 * Demand delivery service_tasks REQUIRE a finalized demand document.
 * This is enforced by a NOT NULL FK on service_tasks.document_id.
 * We resolve it ONCE here and reuse for all fan-out methods.
 */

    public function ensureTasksForTargets(
        string $tenantId,
        string $caseId,
        string $actionRequestId,
        string $phaseKey,
        string $actionType,
        string $stateCode,
        array $targets,
        array $generatedDocuments = []
    ): array {

        /*
        * Resolve document_id (REQUIRED)
        *
        * service_tasks.document_id is NOT NULL.
        * For demand actions, we must have a generated document. 
        */
        $documentId = null;

        /*
        * Prefer explicitly generated documents from the procedure
        */
        if (!empty($generatedDocuments)) {
            $first = reset($generatedDocuments);
            if (is_array($first) && !empty($first['case_document_id'])) {
                $documentId = (string)$first['case_document_id'];
            }
        }

        /*
        * Fallback: resolve most recent demand-related case document
        * (NO source_action_request_id — it does not exist)
        */
        if ($documentId === null) {
            $st = $this->db->prepare("
                SELECT id
                FROM case_documents
                WHERE tenant_id = :tenant
                AND case_id   = :case
                AND document_type_key IN (
                    'az_demand_for_possession',
                    'affidavit_of_service',
                    'demand_notice'
                )
                ORDER BY created_at DESC
                LIMIT 1
            ");

            $st->execute([
                ':tenant' => $tenantId,
                ':case'   => $caseId,
            ]);

            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $documentId = (string)$row['id'];
            }
        }

        /*
        * HARD FAIL — service_tasks.document_id is NOT NULL
        */
        if ($documentId === null) {
            throw new RuntimeException('SERVICE_TASK_DOCUMENT_REQUIRED');
        }


        /*
         * HARD INVARIANTS
         */
        if (empty($targets)) {
            throw new RuntimeException('TASK_CREATION_BLOCKED_NO_TARGETS');
        }

        if ($phaseKey === '') {
            throw new RuntimeException('PROCEDURAL_PHASE_REQUIRED');
        }

        $addr = $this->resolveCasePropertyAddress($tenantId, $caseId);
        if ($addr === null) {
            throw new RuntimeException('CASE_PROPERTY_ADDRESS_MISSING');
        }

        
        // Determine which primary methods to create up front.
        // Posting is never created here.
        /*
         * Resolve delivery methods ONCE (procedure-level decision)
         */
        $methods = $this->resolveInitialFanoutMethods(
            tenantId: $tenantId,
            caseId: $caseId,
            phaseKey: $phaseKey,
            actionType: $actionType,
            stateCode: $stateCode,
            targets: $targets,
            generatedDocuments: $generatedDocuments
        );

        if (empty($methods)) {
            throw new RuntimeException('DELIVERY_METHOD_REQUIRED');
        }

        $now = gmdate('c');

        /*
         * Prepared statements (outside loops)
         */
        $insServiceTask = $this->db->prepare("
            INSERT INTO service_tasks (
                id,
                tenant_id,
                case_id,
                action_request_id,
                occupant_id,
                document_id,
                service_method,
                status,
                created_at
            ) VALUES (
                :id,
                :tenant,
                :case,
                :arid,
                :occupant_id,
                :document_id,
                :service_method,
                'pending',
                now()
            )
        ");

        $insDelivery = $this->db->prepare("
            INSERT INTO demand_delivery_events (
                id,
                tenant_id,
                case_id,
                action_request_id,
                delivery_method,
                delivered_at,
                delivered_by,
                address_line1,
                address_line2,
                city,
                state,
                postal_code,
                proof_document_id,
                notes,
                created_at,
                tracking_number,
                proof_case_document_id,
                service_task_id,
                occupant_id,
                delivery_status,
                target_id
            ) VALUES (
                :id,
                :tenant,
                :case_id,
                :arid,
                :method,
                NULL,
                NULL,
                :addr1,
                :addr2,
                :city,
                :state,
                :postal,
                NULL,
                NULL,
                :created_at,
                :tracking_number,
                NULL,
                :service_task_id,
                :occupant_id,
                'pending',
                :target_id
            )
            ON CONFLICT (tenant_id, case_id, action_request_id, target_id, delivery_method)
            DO NOTHING
            RETURNING id, service_task_id
        ");

        $fetchExisting = $this->db->prepare("
            SELECT id, service_task_id
            FROM demand_delivery_events
            WHERE tenant_id = :tenant
              AND case_id   = :case_id
              AND action_request_id = :arid
              AND target_id = :target_id
              AND delivery_method = :method
            LIMIT 1
        ");

        $out = [];

        foreach ($targets as $t) {
            if (!is_array($t)) {
                continue;
            }

            $targetId = (string)($t['id'] ?? '');
            if ($targetId === '' || !$this->isUuid($targetId)) {
                throw new RuntimeException('INVALID_TARGET_ID');
            }

            $serviceOccupantId = $t['occupant_id'] ?? null;

            foreach ($methods as $method) {
                $method = $this->normalizeMethodAlias($method);

                // Posting is never created here
                if ($method === 'posting') {
                    continue;
                }

                /*
                 * 🔒 FINAL IDENTITY GUARD
                 * One delivery per (tenant, case, request, target, method)
                 */
                $fetchExisting->execute([
                    ':tenant'    => $tenantId,
                    ':case_id'   => $caseId,
                    ':arid'      => $actionRequestId,
                    ':target_id' => $targetId,
                    ':method'    => $method,
                ]);

                if ($existing = $fetchExisting->fetch(PDO::FETCH_ASSOC)) {
                    $out[] = [
                        'delivery_event_id' => (string)$existing['id'],
                        'service_task_id'   => (string)$existing['service_task_id'],
                        'delivery_method'   => $method,
                        'existing'          => true,
                    ];
                    continue;
                }

                /*
                 * Create service_task FIRST (parent)
                 */
                $serviceTaskId = $this->uuid();

                $insServiceTask->execute([
                    ':id'             => $serviceTaskId,
                    ':tenant'         => $tenantId,
                    ':case'           => $caseId,
                    ':arid'           => $actionRequestId,
                    ':occupant_id'    => $serviceOccupantId,
                    ':document_id'    => $documentId,   // guaranteed non-null
                    ':service_method' => $method,
                ]);



                /*
                 * Create delivery event (child)
                 */
                $deliveryEventId = $this->uuid();

                $insDelivery->execute([
                    ':id'                  => $deliveryEventId,
                    ':tenant'              => $tenantId,
                    ':case_id'             => $caseId,
                    ':arid'                => $actionRequestId,
                    ':method'              => $method,
                    ':addr1'               => $addr['address_line1'],
                    ':addr2'               => $addr['address_line2'],
                    ':city'                => $addr['city'],
                    ':state'               => $addr['state'],
                    ':postal'              => $addr['postal_code'],
                    ':created_at'          => $now,
                    ':tracking_number'     => $method === 'certified_mail' ? 'PENDING' : null,
                    ':service_task_id'     => $serviceTaskId,
                    ':occupant_id'         => $targetId,
                    ':target_id'           => $targetId,
                ]);

                $out[] = [
                    'delivery_event_id' => $deliveryEventId,
                    'service_task_id'   => $serviceTaskId,
                    'delivery_method'   => $method,
                    'existing'          => false,
                ];
            }
        }

        return $out;
    }

    /**
     * Phase 15: conditional posting creation.
     *
     * Called when personal service fails. Creates posting ONLY if:
     * - certified_mail already exists for the target (posting never alone)
     * - posting does not already exist
     *
     * Safe on replay/idempotent.
     */
    public function ensurePostingForTargetsOnPersonalFailure(
        string $tenantId,
        string $caseId,
        string $actionRequestId,
        array $targets
    ): array {
        if (empty($targets)) {
            throw new RuntimeException('TASK_CREATION_BLOCKED_NO_TARGETS');
        }

        $addr = $this->resolveCasePropertyAddress($tenantId, $caseId);
        if ($addr === null) {
            throw new RuntimeException('CASE_PROPERTY_ADDRESS_MISSING');
        }

        $now = gmdate('c');

        $fetchExisting = $this->db->prepare("
            SELECT id, service_task_id
            FROM demand_delivery_events
            WHERE tenant_id = :tenant
              AND case_id   = :case_id
              AND action_request_id = :arid
              AND target_id = :target_id
              AND delivery_method = :method
            LIMIT 1
        ");

        $insServiceTask = $this->db->prepare("
            INSERT INTO service_tasks (
                id, tenant_id, case_id, action_request_id, occupant_id, document_id, service_method, status, created_at
            ) VALUES (
                :id, :tenant, :case, :arid, :occupant_id, :document_id, :service_method, 'pending', now()
            )
        ");

        $insDelivery = $this->db->prepare("
            INSERT INTO demand_delivery_events (
                id, tenant_id, case_id, action_request_id, delivery_method,
                delivered_at, delivered_by,
                address_line1, address_line2, city, state, postal_code,
                proof_document_id, notes, created_at,
                tracking_number, proof_case_document_id,
                service_task_id, occupant_id, delivery_status, target_id
            ) VALUES (
                :id, :tenant, :case_id, :arid, :method,
                NULL, NULL,
                :addr1, :addr2, :city, :state, :postal,
                NULL, NULL, :created_at,
                :tracking_number, :proof_case_document_id,
                :service_task_id, :occupant_id, 'pending', :target_id
            )
            ON CONFLICT (tenant_id, case_id, action_request_id, target_id, delivery_method) DO NOTHING
            RETURNING id, service_task_id
        ");

        $out = [];

        foreach ($targets as $t) {
            if (!is_array($t)) continue;

            $targetId = (string)($t['id'] ?? '');
            if ($targetId === '' || !$this->isUuid($targetId)) {
                throw new RuntimeException('INVALID_TARGET_ID');
            }

            // posting requires certified_mail existing
            $fetchExisting->execute([
                ':tenant'   => $tenantId,
                ':case_id'  => $caseId,
                ':arid'     => $actionRequestId,
                ':target_id'=> $targetId,
                ':method'   => 'certified_mail',
            ]);
            $hasCertified = $fetchExisting->fetch(PDO::FETCH_ASSOC);

            if (!is_array($hasCertified) || empty($hasCertified['id'])) {
                // Do nothing: posting is not allowed alone
                continue;
            }

            // If posting already exists, reuse it
            $fetchExisting->execute([
                ':tenant'   => $tenantId,
                ':case_id'  => $caseId,
                ':arid'     => $actionRequestId,
                ':target_id'=> $targetId,
                ':method'   => 'posting',
            ]);
            $existingPosting = $fetchExisting->fetch(PDO::FETCH_ASSOC);
            if (is_array($existingPosting) && !empty($existingPosting['id']) && !empty($existingPosting['service_task_id'])) {
                $out[] = [
                    'id'              => (string)$existingPosting['id'],
                    'service_task_id' => (string)$existingPosting['service_task_id'],
                    'target_id'       => $targetId,
                    'delivery_method' => 'posting',
                    'delivery_status' => 'pending',
                ];
                continue;
            }

            $deliveryEventId = $this->uuid();
            $serviceTaskId   = $this->uuid();
            $documentId      = $this->uuid();

            [$trackingPlaceholder, $proofPlaceholder] = $this->placeholdersForMethod('posting');

            // occupant_id mapping same as ensureTasksForTargets
            $deliveryOccupantFk = $targetId;
            $serviceOccupantId  = $t['occupant_id'] ?? null;

            $insServiceTask->execute([
                ':id'            => $serviceTaskId,
                ':tenant'        => $tenantId,
                ':case'          => $caseId,
                ':arid'          => $actionRequestId,
                ':occupant_id'   => $serviceOccupantId,
                ':document_id'   => $documentId, // ✅ GUARANTEED NON-NULL
                ':service_method'=> $method,
            ]);


            $insDelivery->execute([
                ':id' => $deliveryEventId,
                ':tenant' => $tenantId,
                ':case_id' => $caseId,
                ':arid' => $actionRequestId,
                ':method' => 'posting',
                ':addr1' => $addr['address_line1'],
                ':addr2' => $addr['address_line2'],
                ':city'  => $addr['city'],
                ':state' => $addr['state'],
                ':postal'=> $addr['postal_code'],
                ':created_at' => $now,
                ':tracking_number' => $trackingPlaceholder,
                ':proof_case_document_id' => $proofPlaceholder,
                ':service_task_id' => $serviceTaskId,
                ':occupant_id' => $deliveryOccupantFk,
                ':target_id' => $targetId,
            ]);

            $row = $insDelivery->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                // conflict - delete unused task and fetch existing
                $this->db->prepare("DELETE FROM service_tasks WHERE id = :id")
                    ->execute([':id' => $serviceTaskId]);

                $fetchExisting->execute([
                    ':tenant'   => $tenantId,
                    ':case_id'  => $caseId,
                    ':arid'     => $actionRequestId,
                    ':target_id'=> $targetId,
                    ':method'   => 'posting',
                ]);
                $row = $fetchExisting->fetch(PDO::FETCH_ASSOC);
            }

            if (!is_array($row) || empty($row['id']) || empty($row['service_task_id'])) {
                throw new RuntimeException('SERVICE_TASK_CREATION_FAILED');
            }

            $out[] = [
                'id'              => (string)$row['id'],
                'service_task_id' => (string)$row['service_task_id'],
                'target_id'       => $targetId,
                'delivery_method' => 'posting',
                'delivery_status' => 'pending',
            ];
        }

        return $out;
    }

    /**
     * Phase 15 stub: accept USPS polling results (cron will call this in the future).
     *
     * This does NOT implement USPS polling. It only applies an update to the existing
     * certified_mail row so the system can evolve safely.
     *
     * Allowed transitions (recommended):
     * - mailed -> delivered
     * - mailed -> undeliverable
     * - pending -> mailed (tracking recorded at time of mailing)
     */
    public function applyCertifiedMailTrackingUpdate(
        string $tenantId,
        string $caseId,
        string $actionRequestId,
        string $targetId,
        string $deliveryEventId,
        array $update,
        string $actorId = null
    ): void {
        if ($deliveryEventId === '' || !$this->isUuid($deliveryEventId)) {
            throw new RuntimeException('INVALID_DELIVERY_EVENT_ID');
        }
        if ($targetId === '' || !$this->isUuid($targetId)) {
            throw new RuntimeException('INVALID_TARGET_ID');
        }

        $status = (string)($update['delivery_status'] ?? '');
        if (!in_array($status, ['mailed', 'delivered', 'undeliverable'], true)) {
            throw new RuntimeException('INVALID_DELIVERY_STATUS');
        }

        $tracking = $update['tracking_number'] ?? null;
        $proof    = $update['proof_case_document_id'] ?? null;

        $deliveredAt = $update['delivered_at'] ?? null; // ISO string or null
        $deliveredBy = $actorId;

        $st = $this->db->prepare("
            UPDATE demand_delivery_events
            SET
                delivery_status = :status,
                tracking_number = COALESCE(:tracking, tracking_number),
                proof_case_document_id = COALESCE(:proof, proof_case_document_id),
                delivered_at = CASE
                    WHEN :status IN ('delivered','undeliverable') THEN COALESCE(:delivered_at, delivered_at, now())
                    ELSE delivered_at
                END,
                delivered_by = CASE
                    WHEN :status IN ('delivered','undeliverable') THEN COALESCE(:delivered_by, delivered_by)
                    ELSE delivered_by
                END
            WHERE tenant_id = :tenant
              AND case_id = :case_id
              AND action_request_id = :arid
              AND target_id = :target_id
              AND id = :event_id
              AND delivery_method = 'certified_mail'
        ");

        $st->bindValue(':status', $status);
        $st->bindValue(':tracking', is_string($tracking) ? $tracking : null);
        $st->bindValue(':proof', $proof);
        $st->bindValue(':delivered_at', $deliveredAt);
        $st->bindValue(':delivered_by', $deliveredBy);
        $st->bindValue(':tenant', $tenantId);
        $st->bindValue(':case_id', $caseId);
        $st->bindValue(':arid', $actionRequestId);
        $st->bindValue(':target_id', $targetId);
        $st->bindValue(':event_id', $deliveryEventId);

        $st->execute();
    }

    private function hasAnyDeliveryForTarget(
        string $tenantId,
        string $caseId,
        string $actionRequestId,
        string $targetId
    ): bool {
        $st = $this->db->prepare("
            SELECT 1
            FROM demand_delivery_events
            WHERE tenant_id = :tenant
            AND case_id = :case
            AND action_request_id = :arid
            AND target_id = :target
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
            ':arid'   => $actionRequestId,
            ':target' => $targetId,
        ]);

        return (bool)$st->fetchColumn();
    }


    private function resolveCasePropertyAddress(string $tenantId, string $caseId): ?array
    {
        $st = $this->db->prepare("
            SELECT
                p.address_line1,
                p.address_line2,
                p.city,
                p.state,
                p.zip AS postal_code
            FROM case_properties cp
            JOIN properties p
              ON p.id = cp.property_id
             AND p.tenant_id = cp.tenant_id
            WHERE cp.tenant_id = :tenant
              AND cp.case_id = :case_id
              AND cp.ends_at IS NULL
            ORDER BY cp.starts_at DESC
            LIMIT 1
        ");
        $st->execute([
            ':tenant'  => $tenantId,
            ':case_id' => $caseId,
        ]);

        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;

        $line1 = (string)($r['address_line1'] ?? '');
        $city  = (string)($r['city'] ?? '');
        $state = (string)($r['state'] ?? '');
        $zip   = (string)($r['postal_code'] ?? '');

        if ($line1 === '' || $city === '' || $state === '' || $zip === '') {
            return null;
        }

        return [
            'address_line1' => $line1,
            'address_line2' => is_string($r['address_line2'] ?? null) ? (string)$r['address_line2'] : null,
            'city'          => $city,
            'state'         => $state,
            'postal_code'   => $zip,
        ];
    }

    /**
     * Phase 15: choose initial fan-out methods.
     *
     * Rules implemented per your specs:
     * - Posting is never created initially (conditional on personal failure).
     * - Certified mail is usually sent immediately when requested.
     * - Personal service is always legally allowed, but is only created if requested OR if no explicit request exists.
     *
     * Best-effort extraction of requested methods:
     * - Looks for generatedDocuments['metadata']['delivery_methods'] (common pattern)
     * - Else looks for generatedDocuments['delivery_methods']
     * - Else defaults for az_demand-like phases: personal_service + certified_mail
     */
    private function resolveInitialFanoutMethods(
        string $tenantId,
        string $caseId,
        string $phaseKey,
        string $actionType,
        string $stateCode,
        array $targets,
        array $generatedDocuments
    ): array {
        // Pull allowed methods from procedural_action_requirements (still useful as a cap)
        $allowed = $this->loadAllowedDeliveryMethods($phaseKey, $actionType, $stateCode);

        $requested = $this->extractRequestedDeliveryMethods($generatedDocuments);

        // Normalize aliases
        $requested = array_map([$this, 'normalizeMethodAlias'], $requested);
        $requested = array_values(array_unique(array_filter($requested, fn($m) => is_string($m) && $m !== '')));

        // Cap to allowed if allowed set is non-empty (but ALWAYS allow personal_service per your instruction)
        if (!empty($allowed)) {
            $allowed = array_map([$this, 'normalizeMethodAlias'], $allowed);
            $allowed = array_values(array_unique($allowed));

            $requested = array_values(array_filter($requested, function ($m) use ($allowed) {
                if ($m === 'personal_service') return true; // always allowed
                return in_array($m, $allowed, true);
            }));
        }

        // Enforce "posting never alone" at request level (even though we won't create posting initially)
        // If someone requests posting without certified_mail, treat posting as invalid and drop it.
        if (in_array('posting', $requested, true) && !in_array('certified_mail', $requested, true)) {
            $requested = array_values(array_filter($requested, fn($m) => $m !== 'posting'));
        }

        // Remove posting from initial creation always (conditional later)
        $requested = array_values(array_filter($requested, fn($m) => $m !== 'posting'));

        // If explicit request exists, honor it (common: personal + certified)
        if (!empty($requested)) {
            return $requested;
        }

        // If nothing explicitly requested, default to operationally sane baseline:
        // personal_service + certified_mail (matches your "usually requested together" statement)
        return ['personal_service', 'certified_mail'];
    }

    private function extractRequestedDeliveryMethods(array $generatedDocuments): array
    {
        // Most likely place: procedure result metadata
        if (isset($generatedDocuments['metadata']['delivery_methods']) && is_array($generatedDocuments['metadata']['delivery_methods'])) {
            return $generatedDocuments['metadata']['delivery_methods'];
        }
        if (isset($generatedDocuments['delivery_methods']) && is_array($generatedDocuments['delivery_methods'])) {
            return $generatedDocuments['delivery_methods'];
        }
        return [];
    }

    private function loadAllowedDeliveryMethods(string $phaseKey, string $actionType, string $stateCode): array
    {
        $st = $this->db->prepare("
            SELECT allowed_delivery_methods, requires_tracking_number
            FROM procedural_action_requirements
            WHERE phase_key = :phase_key
              AND action_type = :action_type
              AND (jurisdiction_state = :state_code OR jurisdiction_state IS NULL)
            ORDER BY (jurisdiction_state IS NULL) ASC
            LIMIT 1
        ");
        $st->execute([
            ':phase_key'   => $phaseKey,
            ':action_type' => $actionType,
            ':state_code'  => $stateCode,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // If no policy row exists, allow the operational baseline
            return ['personal_service', 'certified_mail'];
        }

        $allowed = $this->normalizePgTextArray($row['allowed_delivery_methods'] ?? null) ?? [];

        // Preserve old behavior: certified_mail implies courier equivalent, if that matters elsewhere
        if (in_array('certified_mail', $allowed, true) && !in_array('courier', $allowed, true)) {
            $allowed[] = 'courier';
        }

        // If policy says tracking required, include certified_mail as an allowed method
        $requiresTracking = (bool)($row['requires_tracking_number'] ?? false);
        if ($requiresTracking && !in_array('certified_mail', $allowed, true)) {
            $allowed[] = 'certified_mail';
        }

        // Personal is always allowed (your Phase 15 rule)
        if (!in_array('personal_service', $allowed, true) && !in_array('personal_delivery', $allowed, true)) {
            $allowed[] = 'personal_service';
        }

        return $allowed;
    }

    private function normalizeMethodAlias(string $m): string
    {
        $m = trim($m);
        if ($m === 'personal_delivery') return 'personal_service';
        return $m;
    }

    /**
     * Placeholders that satisfy chk_demand_delivery_proof_v4 under delivery_status='pending'.
     *
     * @return array{0:?string,1:?string} (tracking_number, proof_case_document_id)
     */
    private function placeholdersForMethod(string $method): array
    {
        // For mail methods, pending is allowed to be NULL/''/'PENDING' per v4.
        if (in_array($method, ['certified_mail', 'courier'], true)) {
            return ['PENDING', null];
        }

        // For personal_service/posting, pending requires proof_case_document_id IS NULL per v4.
        return [null, null];
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

    private function isUuid(string $v): bool
    {
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $v
        );
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
