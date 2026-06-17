<?php
declare(strict_types=1);

namespace App\Cron;

use PDO;
use App\Services\ProceduralEventEngineService;
use App\Services\Documents\StatutoryReceiptDocumentFactory;

final class CertifiedMailDeemedReceivedJob
{
    public function __construct(
        private PDO $db
    ) {}

    /**
     * Context flags:
     *  - dry_run (bool)
     *  - verbose (bool)
     *  - per_tenant_batch_size (int) default 50
     *  - max_tenants (int) default 50
     *  - use_tenant_advisory_lock (bool) default true
     *
     * Production shape:
     *  - Per-tenant isolation => fairness + bounded transactions
     *  - FOR UPDATE SKIP LOCKED => safe horizontal scaling
     *  - Tenant advisory lock => avoids same-tenant procedural races
     */
    public function run(array $context = []): array
    {
        $dryRun  = (bool)($context['dry_run'] ?? false);
        $verbose = (bool)($context['verbose'] ?? false);

        $perTenantBatch = (int)($context['per_tenant_batch_size'] ?? 50);
        if ($perTenantBatch <= 0) $perTenantBatch = 50;

        $maxTenants = (int)($context['max_tenants'] ?? 50);
        if ($maxTenants <= 0) $maxTenants = 50;

        $useTenantLock = $context['use_tenant_advisory_lock'] ?? true;
        $useTenantLock = (bool)$useTenantLock;

        $processedTotal = 0;
        $tenantsTouched = 0;
        $tenantsSkippedLock = 0;

        $engine = new ProceduralEventEngineService($this->db);

        // Step 0: pick tenants with eligible work (fair, oldest-first)
        $tenantSel = $this->db->prepare("
            SELECT tenant_id
            FROM demand_delivery_events
            WHERE delivery_method = 'certified_mail'
              AND delivery_status = 'sent'
              AND delivered_at IS NULL
              AND created_at <= now() - interval '5 days'
            GROUP BY tenant_id
            ORDER BY MIN(created_at) ASC
            LIMIT :max_tenants
        ");
        $tenantSel->bindValue(':max_tenants', $maxTenants, PDO::PARAM_INT);
        $tenantSel->execute();
        $tenants = $tenantSel->fetchAll(PDO::FETCH_COLUMN) ?: [];

        if (!$tenants) {
            return [
                'exit_code' => 0,
                'message'   => 'No certified mail items eligible for deemed receipt.',
                'metrics'   => [
                    'processed' => 0,
                    'tenants'   => 0,
                    'skipped_tenant_lock' => 0,
                    'dry_run'   => $dryRun,
                    'per_tenant_batch_size' => $perTenantBatch,
                    'max_tenants' => $maxTenants,
                ],
            ];
        }

        foreach ($tenants as $tenantId) {
            if (!is_string($tenantId) || $tenantId === '') {
                continue;
            }

            // Tenant-level advisory lock (optional, recommended)
            if ($useTenantLock) {
                if (!$this->tryAdvisoryTenantLock($tenantId)) {
                    $tenantsSkippedLock++;
                    if ($verbose) {
                        fwrite(STDOUT, "Tenant lock busy, skipping tenant={$tenantId}\n");
                    }
                    continue;
                }
            }

            try {
                $tenantProcessed = $this->processTenantBatch(
                    tenantId: $tenantId,
                    perTenantBatch: $perTenantBatch,
                    engine: $engine,
                    dryRun: $dryRun,
                    verbose: $verbose
                );

                if ($tenantProcessed > 0) {
                    $tenantsTouched++;
                    $processedTotal += $tenantProcessed;
                }

            } finally {
                if ($useTenantLock) {
                    $this->releaseAdvisoryTenantLock($tenantId);
                }
            }
        }

        return [
            'exit_code' => 0,
            'message'   => $dryRun
                ? "DRY RUN: {$processedTotal} certified mail item(s) would be processed across {$tenantsTouched} tenant(s)."
                : "Certified mail deemed received processed {$processedTotal} item(s) across {$tenantsTouched} tenant(s).",
            'metrics'   => [
                'processed' => $processedTotal,
                'tenants'   => $tenantsTouched,
                'skipped_tenant_lock' => $tenantsSkippedLock,
                'dry_run'   => $dryRun,
                'per_tenant_batch_size' => $perTenantBatch,
                'max_tenants' => $maxTenants,
                'use_tenant_advisory_lock' => $useTenantLock,
            ],
        ];
    }

    private function processTenantBatch(
        string $tenantId,
        int $perTenantBatch,
        ProceduralEventEngineService $engine,
        bool $dryRun,
        bool $verbose
    ): int {
        $processed = 0;

        $this->db->beginTransaction();

        try {
            // Step 1: claim rows for tenant with SKIP LOCKED (worker-safe)
            $select = $this->db->prepare("
                SELECT
                    id,
                    tenant_id,
                    case_id,
                    action_request_id,
                    service_task_id,
                    tracking_number,
                    created_at
                FROM demand_delivery_events
                WHERE tenant_id = :tenant
                  AND delivery_method = 'certified_mail'
                  AND delivery_status = 'sent'
                  AND delivered_at IS NULL
                  AND created_at <= now() - interval '5 days'
                ORDER BY created_at ASC
                FOR UPDATE SKIP LOCKED
                LIMIT :lim
            ");
            $select->bindValue(':tenant', $tenantId);
            $select->bindValue(':lim', $perTenantBatch, PDO::PARAM_INT);
            $select->execute();

            $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (!$rows) {
                $this->db->commit();
                return 0;
            }

            if ($verbose) {
                fwrite(STDOUT, "Tenant {$tenantId}: processing " . count($rows) . " item(s)\n");
            }

            foreach ($rows as $r) {
                // Step 2: resolve/create proof doc first (constraint-safe)
                $proofDocumentId = StatutoryReceiptDocumentFactory::resolveOrCreate(
                    db: $this->db,
                    tenantId: $r['tenant_id'],
                    caseId: $r['case_id'],
                    actionRequestId: $r['action_request_id'],
                    deliveryEventId: $r['id'],
                    occurredAtIso: $r['created_at']
                );

                if (!$dryRun) {
                    // Step 3: update delivery event
                    $upd = $this->db->prepare("
                        UPDATE demand_delivery_events
                        SET
                            proof_case_document_id = :proof,
                            delivery_status        = 'deemed_received',
                            delivered_at           = created_at + interval '5 days',
                            delivered_by           = NULL
                        WHERE id = :id
                    ");
                    $upd->execute([
                        ':proof' => $proofDocumentId,
                        ':id'    => $r['id'],
                    ]);

                    // Step 4: emit case event idempotently
                    $payload = [
                        'action_request_id'       => $r['action_request_id'],
                        'service_task_id'         => $r['service_task_id'],
                        'delivery_event_id'       => $r['id'],
                        'tracking_number'         => $r['tracking_number'],
                        'proof_case_document_id'  => $proofDocumentId,
                        'source'                  => 'cron',
                    ];

                    $ins = $this->db->prepare("
                        INSERT INTO case_events (
                            id,
                            tenant_id,
                            case_id,
                            event_type,
                            occurred_at,
                            actor_id,
                            created_at,
                            payload
                        )
                        SELECT
                            uuid_generate_v4(),
                            :tenant,
                            :case,
                            'demand_delivery.certified_mail_deemed_received',
                            :occurred_at,
                            NULL,
                            now(),
                            :payload::jsonb
                        WHERE NOT EXISTS (
                            SELECT 1
                            FROM case_events
                            WHERE event_type = 'demand_delivery.certified_mail_deemed_received'
                              AND payload->>'delivery_event_id' = :delivery_event_id
                        )
                    ");
                    $ins->execute([
                        ':tenant'             => $r['tenant_id'],
                        ':case'               => $r['case_id'],
                        ':occurred_at'        => $r['created_at'],
                        ':delivery_event_id'  => (string)$r['id'],
                        ':payload'            => json_encode($payload, JSON_UNESCAPED_SLASHES),
                    ]);

                    // Step 5: apply procedural effects
                    $engine->applySingleEvent(
                        tenantId: $r['tenant_id'],
                        caseId: $r['case_id'],
                        eventType: 'demand_delivery.certified_mail_deemed_received',
                        payload: $payload,
                        occurredAtIso: $r['created_at'],
                        replayedByActorId: null,
                        dryRun: false
                    );
                }

                $processed++;
            }

            if ($dryRun) {
                $this->db->rollBack();
            } else {
                $this->db->commit();
            }

            return $processed;

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Tenant-scoped advisory lock using pg_try_advisory_lock(int,int).
     * Stable keys derived from md5(tenantId).
     */
    private function tryAdvisoryTenantLock(string $tenantId): bool
    {
        [$k1, $k2] = $this->advisoryKeys($tenantId);

        $st = $this->db->prepare("SELECT pg_try_advisory_lock(:k1, :k2) AS ok");
        $st->bindValue(':k1', $k1, PDO::PARAM_INT);
        $st->bindValue(':k2', $k2, PDO::PARAM_INT);
        $st->execute();

        $row = $st->fetch(PDO::FETCH_ASSOC);
        return !empty($row) && ($row['ok'] === true || $row['ok'] === 't' || $row['ok'] === 1 || $row['ok'] === '1');
    }

    private function releaseAdvisoryTenantLock(string $tenantId): void
    {
        [$k1, $k2] = $this->advisoryKeys($tenantId);

        $st = $this->db->prepare("SELECT pg_advisory_unlock(:k1, :k2)");
        $st->bindValue(':k1', $k1, PDO::PARAM_INT);
        $st->bindValue(':k2', $k2, PDO::PARAM_INT);
        $st->execute();
    }

    private function advisoryKeys(string $tenantId): array
    {
        $hex = md5($tenantId);
        $a = hexdec(substr($hex, 0, 8));
        $b = hexdec(substr($hex, 8, 8));

        if ($a >= 0x80000000) $a -= 0x100000000;
        if ($b >= 0x80000000) $b -= 0x100000000;

        return [(int)$a, (int)$b];
    }
}
