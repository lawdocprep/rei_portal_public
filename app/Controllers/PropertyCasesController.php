<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\Guard\CaseMutationGuard;
use App\Services\Venue\VenueResolutionOrchestratorService;
use App\Procedures\ResolveVenueProcedure;

use PDO;
use RuntimeException;

final class PropertyCasesController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * GET /properties/{id}/cases
     * ============================================================ */
    public function list(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return [
                'status' => 401,
                'body' => ['error' => 'AUTH_REQUIRED'],
            ];
        }

        $propertyId = $req['params']['id'] ?? null;

        if (!$this->isUuid($propertyId)) {
            return [
                'status' => 400,
                'body' => ['error' => 'INVALID_PROPERTY_ID'],
            ];
        }

        $stmt = $this->db->prepare(
            '
            SELECT c.*
            FROM cases c
            INNER JOIN case_properties cp
              ON cp.case_id = c.id
            WHERE cp.property_id = :property_id
            ORDER BY c.created_at DESC
            '
        );

        $stmt->execute([
            'property_id' => $propertyId,
        ]);

        return [
            'status' => 200,
            'body' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /* ============================================================
     * POST /properties/{id}/cases
     * ============================================================ */
    public function create(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return [
                'status' => 401,
                'body' => ['error' => 'AUTH_REQUIRED'],
            ];
        }

        $propertyId = $req['params']['id'] ?? null;
        $tenantId   = (string)$ctx->tenantId;
        $actorId    = (string)$ctx->actorId;

        $body       = $req['body'] ?? [];

        $caseId = $body['case_id'] ?? null;
        $reason = $body['reason'] ?? null;

        if (!$this->isUuid($propertyId) || !$this->isUuid($caseId)) {
            return [
                'status' => 400,
                'body' => [
                    'error' => 'property_id and case_id must be valid UUIDs'
                ],
            ];
        }

        /* ============================================================
         * 🔒 Phase 17 Freeze Guard
         * ============================================================ */

        try {

            $guard = new CaseMutationGuard($this->db);

            $guard->assertCaseMutable(
                $tenantId,
                $caseId
            );

        } catch (RuntimeException $e) {

            if ($e->getMessage() === 'CASE_FROZEN_PREPARED_FOR_FILING') {
                return [
                    'status' => 409,
                    'body' => [
                        'error' => 'CASE_FROZEN_PREPARED_FOR_FILING'
                    ],
                ];
            }

            throw $e;
        }

        $this->db->beginTransaction();

        try {

            /* --------------------------------------------------------
             * End existing active association
             * -------------------------------------------------------- */

            $st = $this->db->prepare("
                UPDATE case_properties
                SET ends_at = now()
                WHERE case_id = :case_id
                  AND tenant_id = :tenant_id
                  AND ends_at IS NULL
            ");

            $st->execute([
                ':case_id'   => $caseId,
                ':tenant_id' => $tenantId,
            ]);

            /* --------------------------------------------------------
             * Insert new association
             * -------------------------------------------------------- */

            $st = $this->db->prepare("
                INSERT INTO case_properties (
                    tenant_id,
                    case_id,
                    property_id,
                    starts_at,
                    reason
                ) VALUES (
                    :tenant_id,
                    :case_id,
                    :property_id,
                    now(),
                    :reason
                )
            ");

            $st->execute([
                ':tenant_id'   => $tenantId,
                ':case_id'     => $caseId,
                ':property_id' => $propertyId,
                ':reason'      => $reason,
            ]);

            $this->db->commit();

            /*
            ------------------------------------------------------------
            Early Venue Resolution Trigger
            ------------------------------------------------------------

            Runs AFTER commit.

            Guarantees:

            - no transaction interference
            - idempotent execution
            - safe retry behavior
            - UI readiness
            - document readiness

            Failure is intentionally non-blocking.
            ------------------------------------------------------------
            */

            $this->attemptEarlyVenueResolution(
                tenantId: $tenantId,
                caseId: $caseId,
                actorId: $actorId
            );

            return [
                'status' => 201,
                'body' => [
                    'case_id'     => $caseId,
                    'property_id' => $propertyId,
                ],
            ];

        } catch (\Throwable $e) {

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    /* ============================================================
     * Early Venue Resolution
     * ============================================================ */

    private function attemptEarlyVenueResolution(
        string $tenantId,
        string $caseId,
        string $actorId
    ): void {

        try {

            $legalActionId = $this->resolveLegalActionId(
                $tenantId,
                $caseId
            );

            $orchestrator =
                new VenueResolutionOrchestratorService(
                    $this->db,
                    new ResolveVenueProcedure($this->db)
                );

            $orchestrator->resolveIfReady(
                tenantId: $tenantId,
                caseId: $caseId,
                legalActionId: $legalActionId,
                actorId: $actorId
            );

        } catch (\Throwable $e) {

            /*
            ------------------------------------------------------------
            Non-blocking failure by design.

            Venue resolution must never prevent:

            - property assignment
            - case creation
            - workflow progression
            ------------------------------------------------------------
            */

        }
    }

    /* ============================================================
     * Resolve Legal Action
     * ============================================================ */

    private function resolveLegalActionId(
        string $tenantId,
        string $caseId
    ): string {

        $st = $this->db->prepare("
            SELECT id
            FROM legal_actions
            WHERE tenant_id = :tenant
              AND case_id = :case
            ORDER BY created_at DESC
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case' => $caseId
        ]);

        $id = $st->fetchColumn();

        if (!$id) {
            throw new RuntimeException(
                'LEGAL_ACTION_NOT_FOUND'
            );
        }

        return (string)$id;
    }

    /* ============================================================ */

    private function isUuid(?string $v): bool
    {
        if (!$v) {
            return false;
        }

        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $v
        );
    }
}