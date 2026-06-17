<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;
use RuntimeException;

final class ProcessServiceEventsController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * POST /cases/{id}/process-service-events
     * ============================================================ */
    public function create(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $caseId = $req['params']['id'] ?? null;
        $body   = $req['body'] ?? null;

        if (!is_string($caseId) || !is_array($body)) {
            return $this->badRequest();
        }

        $propertyId = $body['property_id'] ?? null;
        if (!is_string($propertyId) || !preg_match('/^[0-9a-fA-F-]{36}$/', $propertyId)) {
            return $this->validationError('property_id is required');
        }

        $method = $body['service_method'] ?? null;
        if (!is_string($method) || !in_array($method, self::SERVICE_METHODS, true)) {
            return $this->validationError('invalid service_method');
        }

        $metadata = $body['metadata'] ?? [];
        if (!is_array($metadata)) {
            return $this->validationError('metadata must be object');
        }

        $this->validateMetadata($method, $metadata);

        $st = $this->db->prepare(
            <<<SQL
            INSERT INTO process_service_events (
                tenant_id,
                case_id,
                property_id,
                served_actor_id,
                served_name,
                service_method,
                service_date,
                service_time,
                service_location,
                server_name,
                outcome,
                failure_reason,
                metadata,
                created_by
            ) VALUES (
                :tenant,
                :case,
                :property,
                :served_actor,
                :served_name,
                :method,
                :date,
                :time,
                :location,
                :server,
                :outcome,
                :failure,
                :metadata,
                :by
            )
            RETURNING id
            SQL
        );

        $st->execute([
            ':tenant' => $ctx->tenantId,
            ':case'   => $caseId,
            ':property' => $propertyId,
            ':served_actor' => $body['served_actor_id'] ?? null,
            ':served_name'  => $body['served_name'],
            ':method' => $method,
            ':date'   => $body['service_date'],
            ':time'   => $body['service_time'] ?? null,
            ':location' => $body['service_location'] ?? null,
            ':server' => $body['server_name'] ?? null,
            ':outcome'=> $body['outcome'],
            ':failure'=> $body['failure_reason'] ?? null,
            ':metadata' => json_encode($metadata),
            ':by' => $ctx->actorId,
        ]);

        return $this->created([
            'process_service_event_id' => $st->fetchColumn(),
        ]);
    }

    /* ============================================================
     * GET /process-service-events/{id}
     * ============================================================ */
    public function get(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $id = $req['params']['event_id'] ?? null;
        if (!is_string($id)) return $this->badRequest();

        $st = $this->db->prepare(
            <<<SQL
            SELECT *
            FROM process_service_events
            WHERE id = :id
              AND tenant_id = :tenant
            SQL
        );
        $st->execute([
            ':id' => $id,
            ':tenant' => $ctx->tenantId,
        ]);

        $event = $st->fetch(PDO::FETCH_ASSOC);
        if (!$event) return $this->notFound();

        $event['evidence'] = $this->loadEvidence($id, $ctx->tenantId);

        return $this->ok(['event' => $event]);
    }

    /* ============================================================
     * GET /cases/{id}/process-service-events
     * ============================================================ */
    public function listForCase(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $caseId = $req['params']['id'] ?? null;
        if (!is_string($caseId)) return $this->badRequest();

        $st = $this->db->prepare(
            <<<SQL
            SELECT *
            FROM process_service_events
            WHERE tenant_id = :tenant
              AND case_id = :case
            ORDER BY service_date DESC, created_at DESC
            SQL
        );
        $st->execute([
            ':tenant' => $ctx->tenantId,
            ':case'   => $caseId,
        ]);

        $events = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($events as &$e) {
            $e['evidence'] = $this->loadEvidence($e['id'], $ctx->tenantId);
        }

        return $this->ok(['events' => $events]);
    }

    /* ============================================================
     * Evidence loader
     * ============================================================ */
    private function loadEvidence(string $eventId, string $tenantId): array
    {
        $st = $this->db->prepare(
            <<<SQL
            SELECT document_id, role, metadata
            FROM process_service_event_evidence
            WHERE process_service_event_id = :id
              AND tenant_id = :tenant
            ORDER BY created_at
            SQL
        );
        $st->execute([
            ':id' => $eventId,
            ':tenant' => $tenantId,
        ]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ============================================================
     * Validation
     * ============================================================ */
    private function validateMetadata(string $method, array $metadata): void
    {
        $required = self::METADATA_RULES[$method] ?? [];
        foreach ($required as $key) {
            if (!array_key_exists($key, $metadata)) {
                throw new RuntimeException(
                    "metadata.$key is required for $method"
                );
            }
        }
    }

    /* ============================================================
    * POST /process-service-events/{event_id}/evidence
    * ============================================================ */
    public function attachEvidence(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $eventId = $req['params']['event_id']
            ?? $req['params']['id']
            ?? null;
        $body    = $req['body'] ?? null;

        if (!is_string($eventId) || !is_array($body)) {
            return $this->badRequest();
        }

        $documentId = $body['document_id'] ?? null;
        $role       = $body['role'] ?? null;
        $metadata   = $body['metadata'] ?? null;

        if (!is_string($documentId) || !is_string($role)) {
            return $this->validationError('document_id and role are required');
        }

        if ($metadata !== null && !is_array($metadata)) {
            return $this->validationError('metadata must be an object');
        }

        // Ensure event exists and belongs to tenant
        $check = $this->db->prepare(
            "SELECT id
            FROM process_service_events
            WHERE id = :id
                AND tenant_id = :tenant"
        );
        $check->execute([
            ':id'     => $eventId,
            ':tenant' => $ctx->tenantId,
        ]);

        if (!$check->fetchColumn()) {
            return $this->notFound();
        }

        $st = $this->db->prepare(
            <<<SQL
            INSERT INTO process_service_event_evidence (
                tenant_id,
                process_service_event_id,
                document_id,
                role,
                metadata,
                created_by
            ) VALUES (
                :tenant,
                :event,
                :document,
                :role,
                :metadata,
                :by
            )
            SQL
        );

        $st->execute([
            ':tenant'   => $ctx->tenantId,
            ':event'    => $eventId,
            ':document' => $documentId,
            ':role'     => $role,
            ':metadata' => $metadata !== null ? json_encode($metadata) : null,
            ':by'       => $ctx->actorId,
        ]);

        return $this->created([
            'status' => 'attached',
        ]);
    }


    /* ============================================================
     * Helpers
     * ============================================================ */
    private const SERVICE_METHODS = [
        'personal_service',
        'substitute_service',
        'certified_mail',
        'posted_on_property',
        'courier_delivery',
    ];

    private const METADATA_RULES = [
        'personal_service' => ['recipient_relationship'],
        'substitute_service' => ['recipient_name', 'recipient_relationship'],
        'certified_mail' => ['tracking_number', 'carrier'],
        'posted_on_property' => ['posting_location'],
        'courier_delivery' => ['courier_company'],
    ];

    private function ok(array $b): array {
        return ['status'=>200,'headers'=>['Content-Type'=>'application/json'],'body'=>$b];
    }
    private function created(array $b): array {
        return ['status'=>201,'headers'=>['Content-Type'=>'application/json'],'body'=>$b];
    }
    private function badRequest(): array {
        return ['status'=>400,'headers'=>['Content-Type'=>'application/json'],'body'=>['error'=>'INVALID_REQUEST']];
    }
    private function unauthorized(): array {
        return ['status'=>401,'headers'=>['Content-Type'=>'application/json'],'body'=>['error'=>'AUTH_REQUIRED']];
    }
    private function notFound(): array {
        return ['status'=>404,'headers'=>['Content-Type'=>'application/json'],'body'=>['error'=>'NOT_FOUND']];
    }
    private function validationError(string $m): array {
        return ['status'=>422,'headers'=>['Content-Type'=>'application/json'],'body'=>['error'=>$m]];
    }
}
