<?php
declare(strict_types=1);

namespace App\Services\Procedures;

use App\Services\ProceduralAction;
use App\Services\ProcedureContext;
use PDO;

final class TrialPreparationProcedure implements ProceduralAction
{
    public function run(ProcedureContext $ctx): array
    {
        error_log('*** TrialPreparationProcedure RUN ' . __FILE__);

        $meta = is_array($ctx->larPayload['metadata'] ?? null) ? $ctx->larPayload['metadata'] : [];

        // Trial date is strongly recommended here; can be null if judge hasn't set it yet,
        // but deadlines anchored to trial_date won't instantiate without it.
        $trialDate = is_string($meta['trial_date'] ?? null) ? $meta['trial_date'] : null;
        if ($trialDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $trialDate)) {
            throw new \RuntimeException('INVALID_REQUEST');
        }

        // Gather anchors (best-effort)
        $anchors = [
            'trial_date'   => $trialDate,
            'service_date' => $this->findLatestDateFromEvent($ctx, 'lawsuit_served', 'service_date'),
            'hearing_date' => $this->findLatestDateFromEvent($ctx, 'initial_hearing_scheduled', 'hearing_date'),
        ];

        // Templates for this jurisdiction + track + phase
        $templates = $this->loadTemplates($ctx, 'AZ', 'az_trustee_sale_possession', 'az_trial_preparation');

        // Optional judge overrides:
        // metadata.deadline_overrides = {
        //   "witness_list": {"due_at":"2026-02-01"},
        //   "exhibit_list": {"anchor_key":"trial_date","offset_days":-3},
        //   "rfp": {"anchor_key":"service_date","offset_days":14}
        // }
        $overrides = $meta['deadline_overrides'] ?? null;
        if (!is_array($overrides)) $overrides = [];

        $created = [];

        foreach ($templates as $t) {
            $obKey = (string)$t['obligation_key'];

            $ov = $overrides[$obKey] ?? null;
            if (!is_array($ov)) $ov = [];

            // Determine due_at
            $dueAt = null;

            // Explicit due date wins
            $explicitDue = is_string($ov['due_at'] ?? null) ? $ov['due_at'] : null;
            if ($explicitDue !== null) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $explicitDue)) {
                    throw new \RuntimeException('INVALID_REQUEST');
                }
                $dueAt = $explicitDue . 'T00:00:00Z';
            } else {
                $anchorKey = is_string($ov['anchor_key'] ?? null) ? $ov['anchor_key'] : (string)$t['anchor_key'];
                $offsetDays = isset($ov['offset_days']) ? (int)$ov['offset_days'] : (int)$t['offset_days'];

                $anchorVal = $anchors[$anchorKey] ?? null;
                if ($anchorVal === null || $anchorVal === '') {
                    // If we cannot compute due date, skip instantiation (trial date not set yet).
                    // This allows you to run trial_preparation early and re-run later once trial date exists.
                    continue;
                }

                // anchorVal is YYYY-MM-DD
                $ts = strtotime($anchorVal . 'T00:00:00Z');
                $ts = $ts + ($offsetDays * 86400);
                $dueAt = gmdate('c', $ts);
            }

            $deadlineId = $this->uuid();
            $st = $ctx->db->prepare("
                INSERT INTO case_procedural_deadlines (
                    id, tenant_id, case_id, legal_action_request_id,
                    phase_key, obligation_key, display_name,
                    due_at, required, blocks_phase_key, status, payload
                ) VALUES (
                    :id, :tenant_id, :case_id, :lar_id,
                    :phase_key, :obligation_key, :display_name,
                    :due_at, :required, :blocks_phase_key, 'pending', :payload::jsonb
                )
            ");

            error_log('TEMPLATE REQUIRED RAW: ' . var_export($t['required'] ?? null, true));
            $required = $this->pgBool($t['required'] ?? null, true);
            error_log('REQUIRED AFTER NORMALIZE: ' . var_export($required, true));

            $st->bindValue(':id', $deadlineId);
            $st->bindValue(':tenant_id', $ctx->tenantId);
            $st->bindValue(':case_id', $ctx->caseId);
            $st->bindValue(':lar_id', $ctx->requestId);
            $st->bindValue(':phase_key', 'az_trial_preparation');
            $st->bindValue(':obligation_key', $obKey);
            $st->bindValue(':display_name', (string)$t['display_name']);
            $st->bindValue(':due_at', $dueAt);
            $st->bindValue(':required', $required, PDO::PARAM_BOOL);   // <-- THIS IS THE FIX
            $st->bindValue(
                ':blocks_phase_key',
                (isset($t['blocks_phase_key']) && trim((string)$t['blocks_phase_key']) !== '')
                    ? (string)$t['blocks_phase_key']
                    : null,
                PDO::PARAM_STR
            );
            $st->bindValue(':payload', json_encode([
                'template_id' => $t['id'],
                'anchor_key' => $ov['anchor_key'] ?? $t['anchor_key'],
                'offset_days' => $ov['offset_days'] ?? $t['offset_days'],
                'judge_override' => $explicitDue !== null || isset($ov['anchor_key']) || isset($ov['offset_days']),
            ], JSON_UNESCAPED_SLASHES), PDO::PARAM_STR);

            $st->execute();


            $created[] = [
                'deadline_id' => $deadlineId,
                'obligation_key' => $obKey,
                'due_at' => $dueAt,
            ];
        }

        $ctx->emitCaseEvent('trial_preparation_started', [
            'trial_date' => $trialDate,
            'created_deadlines' => count($created),
            'title' => 'Trial Preparation Started',
        ]);

        return [
            'executed_at' => $ctx->nowIso,
            'executed_by' => $ctx->actorId,
            'generated_documents' => [],
            'emitted_events' => [['event_type' => 'trial_preparation_started']],
            'created_deadlines' => $created,
            'new_phase' => 'trial_preparation',
        ];
    }

    private function pgBool(mixed $v, bool $default = true): bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v === 1;

        if (is_string($v)) {
            $s = strtolower(trim($v));
            if ($s === 't' || $s === 'true' || $s === '1' || $s === 'yes') return true;
            if ($s === 'f' || $s === 'false' || $s === '0' || $s === 'no') return false;
            if ($s === '') return $default;
        }

        return $default;
    }

    private function loadTemplates(ProcedureContext $ctx, string $stateCode, string $actionKey, string $phaseKey): array
    {
        $st = $ctx->db->prepare("
            SELECT id, obligation_key, display_name, anchor_key, offset_days, required, blocks_phase_key
            FROM procedural_deadline_templates
            WHERE state_code = :state
              AND legal_action_key = :action
              AND trigger_phase_key = :phase
            ORDER BY created_at ASC
        ");
        $st->execute([':state' => $stateCode, ':action' => $actionKey, ':phase' => $phaseKey]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function findLatestDateFromEvent(ProcedureContext $ctx, string $eventType, string $payloadKey): ?string
    {
        $st = $ctx->db->prepare("
            SELECT payload
            FROM case_events
            WHERE tenant_id = :tenant_id
              AND case_id = :case_id
              AND event_type = :event_type
            ORDER BY occurred_at DESC
            LIMIT 1
        ");
        $st->execute([
            ':tenant_id' => $ctx->tenantId,
            ':case_id' => $ctx->caseId,
            ':event_type' => $eventType,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $payload = is_string($row['payload'] ?? null) ? json_decode((string)$row['payload'], true) : null;
        if (!is_array($payload)) return null;

        $v = $payload[$payloadKey] ?? null;
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
        return $v;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
