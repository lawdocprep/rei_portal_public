<?php
declare(strict_types=1);

namespace App\Services\Events;

use PDO;
use RuntimeException;

final class CaseEventService
{
    public function __construct(private PDO $db) {}

    public function emitEvent(
        string $type,
        array $payload,
        string $tenantId,
        string $caseId,
        string $actorId,
        ?string $causationId = null,
        ?string $correlationId = null
    ): ?string {

        $startedTx = false;

        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $startedTx = true;
        }

        try {

            // ------------------------------------------------------------
            // 1) Case-level advisory lock (prevents concurrent writers)
            // ------------------------------------------------------------
            $lock = $this->db->prepare("
                SELECT pg_advisory_xact_lock(
                    ('x' || substr(replace(:tenant_id,'-',''),1,8))::bit(32)::int,
                    ('x' || substr(replace(:case_id,'-',''),1,8))::bit(32)::int
                )
            ");

            $lock->execute([
                ':tenant_id' => $tenantId,
                ':case_id'   => $caseId
            ]);

            // ------------------------------------------------------------
            // 2) Filing freeze guard
            // ------------------------------------------------------------
            $freezeCheck = $this->db->prepare("
                SELECT filing_committed_at, case_event_cursor
                FROM cases
                WHERE tenant_id = :tenant
                AND id = :case_id
                FOR UPDATE
            ");

            $freezeCheck->execute([
                ':tenant'  => $tenantId,
                ':case_id' => $caseId
            ]);

            $frozenAt = $freezeCheck->fetchColumn();

            if ($frozenAt !== false && $frozenAt !== null) {
                throw new RuntimeException('CASE_EVENT_APPEND_AFTER_FILING_FREEZE');
            }

            // ------------------------------------------------------------
            // 3) Idempotency key
            // ------------------------------------------------------------
            if (
                $type === 'legal_action_request'
                && is_string($payload['requested_action'] ?? null)
                && $payload['requested_action'] !== ''
            ) {
                $idempotencyKey =
                    $type . ':' .
                    $caseId . ':' .
                    $payload['requested_action'] . ':' .
                    ($payload['transition_spawn_token'] ?? bin2hex(random_bytes(8)));
            } else {
                $idempotencyKey =
                    $type . ':' .
                    ($payload['legal_action_id'] ?? $caseId);
            }

            // ------------------------------------------------------------
            // 4) Allocate next sequence
            // ------------------------------------------------------------
            $seqStmt = $this->db->prepare("
                SELECT COALESCE(MAX(case_sequence),0) + 1
                FROM case_events
                WHERE tenant_id = :tenant
                AND case_id = :case_id
            ");

            $seqStmt->execute([
                ':tenant'  => $tenantId,
                ':case_id' => $caseId
            ]);

            $caseSequence = (int)$seqStmt->fetchColumn();

            if ($caseSequence <= 0) {
                throw new RuntimeException('CASE_SEQUENCE_ALLOC_FAILED');
            }

            // ------------------------------------------------------------
            // 5) Resolve event UUID
            // Allows caller-controlled IDs for legal_action_request spawning
            // ------------------------------------------------------------
            $eventId =
                (is_string($payload['id'] ?? null)
                    && $payload['id'] !== '')
                ? (string)$payload['id']
                : sprintf(
                    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    random_int(0,0xffff), random_int(0,0xffff),
                    random_int(0,0xffff),
                    random_int(0,0x0fff) | 0x4000,
                    random_int(0,0x3fff) | 0x8000,
                    random_int(0,0xffff),
                    random_int(0,0xffff),
                    random_int(0,0xffff)
                );

            // ------------------------------------------------------------
            // 6) Encode payload
            // ------------------------------------------------------------
            $payloadJson = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            if (!is_string($payloadJson)) {
                throw new RuntimeException('EVENT_PAYLOAD_JSON_ENCODE_FAILED');
            }

            // ------------------------------------------------------------
            // 7) Load previous hash
            // ------------------------------------------------------------
            $prevStmt = $this->db->prepare("
                SELECT event_hash
                FROM case_events
                WHERE tenant_id = :tenant
                AND case_id = :case_id
                ORDER BY case_sequence DESC
                LIMIT 1
            ");

            $prevStmt->execute([
                ':tenant'  => $tenantId,
                ':case_id' => $caseId
            ]);

            $previousHash = $prevStmt->fetchColumn();

            if (!is_string($previousHash) || $previousHash === '') {
                $previousHash = null;
            }

            // ------------------------------------------------------------
            // 8) Compute event hash (ledger chain)
            // ------------------------------------------------------------
            $hashInput = implode('|', [
                $tenantId,
                $caseId,
                $caseSequence,
                $type,
                $previousHash ?? '',
                $payloadJson
            ]);

            $eventHash = hash(
                'sha256',
                ($previousHash ?? '') .
                $caseId .
                $caseSequence .
                $type .
                $payloadJson
            );

            if ($eventHash === '') {
                throw new RuntimeException('EVENT_HASH_COMPUTE_FAILED');
            }

            // ------------------------------------------------------------
            // 9) Insert event
            // ------------------------------------------------------------

            $insert = $this->db->prepare("
                INSERT INTO case_events (
                    id,
                    tenant_id,
                    case_id,
                    case_sequence,
                    event_type,
                    occurred_at,
                    actor_id,
                    payload,
                    idempotency_key,
                    causation_id,
                    correlation_id,
                    previous_event_hash,
                    event_hash,
                    created_at
                )
                SELECT
                    :id::uuid,
                    :tenant::uuid,
                    :case_id::uuid,
                    :seq::int,
                    :type::text,
                    NOW(),
                    :actor::uuid,
                    CAST(:payload AS jsonb),
                    :key::text,
                    :causation::uuid,
                    :correlation::uuid,
                    :prev_hash::text,
                    encode(
                        digest(
                            COALESCE(:prev_hash::text,'') ||
                            :case_id::text ||
                            :seq::text ||
                            :type::text ||
                            jsonb_strip_nulls(CAST(:payload AS jsonb))::text,
                            'sha256'
                        ),
                        'hex'
                    ),
                    NOW()
                ON CONFLICT (tenant_id, idempotency_key)
                DO NOTHING
            ");

            $insert->execute([
                ':id'          => $eventId,
                ':tenant'      => $tenantId,
                ':case_id'     => $caseId,
                ':seq'         => $caseSequence,
                ':type'        => $type,
                ':actor'       => $actorId,
                ':payload'     => $payloadJson,
                ':key'         => $idempotencyKey,
                ':causation'   => $causationId,
                ':correlation' => $correlationId,
                ':prev_hash'   => $previousHash,
            ]);

            // ------------------------------------------------------------
            // 10) Idempotent retry handling
            // ------------------------------------------------------------
            if ($insert->rowCount() === 0) {

                $lookup = $this->db->prepare("
                    SELECT id
                    FROM case_events
                    WHERE tenant_id = :tenant
                    AND case_id = :case_id
                    AND idempotency_key = :key
                    LIMIT 1
                ");

                $lookup->execute([
                    ':tenant'  => $tenantId,
                    ':case_id' => $caseId,
                    ':key'     => $idempotencyKey
                ]);

                $existingId = $lookup->fetchColumn();

                if ($startedTx) {
                    $this->db->commit();
                }

                if (is_string($existingId) && $existingId !== '') {
                    return $existingId;
                }

                throw new RuntimeException('IDEMPOTENT_EVENT_LOOKUP_FAILED');
            }

            if ($startedTx) {
                $this->db->commit();
            }

            return $eventId;

        } catch (\Throwable $e) {

            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }
}