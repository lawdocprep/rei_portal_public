<?php
declare(strict_types=1);

namespace App\Services\Documents;

use PDO;
use RuntimeException;

final class StatutoryReceiptDocumentFactory
{
    /**
     * Must match:
     * - document_types.type_key
     * - case_documents.document_type_key
     */
    public const DOCUMENT_TYPE_KEY = 'az_certified_mail_deemed_receipt';

    /**
     * Resolve or create statutory proof of certified-mail deemed receipt.
     *
     * Idempotent across:
     * - cron overlap
     * - replay
     */
    public static function resolveOrCreate(
        PDO $db,
        string $tenantId,
        string $caseId,
        string $actionRequestId,
        string $deliveryEventId,
        string $occurredAtIso
    ): string {
        /*
         * Fast path: existing statutory proof
         */
        $st = $db->prepare("
            SELECT id
            FROM case_documents
            WHERE tenant_id = :tenant
              AND case_id = :case
              AND source_action_request_id = :arid
              AND document_type_key = :dtype
            LIMIT 1
        ");
        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
            ':arid'   => $actionRequestId,
            ':dtype'  => self::DOCUMENT_TYPE_KEY,
        ]);

        if ($id = $st->fetchColumn()) {
            return (string)$id;
        }

        /*
         * Create FINAL statutory proof document
         * IMPORTANT: finalized_at MUST be set when status = 'final'
         */
        $ins = $db->prepare("
            INSERT INTO case_documents (
                tenant_id,
                case_id,
                document_type_key,
                title,
                status,
                is_evidence,
                created_at,
                created_by,
                finalized_at,
                metadata,
                source_action_request_id
            ) VALUES (
                :tenant,
                :case,
                :dtype,
                'Certified Mail – Deemed Received (Statutory)',
                'final',
                false,
                :created_at,
                NULL,
                :finalized_at,
                :metadata,
                :arid
            )
            RETURNING id
        ");

        $ins->execute([
            ':tenant'       => $tenantId,
            ':case'         => $caseId,
            ':dtype'        => self::DOCUMENT_TYPE_KEY,
            ':created_at'   => $occurredAtIso,
            ':finalized_at' => $occurredAtIso,
            ':arid'         => $actionRequestId,
            ':metadata'     => json_encode([
                'delivery_event_id' => $deliveryEventId,
                'basis'             => 'statutory_deemed_receipt',
                'occurred_at'       => $occurredAtIso,
            ], JSON_UNESCAPED_SLASHES),
        ]);

        $id = $ins->fetchColumn();
        if (!$id) {
            throw new RuntimeException('STATUTORY_RECEIPT_DOCUMENT_CREATE_FAILED');
        }

        return (string)$id;
    }
}
