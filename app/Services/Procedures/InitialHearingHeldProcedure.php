<?php
declare(strict_types=1);

namespace App\Services\Procedures;

use App\Services\ProceduralAction;
use App\Services\ProcedureContext;

final class InitialHearingHeldProcedure implements ProceduralAction
{
    public function run(ProcedureContext $ctx): array
    {
        $meta = is_array($ctx->larPayload['metadata'] ?? null) ? $ctx->larPayload['metadata'] : [];

        $outcome = is_string($meta['outcome'] ?? null) ? $meta['outcome'] : '';
        if (!in_array($outcome, ['judgment_entered','trial_set'], true)) {
            throw new \RuntimeException('INVALID_REQUEST');
        }

        // Record hearing held (always)
        $ctx->emitCaseEvent('initial_hearing_held', [
            'outcome' => $outcome,
            'title' => 'Initial Hearing Held',
        ]);

        // Branch
        if ($outcome === 'judgment_entered') {
            $ctx->emitCaseEvent('judgment_entered', [
                'source' => 'initial_hearing',
                'title' => 'Judgment Entered',
                'prevailing_party' => $meta['prevailing_party'] ?? 'plaintiff',
            ]);

            return [
                'executed_at' => $ctx->nowIso,
                'executed_by' => $ctx->actorId,
                'generated_documents' => [],
                'emitted_events' => [
                    ['event_type' => 'initial_hearing_held'],
                    ['event_type' => 'judgment_entered'],
                ],
                'created_deadlines' => [],
                'new_phase' => 'judgment',
            ];
        }

        // Trial set path
        $trialDate = is_string($meta['trial_date'] ?? null) ? $meta['trial_date'] : null;
        if ($trialDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $trialDate)) {
            throw new \RuntimeException('INVALID_REQUEST');
        }

        $ctx->emitCaseEvent('trial_set', [
            'trial_date' => $trialDate, // may be null; can be set later
            'title' => 'Trial Set',
        ]);

        return [
            'executed_at' => $ctx->nowIso,
            'executed_by' => $ctx->actorId,
            'generated_documents' => [],
            'emitted_events' => [
                ['event_type' => 'initial_hearing_held'],
                ['event_type' => 'trial_set'],
            ],
            'created_deadlines' => [],
            'new_phase' => 'trial_preparation',
        ];
    }
}
