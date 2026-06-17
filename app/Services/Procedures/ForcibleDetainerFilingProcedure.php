<?php
declare(strict_types=1);

namespace App\Services\Procedures;

use RuntimeException;
use App\Services\ProceduralAction;
use App\Services\ProcedureContext;

use App\Services\Filing\FilingArtifactAssemblyService;
use App\Services\Validation\FilingArtifactValidationService;
use App\Services\ArtifactFilingReadinessService;
use App\Services\Filing\FilingFreezeService;

final class ForcibleDetainerFilingProcedure implements ProceduralAction
{
    public function run(ProcedureContext $ctx): array
    {
        /*
        ------------------------------------------------------------
        Phase 20 — Deterministic Filing Execution
        ------------------------------------------------------------
        */

        $startedTx = false;

        if (!$ctx->db->inTransaction()) {
            $ctx->db->beginTransaction();
            $startedTx = true;
        }

        try {

            /*
            ------------------------------------------------------------
            1) Load canonical intent snapshot
            ------------------------------------------------------------
            */

            $intent = $this->loadFilingIntent($ctx);

            $actionType =
                $intent['action_type']
                ?? $ctx->requestedAction
                ?? 'az_forcible_detainer';

            if (!is_string($actionType) || $actionType === '') {
                throw new RuntimeException('ACTION_TYPE_MISSING');
            }

            /*
            ------------------------------------------------------------
            2) Assemble artifacts (deterministic)
            ------------------------------------------------------------
            */

            $assemblyService =
                new FilingArtifactAssemblyService($ctx->db);

            $artifacts =
                $assemblyService->assemble(
                    tenantId: $ctx->tenantId,
                    caseId: $ctx->caseId,
                    legalActionId: $ctx->legalActionId,
                    actorId: $ctx->actorId,
                    actionType: $actionType
                );

            /*
            ------------------------------------------------------------
            3) Structural validation
            ------------------------------------------------------------
            */

            $validationService =
                new FilingArtifactValidationService();

            $validationResult =
                $validationService->validateBundle(
                    artifacts: $artifacts,
                    intent: $intent,
                    traceEnabled: false
                );

            if (
                ($validationResult['bundle_validation']['structural_status']
                    ?? null) !== 'valid'
            ) {
                throw new RuntimeException(
                    'STRUCTURAL_VALIDATION_FAILED'
                );
            }

            /*
            ------------------------------------------------------------
            4) Filing readiness evaluation
            ------------------------------------------------------------
            */

            $readinessService =
                new ArtifactFilingReadinessService(
                    fn(array $intent, array $artifacts, string $actionType)
                        => $validationResult['bundle_validation']
                );

            $readiness =
                $readinessService->evaluate(
                    intent: $intent,
                    artifacts: $validationResult['artifacts'],
                    actionType: $actionType
                );

            if (
                ($readiness['ready_for_filing'] ?? false) !== true
            ) {
                $reasons =
                    $readiness['blocking_reasons']
                    ?? [];

                throw new RuntimeException(
                    'NOT_READY_FOR_FILING:' .
                    json_encode($reasons)
                );
            }

            /*
            ------------------------------------------------------------
            5) Commit / freeze filing bundle
            ------------------------------------------------------------
            */

            $freezeService =
                new FilingFreezeService($ctx->db);

            $freezeResult =
                $freezeService->commitFiling(
                    tenantId: $ctx->tenantId,
                    caseId: $ctx->caseId,
                    legalActionId: $ctx->legalActionId,
                    actorId: $ctx->actorId
                );

            /*
            ------------------------------------------------------------
            6) Emit canonical filing event
            ------------------------------------------------------------
            */

            $ctx->emitCaseEvent(
                'forcible_detainer_filed',
                [
                    'title' => 'Forcible Detainer Filed',
                    'status' => 'completed',
                    'bundle_id' =>
                        $freezeResult['bundle_id'] ?? null,
                    'action_type' => $actionType,
                ]
            );

            if ($startedTx) {
                $ctx->db->commit();
            }

            /*
            ------------------------------------------------------------
            Return deterministic procedure result
            ------------------------------------------------------------
            */

            return [

                'executed_at' => $ctx->nowIso,

                'executed_by' => $ctx->actorId,

                'generated_documents' =>
                    $freezeResult['documents']
                    ?? [],

                'emitted_events' => [
                    [
                        'event_type' =>
                            'forcible_detainer_filed'
                    ]
                ],

                'created_deadlines' =>
                    $freezeResult['deadlines']
                    ?? [],

                'new_phase' => 'filing',
            ];

        } catch (\Throwable $e) {

            if ($startedTx && $ctx->db->inTransaction()) {
                $ctx->db->rollBack();
            }

            throw $e;
        }
    }

    /*
    ============================================================
    Load Filing Intent Snapshot
    ============================================================
    */

    private function loadFilingIntent(
        ProcedureContext $ctx
    ): array {

        $st = $ctx->db->prepare("
            SELECT intent_snapshot
            FROM filing_intents
            WHERE tenant_id = :tenant
              AND case_id = :case
              AND legal_action_id = :legal_action
            ORDER BY created_at DESC
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $ctx->tenantId,
            ':case' => $ctx->caseId,
            ':legal_action' => $ctx->legalActionId,
        ]);

        $json = $st->fetchColumn();

        if (!$json) {
            throw new RuntimeException(
                'FILING_INTENT_NOT_FOUND'
            );
        }

        $intent =
            json_decode(
                (string)$json,
                true
            );

        if (!is_array($intent)) {
            throw new RuntimeException(
                'FILING_INTENT_INVALID'
            );
        }

        return $intent;
    }
}