<?php
namespace App\Services;
final class ProceduralCompletionService
{
    public function __construct(
        private PDO $db
    ) {}

    public function handleCompletion(array $event): void
    {
        $actionType = (string)($event['action_type'] ?? '');
        $caseId     = (string)($event['case_id'] ?? '');
        $tenantId   = (string)($event['tenant_id'] ?? '');
        $completedAt = (string)($event['completed_at'] ?? '');

        if ($actionType === '' || $caseId === '' || $tenantId === '') {
            return; // hard no-op, event malformed
        }

        // Find deadlines satisfied by this action
        $deadlines = $this->findDeadlinesSatisfiedByAction(
            $tenantId,
            $caseId,
            $actionType
        );

        foreach ($deadlines as $d) {
            $this->satisfyDeadline(
                (string)$d['id'],
                $completedAt,
                $event['actor_id'] ?? null,
                $event
            );
        }
    }

    private function findDeadlinesSatisfiedByAction(
    string $tenantId,
    string $caseId,
    string $actionType
    ): array {
        $sql = "
            SELECT d.id
            FROM case_procedural_deadlines d
            JOIN procedural_action_requirements r
              ON r.obligation_key = d.obligation_key
            WHERE d.tenant_id = :tenant
              AND d.case_id   = :case
              AND d.status    = 'pending'
              AND r.action_type = :action_type
        ";

        $st = $this->db->prepare($sql);
        $st->execute([
            'tenant' => $tenantId,
            'case'   => $caseId,
            'action_type' => $actionType,
        ]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function satisfyDeadline(
    string $deadlineId,
    string $completedAt,
    ?string $actorId,
    array $event
    ): void {
        $sql = "
            UPDATE case_procedural_deadlines
               SET status = 'completed',
                   completed_at = :completed_at,
                   completed_by = :actor_id,
                   payload = jsonb_set(
                       COALESCE(payload, '{}'::jsonb),
                       '{satisfaction}',
                       to_jsonb(:satisfaction::json)
                   )
             WHERE id = :id
               AND status = 'pending'
        ";

        $st = $this->db->prepare($sql);
        $st->execute([
            'id'           => $deadlineId,
            'completed_at' => $completedAt,
            'actor_id'     => $actorId,
            'satisfaction' => json_encode([
                'source' => 'procedural_action',
                'event_type' => 'completion',
                'action_request_id' => $event['action_request_id'] ?? null,
            ]),
        ]);
    }
}

