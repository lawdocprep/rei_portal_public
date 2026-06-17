<?php
declare(strict_types=1);

namespace App\Auth;

use PDO;

final class IdempotencyGuard
{
    public function __construct(private PDO $db) {}

    /**
     * Reserve or replay an idempotent request.
     */
    public function checkOrReserve(array $req, AuthContext $ctx, string $endpointKey): array
    {
        $headers = $req['headers'] ?? [];

        $key = $headers['Idempotency-Key']
            ?? $headers['idempotency-key']
            ?? null;

        // No key → no idempotency, allow request
        if (!$key) {
            return ['mode' => 'pass'];
        }

        $rawBody     = $req['raw_body'] ?? '';
        $requestHash = hash('sha256', (string)$rawBody);

        // Lock row if it exists
        $sql = <<<SQL
SELECT
    request_hash,
    status,
    response_http_status,
    response_body
FROM api_idempotency_keys
WHERE tenant_id = :tenant_id
  AND idempotency_key = :key
FOR UPDATE
LIMIT 1
SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $ctx->tenantId,
            ':key'       => $key,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Existing key
        if ($row) {
            // Same key, different payload → hard conflict
            if (!hash_equals($row['request_hash'], $requestHash)) {
                return ['mode' => 'conflict'];
            }

            // Completed → replay stored response
            if ($row['status'] === 'completed') {
                return [
                    'mode'   => 'replay',
                    'status' => (int)$row['response_http_status'],
                    'body'   => $row['response_body']
                        ? json_decode($row['response_body'], true)
                        : null,
                ];
            }

            // Status = started → allow retry
            return ['mode' => 'reserved'];
        }

        // No row yet → create reservation
        $insert = <<<SQL
INSERT INTO api_idempotency_keys
    (tenant_id, idempotency_key, request_hash, status, created_at)
VALUES
    (:tenant_id, :key, :hash, 'started', now())
SQL;

        $stmt = $this->db->prepare($insert);
        $stmt->execute([
            ':tenant_id' => $ctx->tenantId,
            ':key'       => $key,
            ':hash'      => $requestHash,
        ]);

        return ['mode' => 'reserved'];
    }

    /**
     * Store the final response for an idempotent request.
     */
    public function storeResponse(array $req, AuthContext $ctx, string $endpointKey, array $resp): void
    {
        $headers = $req['headers'] ?? [];

        $key = $headers['Idempotency-Key']
            ?? $headers['idempotency-key']
            ?? null;

        if (!$key) {
            return;
        }

        $status = (int)($resp['status'] ?? 200);
        $body   = isset($resp['body'])
            ? json_encode($resp['body'], JSON_UNESCAPED_SLASHES)
            : null;

        $sql = <<<SQL
UPDATE api_idempotency_keys
SET status = 'completed',
    response_http_status = :status,
    response_body = :body,
    completed_at = now()
WHERE tenant_id = :tenant_id
  AND idempotency_key = :key
SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':status'    => $status,
            ':body'      => $body,
            ':tenant_id' => $ctx->tenantId,
            ':key'       => $key,
        ]);
    }

    /**
     * Dev-safety: clear abandoned started keys older than N minutes.
     * Optional, but recommended.
     */
    public function cleanupStale(int $minutes = 10): void
    {
        $sql = <<<SQL
DELETE FROM api_idempotency_keys
WHERE status = 'started'
  AND created_at < now() - (:mins || ' minutes')::interval
SQL;

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':mins' => $minutes]);
    }
}
