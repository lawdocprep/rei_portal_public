<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Canonical procedural effects engine.
 *
 * Invariants:
 * - MUST be idempotent
 * - MUST be replay-safe
 * - MUST NOT mutate case_events
 */
final class ProceduralEventEngineService
{
    public function __construct(
        private PDO $db
    ) {}

    /**
     * Apply legal effects for a single fact event.
     *
     * @return array{
     *   applied_effects:int,
     *   effects:array<int,array<string,mixed>>
     * }
     */
    public function applySingleEvent(
        string $tenantId,
        string $caseId,
        string $eventType,
        array $payload,
        string $occurredAtIso,
        ?string $replayedByActorId = null,
        bool $dryRun = false
    ): array {
        $effects = [];
        $applied = 0;

        /*
         * Only these events can satisfy demand service proof
         */
        $serviceProofEvents = [
            'demand_delivery.courier_proof_uploaded',
            'demand_delivery.certified_mail_delivered',
            'demand_delivery.certified_mail_deemed_received',
        ];

        if (!in_array($eventType, $serviceProofEvents, true)) {
            return [
                'applied_effects' => 0,
                'effects' => [],
            ];
        }

        /*
         * Idempotent guard: already completed → NO-OP
         */
        if ($this->isObligationCompleted(
            tenantId: $tenantId,
            caseId: $caseId,
            obligationKey: 'az_demand_service_proof'
        )) {
            return [
                'applied_effects' => 0,
                'effects' => [[
                    'type' => 'deadline.satisfy',
                    'obligation_key' => 'az_demand_service_proof',
                    'via_event_type' => $eventType,
                    'already_completed' => true,
                ]],
            ];
        }

        $effect = [
            'type' => 'deadline.satisfy',
            'obligation_key' => 'az_demand_service_proof',
            'via_event_type' => $eventType,
            'occurred_at' => $occurredAtIso,
        ];

        if ($dryRun) {
            $effect['dry_run'] = true;
            $effect['would_apply'] = $this->hasPendingDeadline(
                tenantId: $tenantId,
                caseId: $caseId,
                obligationKey: 'az_demand_service_proof'
            );

            return [
                'applied_effects' => 0,
                'effects' => [$effect],
            ];
        }

        /*
         * Idempotent UPDATE:
         * - only pending / blocked rows
         * - never inserts
         */
        $rows = $this->completeDeadline(
            tenantId: $tenantId,
            caseId: $caseId,
            obligationKey: 'az_demand_service_proof',
            completedAtIso: $occurredAtIso,
            satisfactionPayload: [
                'via' => [
                    'event_type' => $eventType,
                    'occurred_at' => $occurredAtIso,
                ],
                'applied_by' => [
                    'mode' => 'procedural_engine',
                    'actor_id' => $replayedByActorId,
                ],
            ]
        );

        $effect['applied'] = ($rows > 0);
        $effects[] = $effect;

        if ($rows > 0 && $this->hasPendingDeadline(
            tenantId: $tenantId,
            caseId: $caseId,
            obligationKey: 'az_demand_service_proof'
        ) === false) {
            $applied = 1;

            /*
            * ------------------------------------------------------------
            * Phase transition signal
            * ------------------------------------------------------------
            * Demand service proof completed → advance procedural phase
            */

            if ($rows > 0 && !$dryRun) {

                $eventService = new \App\Services\Events\CaseEventService($this->db);

                $eventService->emitEvent(
                    'az_demand_service_proof_completed',
                    [
                        'source_event_type' => $eventType,
                        'occurred_at' => $occurredAtIso,
                        'via' => 'procedural_event_engine'
                    ],
                    $tenantId,
                    $caseId,
                    $replayedByActorId ?? 'system'
                );

                $effects[] = [
                    'type' => 'phase.transition.signal',
                    'event_type' => 'az_demand_service_proof_completed'
                ];
            }
        }

        return [
            'applied_effects' => $applied,
            'effects' => $effects,
        ];
    }

    /* ============================================================
     * Guards
     * ============================================================ */

    private function isObligationCompleted(
        string $tenantId,
        string $caseId,
        string $obligationKey
    ): bool {
        $st = $this->db->prepare("
            SELECT 1
            FROM case_procedural_deadlines
            WHERE tenant_id = :tenant
              AND case_id = :case
              AND obligation_key = :key
              AND status = 'completed'
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
            ':key'    => $obligationKey,
        ]);

        return (bool)$st->fetchColumn();
    }

    private function hasPendingDeadline(
        string $tenantId,
        string $caseId,
        string $obligationKey
    ): bool {
        $st = $this->db->prepare("
            SELECT 1
            FROM case_procedural_deadlines
            WHERE tenant_id = :tenant
              AND case_id = :case
              AND obligation_key = :key
              AND status IN ('pending','blocked')
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
            ':key'    => $obligationKey,
        ]);

        return (bool)$st->fetchColumn();
    }

    /* ============================================================
     * Idempotent mutation
     * ============================================================ */

    private function completeDeadline(
        string $tenantId,
        string $caseId,
        string $obligationKey,
        string $completedAtIso,
        array $satisfactionPayload
    ): int {
        $st = $this->db->prepare("
            UPDATE case_procedural_deadlines
            SET
                status = 'completed',
                completed_at = :completed_at::timestamptz,
                payload = COALESCE(payload, '{}'::jsonb)
                          || jsonb_build_object(
                              'satisfaction',
                              :sat::jsonb
                          )
            WHERE tenant_id = :tenant
              AND case_id = :case
              AND obligation_key = :key
              AND status IN ('pending','blocked')
        ");

        $st->execute([
            ':completed_at' => $completedAtIso,
            ':sat'          => json_encode($satisfactionPayload, JSON_UNESCAPED_SLASHES),
            ':tenant'       => $tenantId,
            ':case'         => $caseId,
            ':key'          => $obligationKey,
        ]);

        return $st->rowCount();
    }
}
