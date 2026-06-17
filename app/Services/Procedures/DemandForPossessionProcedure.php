<?php
declare(strict_types=1);

namespace App\Services\Procedures;

use App\Services\ProceduralAction;
use App\Services\ProcedureContext;
use App\Services\Documents\DemandDocumentFactory;
use RuntimeException;

final class DemandForPossessionProcedure implements ProceduralAction
{
    public function run(ProcedureContext $ctx): array
    {
        /*
         * Determine requested delivery methods from LAR metadata.
         * These are INTENT signals only — task creation is handled later.
         */
        $methods = [];
        $meta = $ctx->larPayload['metadata'] ?? null;

        if (is_array($meta)
            && isset($meta['delivery_methods'])
            && is_array($meta['delivery_methods'])
        ) {
            $methods = array_values(
                array_unique(
                    array_filter(
                        array_map('strval', $meta['delivery_methods'])
                    )
                )
            );
        }

        /*
        * Default delivery methods if none were specified.
        * Posting cannot be created initially — it is only created
        * after personal service fails.
        */
        if (!$methods) {
            $methods = ['personal_service', 'certified_mail'];

        }

        /*
         * Validate that the case is linked to an active property.
         * This is a hard phase blocker.
         */
        if ($this->loadCasePropertyAddress($ctx) === null) {
            throw new RuntimeException('PHASE_BLOCKED');
        }

        /*
         * EXECUTION-TIME GUARANTEE:
         * Resolve or create the canonical demand document.
         * This MUST occur before any delivery fan-out.
         */
        $documentFactory = new DemandDocumentFactory();
        $documentId = $documentFactory->resolveOrCreate($ctx);

        $proceduralPhase = 'az_demand_service';
        $uiPhase         = 'az_prefiling';

        return [
            /*
             * Canonical execution metadata
             */
            'executed_at' => $ctx->nowIso,
            'executed_by' => $ctx->actorId,

            /*
             * Authoritative generated document
             */
            'generated_documents' => [
                [
                    'case_document_id'       => $documentId,
                    'document_type_key' => $documentFactory->getDocumentTypeKey(),
                ],
            ],

            /*
             * Procedural engine phase
             */
            'procedural_phase_key' => $proceduralPhase,

            /*
             * UI-only phase hint
             */
            'new_phase' => $uiPhase,

            /*
             * Demand is incomplete until service proof exists
             */
            'is_complete' => false,

            /*
             * Intent-only event — executor expands & persists
             */
            'emitted_events' => [
                [
                    'event_type' => 'demand_for_possession_delivery',
                    'payload' => [
                        'title'             => 'Demand for Possession Initiated',
                        'status'            => 'pending',
                        'delivery_methods'  => $methods,
                        'action_request_id' => $ctx->requestId,
                        'action_type'       => 'az_demand_for_possession',
                        'phase_key'         => $proceduralPhase,
                        'ui_phase_key'      => $uiPhase,
                    ],
                ],
            ],
        ];
    }

    /**
     * Ensure the case is associated with an active property.
     * This is a true execution-time prerequisite.
     */
    private function loadCasePropertyAddress(ProcedureContext $ctx): ?array
    {
        $st = $ctx->db->prepare("
            SELECT
                p.address_line1,
                p.address_line2,
                p.city,
                p.state,
                p.zip
            FROM case_properties cp
            JOIN properties p
              ON p.id = cp.property_id
             AND p.tenant_id = cp.tenant_id
            WHERE cp.tenant_id = :tenant_id
              AND cp.case_id   = :case_id
              AND (cp.ends_at IS NULL OR cp.ends_at > now())
            ORDER BY cp.starts_at DESC
            LIMIT 1
        ");

        $st->execute([
            ':tenant_id' => $ctx->tenantId,
            ':case_id'   => $ctx->caseId,
        ]);

        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
