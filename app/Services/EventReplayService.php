<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class EventReplayService
{
    public function __construct(
        private PDO $db,
        private ProceduralEventEngineService $engine
    ) {}

    /**
     * Replay all case_events for a case in canonical order.
     *
     * @return array{audit_id:string,event_count:int,applied_effect_count:int,effects:array}
     */
    public function replayCase(
        string $tenantId,
        string $caseId,
        ?string $actorId,
        bool $dryRun = false,
        ?string $reason = null
    ): array {
        $this->db->beginTransaction();
        try {
            $auditId = $this->insertAuditRow($tenantId, $caseId, $actorId, $dryRun, $reason);

            $events = $this->loadCaseEvents($tenantId, $caseId);

            $appliedTotal = 0;
            $effectsOut = [];

            foreach ($events as $e) {
                $eventType = (string)$e['event_type'];
                $occurredAt = (string)$e['occurred_at'];

                $payload = [];
                if (is_string($e['payload'] ?? null)) {
                    $tmp = json_decode((string)$e['payload'], true);
                    if (is_array($tmp)) $payload = $tmp;
                } elseif (is_array($e['payload'] ?? null)) {
                    $payload = (array)$e['payload'];
                }

                $res = $this->engine->applySingleEvent(
                    tenantId: $tenantId,
                    caseId: $caseId,
                    eventType: $eventType,
                    payload: $payload,
                    occurredAtIso: $occurredAt,
                    replayedByActorId: $actorId,
                    dryRun: $dryRun
                );

                $appliedTotal += (int)$res['applied_effects'];
                foreach (($res['effects'] ?? []) as $fx) {
                    $effectsOut[] = $fx + ['event_type' => $eventType, 'occurred_at' => $occurredAt];
                }
            }

            $this->finishAuditRow(
                auditId: $auditId,
                eventCount: count($events),
                appliedEffectCount: $appliedTotal,
                status: 'succeeded',
                errorMessage: null
            );

            $this->db->commit();

            return [
                'audit_id' => $auditId,
                'event_count' => count($events),
                'applied_effect_count' => $appliedTotal,
                'effects' => $effectsOut,
                'dry_run' => $dryRun,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                // Try to stamp failure if we can (best effort).
                try {
                    if (isset($auditId)) {
                        $this->finishAuditRow(
                            auditId: (string)$auditId,
                            eventCount: 0,
                            appliedEffectCount: 0,
                            status: 'failed',
                            errorMessage: $e->getMessage()
                        );
                    }
                } catch (\Throwable) {
                    // ignore
                }
                $this->db->rollBack();
            }
            throw new RuntimeException('REPLAY_FAILED: ' . $e->getMessage());
        }
    }

    private function loadCaseEvents(string $tenantId, string $caseId): array
    {
        $st = $this->db->prepare("
            SELECT id, event_type, occurred_at, payload
            FROM case_events
            WHERE tenant_id = :tenant
              AND case_id = :case
            ORDER BY occurred_at ASC, id ASC
        ");
        $st->execute([':tenant' => $tenantId, ':case' => $caseId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function insertAuditRow(
        string $tenantId,
        string $caseId,
        ?string $actorId,
        bool $dryRun,
        ?string $reason
    ): string {
        $st = $this->db->prepare("
            INSERT INTO event_replay_audit (
            tenant_id, case_id, replayed_by, reason, dry_run
            ) VALUES (
            :tenant, :case, :actor, :reason, :dry_run
            )
            RETURNING id
        ");

        $st->bindValue(':tenant', $tenantId);
        $st->bindValue(':case',   $caseId);
        $st->bindValue(':actor',  $actorId);
        $st->bindValue(':reason', $reason);
        $st->bindValue(':dry_run', (bool)$dryRun, PDO::PARAM_BOOL);

        $st->execute();

        return (string)$st->fetchColumn();
    }


    private function finishAuditRow(
        string $auditId,
        int $eventCount,
        int $appliedEffectCount,
        string $status,
        ?string $errorMessage
    ): void {
        $st = $this->db->prepare("
            UPDATE event_replay_audit
            SET
              completed_at = now(),
              event_count = :event_count,
              applied_effect_count = :applied,
              status = :status,
              error_message = :err
            WHERE id = :id
        ");
        $st->execute([
            ':event_count' => $eventCount,
            ':applied' => $appliedEffectCount,
            ':status' => $status,
            ':err' => $errorMessage,
            ':id' => $auditId,
        ]);
    }
}
