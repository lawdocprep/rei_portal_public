<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;
use RuntimeException;

final class OwnershipAdjudicationController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * GET /properties/{id}/ownership/validate
     * ============================================================ */
    public function validate(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $propertyId = $req['params']['id'];

        $st = $this->db->prepare(
            <<<SQL
            SELECT
                id,
                actor_id,
                percentage_interest,
                ownership_status
            FROM property_relationships
            WHERE tenant_id = :tenant_id
              AND property_id = :property_id
              AND role = 'owner'
              AND ends_at IS NULL
            SQL
        );

        $st->execute([
            ':tenant_id'   => $ctx->tenantId,
            ':property_id' => $propertyId,
        ]);

        $owners = $st->fetchAll(PDO::FETCH_ASSOC);

        $total = 0.0;
        $missing = [];

        foreach ($owners as $o) {
            if ($o['percentage_interest'] === null) {
                $missing[] = $o['actor_id'];
                continue;
            }
            $total += (float) $o['percentage_interest'];
        }

        $status =
            !empty($missing) ? 'incomplete'
            : ($total > 100.0 ? 'overallocated'
            : ($total < 100.0 ? 'underallocated'
            : 'valid'));

        return $this->ok([
            'status' => $status,
            'total_percentage' => $total,
            'owners_missing_percentages' => $missing,
            'owner_count' => count($owners),
        ]);
    }

    /* ============================================================
     * POST /properties/{id}/ownership/adjudicate
     * ============================================================ */
    public function adjudicate(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $propertyId = $req['params']['id'];

        $this->db->beginTransaction();

        try {
            // Lock owners to prevent concurrent modification
            $st = $this->db->prepare(
                <<<SQL
                SELECT
                    id,
                    percentage_interest
                FROM property_relationships
                WHERE tenant_id = :tenant_id
                  AND property_id = :property_id
                  AND role = 'owner'
                  AND ends_at IS NULL
                FOR UPDATE
                SQL
            );

            $st->execute([
                ':tenant_id'   => $ctx->tenantId,
                ':property_id' => $propertyId,
            ]);

            $owners = $st->fetchAll(PDO::FETCH_ASSOC);

            if (empty($owners)) {
                throw new RuntimeException('No owners found');
            }

            $total = 0.0;

            foreach ($owners as $o) {
                if ($o['percentage_interest'] === null) {
                    throw new RuntimeException(
                        'All owners must have percentage_interest before adjudication'
                    );
                }
                $total += (float) $o['percentage_interest'];
            }

            if (abs($total - 100.0) > 0.0001) {
                throw new RuntimeException(
                    'Total ownership percentage must equal 100 for adjudication'
                );
            }

            // Mark all active owners as adjudicated
            $this->db->prepare(
                <<<SQL
                UPDATE property_relationships
                SET ownership_status = 'adjudicated'
                WHERE tenant_id = :tenant_id
                  AND property_id = :property_id
                  AND role = 'owner'
                  AND ends_at IS NULL
                SQL
            )->execute([
                ':tenant_id'   => $ctx->tenantId,
                ':property_id' => $propertyId,
            ]);

            // Emit single adjudication audit event
            $this->emitAudit(
                $ctx,
                'property_ownership',
                $propertyId,
                'adjudicate',
                [
                    'total_percentage' => $total,
                    'owner_count' => count($owners),
                ]
            );

            $this->db->commit();

            return $this->ok([
                'status' => 'adjudicated',
                'total_percentage' => $total,
                'owner_count' => count($owners),
            ]);

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /* ============================================================
     * Audit
     * ============================================================ */
    private function emitAudit(
        AuthContext $ctx,
        string $entityType,
        string $entityId,
        string $operation,
        array $payload
    ): void {
        $this->db->prepare(
            <<<SQL
            INSERT INTO audit_events (
                tenant_id,
                event_at,
                actor_user_id,
                entity_type,
                entity_id,
                operation,
                diff_payload
            )
            VALUES (
                :tenant_id,
                now(),
                :actor_user_id,
                :entity_type,
                :entity_id,
                :operation,
                :payload
            )
            SQL
        )->execute([
            ':tenant_id'     => $ctx->tenantId,
            ':actor_user_id' => $ctx->actorId,
            ':entity_type'   => $entityType,
            ':entity_id'     => $entityId,
            ':operation'     => $operation,
            ':payload'       => json_encode($payload),
        ]);
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function ok(array $body): array
    {
        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
        ];
    }

    private function unauthorized(): array
    {
        return [
            'status' => 401,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => ['error' => 'AUTH_REQUIRED'],
        ];
    }
}
