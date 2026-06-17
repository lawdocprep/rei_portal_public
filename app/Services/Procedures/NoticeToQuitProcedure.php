<?php
declare(strict_types=1);

namespace App\Services\Procedures;

use App\Services\ProceduralAction;
use App\Services\ProcedureContext;

final class NoticeToQuitProcedure implements ProceduralAction
{
    public function run(ProcedureContext $ctx): array
    {
        // Create a case_document placeholder (draft). Rendering/versioning can come later.
        $caseDocumentId = $this->uuid();
        $deadlineId = $this->uuid();

        // 1) Create case_document (draft)
        $stDoc = $ctx->db->prepare("
            INSERT INTO case_documents (
                id, tenant_id, case_id,
                document_type_key, title,
                status, is_evidence,
                created_at, created_by, metadata
            ) VALUES (
                :id, :tenant_id, :case_id,
                :document_type_key, :title,
                'draft', false,
                now(), :created_by, :metadata::jsonb
            )
        ");

        $stDoc->execute([
            ':id' => $caseDocumentId,
            ':tenant_id' => $ctx->tenantId,
            ':case_id' => $ctx->caseId,
            ':document_type_key' => 'notice_to_quit',
            ':title' => 'Notice to Quit',
            ':created_by' => $ctx->actorId,
            ':metadata' => json_encode([
                'source' => 'lar_execute',
                'action_request_id' => $ctx->requestId,
                'template' => 'notice_to_quit:v1',
                'render_status' => 'needs_render',
            ], JSON_UNESCAPED_SLASHES),
        ]);

        // 2) Emit procedural events into case_events (so timeline shows it)
        $events = [];

        $events[] = $this->insertCaseEvent($ctx, 'notice_generated', [
            'title' => 'Notice Generated',
            'legal_kind' => 'service',
            'case_document_id' => $caseDocumentId,
            'action_request_id' => $ctx->requestId,
        ]);

        $events[] = $this->insertCaseEvent($ctx, 'service_event', [
            'title' => 'Service Required',
            'legal_kind' => 'service',
            'action_request_id' => $ctx->requestId,
            'instructions' => 'Serve the Notice to Quit (mail or posting as permitted).',
        ]);

        // 3) Persist a deadline (example: 5 days from now at end of day)
        $due = (new \DateTimeImmutable($ctx->nowIso))
            ->modify('+5 days')
            ->setTime(23, 59, 59)
            ->format(DATE_ATOM);

        $stDl = $ctx->db->prepare("
            INSERT INTO case_deadlines (
                id, tenant_id, case_id,
                deadline_type, due_at,
                status, rule_source,
                derived_from, calculation, resolved_by,
                superseded_by, created_at
            ) VALUES (
                :id, :tenant_id, :case_id,
                :deadline_type, :due_at,
                'pending', :rule_source::jsonb,
                :derived_from::jsonb, :calculation::jsonb, NULL,
                NULL, now()
            )
        ");

        $stDl->execute([
            ':id' => $deadlineId,
            ':tenant_id' => $ctx->tenantId,
            ':case_id' => $ctx->caseId,
            ':deadline_type' => 'notice_to_quit_cure_period_end',
            ':due_at' => $due,
            ':rule_source' => json_encode([
                'authority' => 'tenant_policy',
                'citation' => 'notice_to_quit:v1 default cure period',
            ], JSON_UNESCAPED_SLASHES),
            ':derived_from' => json_encode([
                'trigger' => 'lar_execute',
                'action_request_id' => $ctx->requestId,
                'case_document_id' => $caseDocumentId,
            ], JSON_UNESCAPED_SLASHES),
            ':calculation' => json_encode([
                'method' => 'fixed_days',
                'days' => 5,
                'notes' => 'Placeholder. Replace with jurisdiction rules engine later.',
            ], JSON_UNESCAPED_SLASHES),
        ]);

        // 4) Phase advancement: emit an event with explicit override
        $phaseEvent = $this->insertCaseEvent($ctx, 'phase_advanced', [
            'title' => 'Phase Advanced',
            'phase_override' => 'pre_filing',
            'to_phase' => 'pre_filing',
            'reason' => 'Notice generated; case is in pre-filing posture.',
            'action_request_id' => $ctx->requestId,
        ]);
        $events[] = $phaseEvent;

        return [
            'executed_by' => $ctx->actorId,
            'executed_at' => $ctx->nowIso,
            'generated_documents' => [
                [
                    'case_document_id' => $caseDocumentId,
                    'document_type_key' => 'notice_to_quit',
                    'title' => 'Notice to Quit',
                    'status' => 'draft',
                    'current_version' => null,
                ],
            ],
            'emitted_events' => $events,
            'created_deadlines' => [
                [
                    'id' => $deadlineId,
                    'deadline_type' => 'notice_to_quit_cure_period_end',
                    'due_at' => $due,
                    'status' => 'pending',
                ],
            ],
            'new_phase' => 'pre_filing',
        ];
    }

    private function insertCaseEvent(ProcedureContext $ctx, string $eventType, array $payload): array
    {
        $id = $ctx->emitCaseEvent($eventType, $payload);

        return [
            'id' => $id,
            'event_type' => $eventType,
            'occurred_at' => $ctx->nowIso,
        ];
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
