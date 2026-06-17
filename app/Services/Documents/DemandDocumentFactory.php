<?php
declare(strict_types=1);

namespace App\Services\Documents;

use App\Services\ProcedureContext;
use RuntimeException;
use PDOException;

final class DemandDocumentFactory implements DocumentFactoryInterface
{
    public function resolveOrCreate(ProcedureContext $ctx): string
    {
        $documentTypeKey = $this->resolveDocumentTypeKey($ctx);

        /*
         * Fast path: resolve existing document
         */
        $select = $ctx->db->prepare("
            SELECT id
            FROM case_documents
            WHERE tenant_id = :tenant
              AND case_id = :case
              AND source_action_request_id = :arid
              AND document_type_key = :dtype
            LIMIT 1
        ");

        $select->execute([
            ':tenant' => $ctx->tenantId,
            ':case'   => $ctx->caseId,
            ':arid'   => $ctx->requestId,
            ':dtype'  => $documentTypeKey,
        ]);

        $existingId = $select->fetchColumn();

        if ($existingId !== false) {
            return (string)$existingId;
        }

        /*
         * Slow path: create document
         * IMPORTANT: use raw SQL to bypass pgsql bind protocol entirely
         */
        try {

            $sql = sprintf(
                "
                INSERT INTO case_documents (
                    tenant_id,
                    case_id,
                    document_type_key,
                    title,
                    created_by,
                    metadata,
                    source_action_request_id
                ) VALUES (
                    %s, %s, %s, %s, %s, %s, %s
                )
                RETURNING id
                ",
                $ctx->db->quote($ctx->tenantId),
                $ctx->db->quote($ctx->caseId),
                $ctx->db->quote($documentTypeKey),
                $ctx->db->quote('Demand for Possession'),
                $ctx->db->quote($ctx->actorId),
                $ctx->db->quote(json_encode([
                    'action_request_id' => $ctx->requestId,
                    'procedure'         => $ctx->requestedAction,
                    'created_at'        => $ctx->nowIso,
                    'render_version'    => 1,
                ])),
                $ctx->db->quote($ctx->requestId)
            );

            $st = $ctx->db->query($sql);
            $documentId = $st->fetchColumn();

            if ($documentId === false) {
                throw new RuntimeException('DEMAND_DOCUMENT_ID_NOT_RETURNED');
            }

            return (string)$documentId;

        } catch (PDOException $e) {

            /*
             * Handle unique-violation race
             */
            if ($e->getCode() === '23505') {

                $select->execute([
                    ':tenant' => $ctx->tenantId,
                    ':case'   => $ctx->caseId,
                    ':arid'   => $ctx->requestId,
                    ':dtype'  => $documentTypeKey,
                ]);

                $id = $select->fetchColumn();

                if ($id !== false) {
                    return (string)$id;
                }
            }

            throw new RuntimeException(
    'DEMAND_DOCUMENT_CREATE_FAILED: ' . $e->getMessage()
);
            
        }
    }

    /**
     * Resolve the correct document_type_key from the expectations catalog
     */
    private function resolveDocumentTypeKey(ProcedureContext $ctx): string
    {
        $st = $ctx->db->prepare("
            SELECT document_type_key
            FROM action_document_expectations
            WHERE state_code = 'AZ'
              AND legal_action_key = 'az_trustee_sale_possession'
              AND requested_action = :requested_action
              AND phase_key = :phase_key
            LIMIT 1
        ");

        $st->execute([
            ':requested_action' => $ctx->requestedAction,
            ':phase_key'        => $ctx->requestedAction
        ]);

        $key = $st->fetchColumn();

        if ($key === false) {
            throw new RuntimeException(
                'DEMAND_DOCUMENT_TYPE_NOT_CONFIGURED'
            );
        }

        return (string)$key;
    }

    /**
     * Required by DocumentFactoryInterface
     * (kept for compatibility — not used internally anymore)
     */
    public function getDocumentTypeKey(): string
    {
        return 'demand_for_possession';
    }
}