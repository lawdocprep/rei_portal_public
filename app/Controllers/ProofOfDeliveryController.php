<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\Guard\CaseMutationGuard;
use App\Services\Events\CaseEventService;
use PDO;
use RuntimeException;

final class ProofOfDeliveryController
{
    private CaseEventService $events;

    public function __construct(private PDO $db)
    {
        $this->events = new CaseEventService($db);
    }

    public function upload(string $serviceTaskId, AuthContext $ctx): array
    {
        if (!$this->isUuid($serviceTaskId)) {
            return $this->json(400, ['error' => 'INVALID_SERVICE_TASK_ID']);
        }

        $this->db->beginTransaction();

        try {

            /* --------------------------------------------------------
            * 1) Resolve tenant + case
            * -------------------------------------------------------- */
            $st = $this->db->prepare("
                SELECT tenant_id, case_id
                FROM demand_delivery_events
                WHERE service_task_id = :id
                AND delivery_method = 'certified_mail'
                LIMIT 1
            ");
            $st->execute([':id' => $serviceTaskId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->db->rollBack();
                return $this->json(404, ['error' => 'SERVICE_TASK_NOT_FOUND']);
            }

            $tenantId = (string)$row['tenant_id'];
            $caseId   = (string)$row['case_id'];
            $actorId  = (string)$ctx->actorId;

            $body = $_POST ?? [];

            /* ============================================================
            * Phase 17 Freeze Guard
            * ============================================================ */
            $guard = new CaseMutationGuard($this->db);
            $guard->assertCaseMutable($tenantId, $caseId);

            /* --------------------------------------------------------
            * 2) Store evidence document
            * -------------------------------------------------------- */
            $caseDocumentId = $this->storeEvidenceDocument(
                tenantId: $tenantId,
                caseId: $caseId
            );

            /* --------------------------------------------------------
            * 3) Update delivery event
            * -------------------------------------------------------- */
            $upd = $this->db->prepare("
                UPDATE demand_delivery_events
                SET
                delivery_status = 'delivered',
                delivered_at = :delivered_at,
                proof_case_document_id = :doc_id
                WHERE service_task_id = :task_id
                AND delivery_method = 'certified_mail'
            ");

            $upd->execute([
                ':delivered_at' => $body['delivered_at'] ?? null,
                ':doc_id'       => $caseDocumentId,
                ':task_id'      => $serviceTaskId,
            ]);

            /* --------------------------------------------------------
            * 4) Emit canonical event
            * -------------------------------------------------------- */
            $payload = [
                'service_task_id'   => $serviceTaskId,
                'delivered_at'      => $body['delivered_at'] ?? null,
                'carrier'           => 'usps',
                'tracking_number'   => $body['tracking_number'] ?? null,
                'proof_document_id' => $caseDocumentId,
                'source'            => 'user_upload',
            ];

            $eventId = $this->events->emitEvent(
                'demand_delivery.certified_mail_delivered',
                $payload,
                $tenantId,
                $caseId,
                $actorId
            );

            if (!is_string($eventId) || $eventId === '') {
                throw new RuntimeException('CERTIFIED_MAIL_DELIVERED_EVENT_FAILED');
            }

            $this->db->commit();

            return $this->json(200, [
                'status'   => 'ok',
                'event_id' => $eventId
            ]);

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /* ============================================================ */

    private function storeEvidenceDocument(string $tenantId, string $caseId): string
    {
        // Existing document ingestion infrastructure.
        return '00000000-0000-0000-0000-000000000000';
    }

    private function json(int $status, array $body): array
    {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
        ];
    }

    private function isUuid(?string $v): bool
    {
        if (!$v) return false;

        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $v
        );
    }
}