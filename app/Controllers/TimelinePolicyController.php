<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;
use RuntimeException;

final class TimelinePolicyController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * GET /admin/timeline-policy
     * ============================================================ */
    public function getPolicy(array $req, ?AuthContext $ctx): array
    {
        $this->requireAdmin($ctx);

        $tenantId = (string) $ctx->tenantId;

        $policy = $this->fetchPolicy($tenantId);
        $phases = $this->fetchPhasePermissions($tenantId);
        $kinds  = $this->fetchEventTypePermissions($tenantId);

        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => [
                'policy' => $policy,
                'phase_permissions' => $phases,
                'event_type_permissions' => $kinds,
            ],
        ];
    }

    /* ============================================================
     * PUT /admin/timeline-policy
     * ============================================================ */
    public function updatePolicy(array $req, ?AuthContext $ctx): array
    {
        $this->requireAdmin($ctx);

        $tenantId = (string) $ctx->tenantId;
        $body = $req['body'] ?? null;

        if (!is_array($body)) {
            return $this->badRequest('Invalid JSON body.');
        }

        $this->db->beginTransaction();

        try {
            $this->upsertPolicy($tenantId, $body['policy'] ?? []);
            $this->replacePhasePermissions($tenantId, $body['phase_permissions'] ?? []);
            $this->replaceEventTypePermissions($tenantId, $body['event_type_permissions'] ?? []);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => ['status' => 'ok'],
        ];
    }

    /* ============================================================
     * Policy Persistence
     * ============================================================ */

    private function fetchPolicy(string $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM tenant_timeline_policies WHERE tenant_id = :tenant_id"
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: [
            'tenant_id' => $tenantId,
            'default_view_mode' => 'procedural',
            'visible_phases' => [],
            'default_expanded_phases' => [],
            'default_filters' => new \stdClass(),
        ];
    }

    private function fetchPhasePermissions(string $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT phase, can_view_roles, can_create_roles, can_edit_roles, can_delete_roles
             FROM tenant_phase_permissions
             WHERE tenant_id = :tenant_id"
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchEventTypePermissions(string $tenantId): array
    {
        $stmt = $this->db->prepare(
            "SELECT legal_kind, can_create_roles
             FROM tenant_event_type_permissions
             WHERE tenant_id = :tenant_id"
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function upsertPolicy(string $tenantId, array $policy): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO tenant_timeline_policies
             (tenant_id, default_view_mode, visible_phases, default_expanded_phases, default_filters)
             VALUES (:tenant_id, :mode, :visible, :expanded, :filters)
             ON CONFLICT (tenant_id)
             DO UPDATE SET
               default_view_mode = EXCLUDED.default_view_mode,
               visible_phases = EXCLUDED.visible_phases,
               default_expanded_phases = EXCLUDED.default_expanded_phases,
               default_filters = EXCLUDED.default_filters,
               updated_at = now()"
        );

        $stmt->execute([
            'tenant_id' => $tenantId,
            'mode'      => $policy['default_view_mode'] ?? 'procedural',
            'visible'   => $this->pgArray($policy['visible_phases'] ?? []),
            'expanded'  => $this->pgArray($policy['default_expanded_phases'] ?? []),
            'filters'   => json_encode($policy['default_filters'] ?? new \stdClass()),
        ]);
    }

    private function replacePhasePermissions(string $tenantId, array $rows): void
    {
        $this->db->prepare(
            "DELETE FROM tenant_phase_permissions WHERE tenant_id = :tenant_id"
        )->execute(['tenant_id' => $tenantId]);

        $stmt = $this->db->prepare(
            "INSERT INTO tenant_phase_permissions
             (tenant_id, phase, can_view_roles, can_create_roles, can_edit_roles, can_delete_roles)
             VALUES (:tenant_id, :phase, :view, :create, :edit, :delete)"
        );

        foreach ($rows as $row) {
            $stmt->execute([
                'tenant_id' => $tenantId,
                'phase'     => $row['phase'],
                'view'      => $this->pgArray($row['can_view_roles'] ?? []),
                'create'    => $this->pgArray($row['can_create_roles'] ?? []),
                'edit'      => $this->pgArray($row['can_edit_roles'] ?? []),
                'delete'    => $this->pgArray($row['can_delete_roles'] ?? []),
            ]);
        }
    }

    private function replaceEventTypePermissions(string $tenantId, array $rows): void
    {
        $this->db->prepare(
            "DELETE FROM tenant_event_type_permissions WHERE tenant_id = :tenant_id"
        )->execute(['tenant_id' => $tenantId]);

        $stmt = $this->db->prepare(
            "INSERT INTO tenant_event_type_permissions
             (tenant_id, legal_kind, can_create_roles)
             VALUES (:tenant_id, :kind, :roles)"
        );

        foreach ($rows as $row) {
            $stmt->execute([
                'tenant_id' => $tenantId,
                'kind'      => $row['legal_kind'],
                'roles'     => $this->pgArray($row['can_create_roles'] ?? []),
            ]);
        }
    }

    /* ============================================================
     * Guards & Helpers
     * ============================================================ */

    private function requireAdmin(?AuthContext $ctx): void
    {
        if ($ctx === null || !$ctx->hasAnyRole(['system_admin', 'tenant_admin'])) {
            throw new RuntimeException('ADMIN_REQUIRED');
        }
    }

    private function badRequest(string $msg): array
    {
        return [
            'status' => 400,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => [
                'error' => 'INVALID_REQUEST',
                'message' => $msg,
            ],
        ];
    }

    private function pgArray(array $values): string
    {
        return '{' . implode(',', array_map(fn($v) => '"' . addslashes($v) . '"', $values)) . '}';
    }
}
