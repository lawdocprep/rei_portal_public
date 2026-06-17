<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;
use RuntimeException;

final class PropertyRelationshipsController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * GET /properties/{id}/relationships
     * ============================================================ */
    public function listForProperty(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $st = $this->db->prepare(
            <<<SQL
            SELECT
                id,
                actor_id,
                role,
                custom_role_label,
                starts_at,
                ends_at,
                percentage_interest,
                scope_type,
                scope_id,
                metadata
            FROM property_relationships
            WHERE tenant_id = :tenant_id
              AND property_id = :property_id
              AND ends_at IS NULL
            ORDER BY role, starts_at
            SQL
        );

        $st->execute([
            ':tenant_id'   => $ctx->tenantId,
            ':property_id' => $req['params']['id'],
        ]);

        return $this->ok($st->fetchAll(PDO::FETCH_ASSOC));
    }

    /* ============================================================
     * POST /properties/{id}/relationships
     * ============================================================ */
    public function create(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $body = $req['body'] ?? null;
        if (!is_array($body)) {
            return $this->badRequest();
        }

        $this->db->beginTransaction();

        try {
            $this->enforceRules($ctx, $req['params']['id'], $body);

            $id = $this->uuid();

            $this->db->prepare(
                <<<SQL
                INSERT INTO property_relationships (
                    id,
                    tenant_id,
                    property_id,
                    actor_id,
                    role,
                    custom_role_label,
                    starts_at,
                    percentage_interest,
                    scope_type,
                    scope_id,
                    metadata,
                    created_by
                )
                VALUES (
                    :id,
                    :tenant_id,
                    :property_id,
                    :actor_id,
                    :role,
                    :custom_role_label,
                    now(),
                    :percentage_interest,
                    :scope_type,
                    :scope_id,
                    :metadata,
                    :created_by
                )
                SQL
            )->execute([
                ':id'                  => $id,
                ':tenant_id'           => $ctx->tenantId,
                ':property_id'         => $req['params']['id'],
                ':actor_id'            => $body['actor_id'],
                ':role'                => $body['role'],
                ':custom_role_label'   => $body['custom_role_label'] ?? null,
                ':percentage_interest' => $body['percentage_interest'] ?? null,
                ':scope_type'          => $body['scope_type'] ?? null,
                ':scope_id'            => $body['scope_id'] ?? null,
                ':metadata'            => json_encode($body['metadata'] ?? new \stdClass()),
                ':created_by'          => $ctx->actorId,
            ]);

            $this->emitAudit($ctx, 'property_relationship', $id, 'create', $body);

            $this->db->commit();
            return $this->created(['id' => $id]);

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /* ============================================================
     * PATCH /properties/{id}/relationships/{relationship_id}
     * ============================================================ */
    public function update(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $body = $req['body'] ?? null;
        if (!is_array($body)) {
            return $this->badRequest();
        }

        $this->db->beginTransaction();

        try {
            // Close existing relationship
            $this->db->prepare(
                <<<SQL
                UPDATE property_relationships
                SET ends_at = now()
                WHERE id = :id
                  AND tenant_id = :tenant_id
                  AND ends_at IS NULL
                SQL
            )->execute([
                ':id'        => $req['params']['relationship_id'],
                ':tenant_id' => $ctx->tenantId,
            ]);

            // Re-create as new row
            $this->enforceRules($ctx, $req['params']['id'], $body);

            $newId = $this->uuid();

            $this->db->prepare(
                <<<SQL
                INSERT INTO property_relationships (
                    id,
                    tenant_id,
                    property_id,
                    actor_id,
                    role,
                    custom_role_label,
                    starts_at,
                    percentage_interest,
                    scope_type,
                    scope_id,
                    metadata,
                    created_by
                )
                VALUES (
                    :id,
                    :tenant_id,
                    :property_id,
                    :actor_id,
                    :role,
                    :custom_role_label,
                    now(),
                    :percentage_interest,
                    :scope_type,
                    :scope_id,
                    :metadata,
                    :created_by
                )
                SQL
            )->execute([
                ':id'                  => $newId,
                ':tenant_id'           => $ctx->tenantId,
                ':property_id'         => $req['params']['id'],
                ':actor_id'            => $body['actor_id'],
                ':role'                => $body['role'],
                ':custom_role_label'   => $body['custom_role_label'] ?? null,
                ':percentage_interest' => $body['percentage_interest'] ?? null,
                ':scope_type'          => $body['scope_type'] ?? null,
                ':scope_id'            => $body['scope_id'] ?? null,
                ':metadata'            => json_encode($body['metadata'] ?? new \stdClass()),
                ':created_by'          => $ctx->actorId,
            ]);

            $this->emitAudit($ctx, 'property_relationship', $newId, 'update', $body);

            $this->db->commit();
            return $this->ok(['id' => $newId]);

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /* ============================================================
     * Business rules
     * ============================================================ */
    private function enforceRules(AuthContext $ctx, string $propertyId, array $body): void
    {
        $role = $body['role'];

        // ---- Ownership shares
        if ($role === 'owner') {
            if (!isset($body['percentage_interest'])) {
                throw new RuntimeException('Owner requires percentage_interest');
            }

            $st = $this->db->prepare(
                <<<SQL
                SELECT COALESCE(SUM(percentage_interest), 0)
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

            $total = (float)$st->fetchColumn() + (float)$body['percentage_interest'];
            if ($total > 100.0) {
                throw new RuntimeException('Ownership exceeds 100%');
            }
        }

        // ---- Scoped exclusivity
        $exclusiveScopedRoles = [
            'foreclosure_trustee' => 'loan',
            'bankruptcy_trustee'  => 'case',
            'receiver'            => 'case',
            'escrow_agent'        => 'transaction',
        ];

        if (isset($exclusiveScopedRoles[$role])) {
            $scopeType = $exclusiveScopedRoles[$role];
            if (($body['scope_type'] ?? null) !== $scopeType || empty($body['scope_id'])) {
                throw new RuntimeException("$role requires scope_type=$scopeType and scope_id");
            }

            $st = $this->db->prepare(
                <<<SQL
                SELECT 1
                FROM property_relationships
                WHERE tenant_id = :tenant_id
                  AND role = :role
                  AND scope_type = :scope_type
                  AND scope_id = :scope_id
                  AND ends_at IS NULL
                LIMIT 1
                SQL
            );
            $st->execute([
                ':tenant_id'  => $ctx->tenantId,
                ':role'       => $role,
                ':scope_type' => $scopeType,
                ':scope_id'   => $body['scope_id'],
            ]);

            if ($st->fetchColumn()) {
                throw new RuntimeException("Active $role already exists for this scope");
            }
        }

        // ---- Buyer / seller role conflict
        if (in_array($role, ['buyer', 'seller'], true)) {
            $st = $this->db->prepare(
                <<<SQL
                SELECT 1
                FROM property_relationships
                WHERE tenant_id = :tenant_id
                  AND property_id = :property_id
                  AND actor_id = :actor_id
                  AND role IN ('buyer','seller')
                  AND ends_at IS NULL
                LIMIT 1
                SQL
            );
            $st->execute([
                ':tenant_id'   => $ctx->tenantId,
                ':property_id' => $propertyId,
                ':actor_id'    => $body['actor_id'],
            ]);

            if ($st->fetchColumn()) {
                throw new RuntimeException('Actor cannot be buyer and seller simultaneously');
            }
        }
    }

private function validateOwnerRules(
    AuthContext $ctx,
    string $propertyId,
    array $body
): void {

    if (($body['role'] ?? null) !== 'owner') {
        return;
    }

    $status = $body['ownership_status'] ?? 'asserted';
    $percent = $body['percentage_interest'] ?? null;

    // ---- Status validation
    if (!in_array($status, ['asserted', 'presumed', 'adjudicated'], true)) {
        throw new RuntimeException('Invalid ownership_status');
    }

    // ---- Percentages only allowed when adjudicated
    if ($percent !== null && $status !== 'adjudicated') {
        throw new RuntimeException(
            'percentage_interest allowed only when ownership_status = adjudicated'
        );
    }

    // ---- Enforce math ONLY when adjudicated
    if ($status === 'adjudicated') {
        if ($percent === null) {
            throw new RuntimeException(
                'percentage_interest required for adjudicated ownership'
            );
        }

        $st = $this->db->prepare(
            <<<SQL
            SELECT COALESCE(SUM(percentage_interest), 0)
            FROM property_relationships
            WHERE tenant_id = :tenant_id
              AND property_id = :property_id
              AND role = 'owner'
              AND ownership_status = 'adjudicated'
              AND ends_at IS NULL
            SQL
        );

        $st->execute([
            ':tenant_id'   => $ctx->tenantId,
            ':property_id' => $propertyId,
        ]);

        $total = (float) $st->fetchColumn() + (float) $percent;

        if ($total > 100.0) {
            throw new RuntimeException(
                'Total adjudicated ownership exceeds 100%'
            );
        }
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
    private function uuid(): string
    {
        return $this->db->query('SELECT uuid_generate_v4()')->fetchColumn();
    }

    private function ok(array $body): array
    {
        return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => $body];
    }

    private function created(array $body): array
    {
        return ['status' => 201, 'headers' => ['Content-Type' => 'application/json'], 'body' => $body];
    }

    private function badRequest(): array
    {
        return ['status' => 400, 'headers' => ['Content-Type' => 'application/json'], 'body' => ['error' => 'INVALID_REQUEST']];
    }

    private function unauthorized(): array
    {
        return ['status' => 401, 'headers' => ['Content-Type' => 'application/json'], 'body' => ['error' => 'AUTH_REQUIRED']];
    }
}
