<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;
use RuntimeException;

final class CasesController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function assertSelectableCourt(?string $courtId): void
    {
        if ($courtId === null) {
            return;
        }

        if (!preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $courtId
        )) {
            throw new RuntimeException('INVALID_COURT_ID_FORMAT');
        }

        $stmt = $this->db->prepare(
            "SELECT 1
               FROM public.courts
              WHERE id = :id
                AND status = 'active'
              LIMIT 1"
        );
        $stmt->execute([':id' => $courtId]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('COURT_NOT_SELECTABLE');
        }
    }

    private function emitLifecycleEvent(
        string $tenantId,
        string $caseId,
        ?string $from,
        string $to,
        ?string $reason,
        ?string $changedBy
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO case_lifecycle_events
             (tenant_id, case_id, from_status, to_status, reason, changed_by)
             VALUES
             (:tenant, :case, :from, :to, :reason, :by)"
        );

        $stmt->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
            ':from'   => $from,
            ':to'     => $to,
            ':reason' => $reason,
            ':by'     => $changedBy,
        ]);
    }

    private function assertMutableStatus(string $status): void
    {
        if ($status === 'closed') {
            throw new RuntimeException('CASE_CLOSED');
        }
    }

    private function revokeAllCaseCapabilities(
        string $tenantId,
        string $caseId
    ): void {
        $stmt = $this->db->prepare(
            "UPDATE case_capability_grants
                SET revoked_at = now(),
                    reason = 'case closed'
              WHERE tenant_id = :tenant
                AND case_id = :case
                AND revoked_at IS NULL"
        );

        $stmt->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId,
        ]);
    }

    /* ============================================================
     * POST /cases
     * ============================================================ */

    public function create(array $req, ?AuthContext $authCtx): array
    {
        if ($authCtx === null) {
            return $this->unauthorized();
        }

        $tenantId = (string)$authCtx->tenantId;

 

        $body = $req['body'] ?? null;
        if (!is_array($body)) {
            return $this->invalidRequest();
        }

        $status   = trim((string)($body['status'] ?? ''));
        $openedAt = trim((string)($body['opened_at'] ?? ''));

        if ($status === '' || $openedAt === '') {
            return $this->validationError('status and opened_at are required');
        }

        $courtId = null;
        if (array_key_exists('court_id', $body)) {
            $v = trim((string)$body['court_id']);
            $courtId = $v !== '' ? $v : null;
        }

        try {
            $this->assertSelectableCourt($courtId);

            $this->db->beginTransaction();

            $stmt = $this->db->prepare(
                "INSERT INTO cases
                 (tenant_id, court_id, status, opened_at)
                 VALUES
                 (:tenant, :court, :status, :opened)
                 RETURNING id"
            );

            $stmt->execute([
                ':tenant' => $tenantId,
                ':court'  => $courtId,
                ':status' => $status,
                ':opened' => $openedAt,
            ]);

            $caseId = (string)$stmt->fetchColumn();
            if ($caseId === '') {
                throw new RuntimeException('FAILED_TO_CREATE_CASE');
            }

            $this->emitLifecycleEvent(
                $tenantId,
                $caseId,
                null,
                $status,
                'case created',
                $authCtx->actorId
            );

            /* --------------------------------------------------
             * Phase 4: Auto-grant for self-represented clients
             * -------------------------------------------------- */
            $profileStmt = $this->db->prepare(
                "SELECT client_type
                   FROM tenant_client_profiles
                  WHERE tenant_id = :tenant
                    AND client_actor_id = :actor
                    AND starts_at <= now()
                    AND (ends_at IS NULL OR ends_at > now())
                  ORDER BY starts_at DESC
                  LIMIT 1"
            );

            $profileStmt->execute([
                ':tenant' => $tenantId,
                ':actor'  => $authCtx->actorId,
            ]);

            if ($profileStmt->fetchColumn() === 'self_represented') {
                $caps = [
                    'case:write',
                    'case:occupants:write',
                    'case:ingress:submit',
                ];

                $grant = $this->db->prepare(
                    "INSERT INTO case_capability_grants
                     (tenant_id, case_id, grantee_actor_id, capability, starts_at, granted_by)
                     VALUES
                     (:tenant, :case, :actor, :cap, now(), :by)
                     ON CONFLICT DO NOTHING"
                );

                foreach ($caps as $cap) {
                    $grant->execute([
                        ':tenant' => $tenantId,
                        ':case'   => $caseId,
                        ':actor'  => $authCtx->actorId,
                        ':cap'    => $cap,
                        ':by'     => $authCtx->actorId,
                    ]);
                }
            }

            $this->db->commit();

            return [
                'status'  => 201,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => [
                    'case' => [
                        'id'          => $caseId,
                        'tenant_id'   => $tenantId,
                        'court_id'    => $courtId,
                        'case_number' => null,
                        'status'      => $status,
                        'opened_at'   => $openedAt,
                    ],
                ],
            ];

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->mapException($e);
        }
    }

    /* ============================================================
     * PATCH /cases/{id}
     * ============================================================ */

    public function patch(array $req, ?AuthContext $authCtx): array
    {
        if ($authCtx === null) {
            return $this->unauthorized();
        }

        $tenantId = (string)$authCtx->tenantId;
        $caseId   = $req['params']['id'] ?? null;

        if (!is_string($caseId) || $caseId === '') {
            return $this->validationError('case id is required');
        }



        $body = $req['body'] ?? null;
        if (!is_array($body)) {
            return $this->invalidRequest();
        }

        $changes = array_intersect_key(
            $body,
            array_flip(['court_id', 'case_number', 'status'])
        );

        if ($changes === []) {
            return $this->validationError('Nothing to update');
        }

        try {
            $this->db->beginTransaction();

            $lock = $this->db->prepare(
                "SELECT court_id, case_number, status
                   FROM cases
                  WHERE id = :id
                    AND tenant_id = :tenant
                  FOR UPDATE"
            );
            $lock->execute([':id' => $caseId, ':tenant' => $tenantId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->db->rollBack();
                return $this->notFound();
            }

            $this->assertMutableStatus($row['status']);

            $sets   = [];
            $params = [':id' => $caseId, ':tenant' => $tenantId];

            if (array_key_exists('court_id', $changes)) {
                $this->assertSelectableCourt($changes['court_id'] ?? null);
                $sets[] = 'court_id = :court';
                $params[':court'] = $changes['court_id'] ?? null;
            }

            if (array_key_exists('case_number', $changes)) {
                if (($changes['case_number'] ?? null) !== null
                    && ($changes['court_id'] ?? $row['court_id']) === null) {
                    throw new RuntimeException('COURT_REQUIRED_FOR_CASE_NUMBER');
                }
                $sets[] = 'case_number = :num';
                $params[':num'] = $changes['case_number'] ?? null;
            }

            if (array_key_exists('status', $changes)) {
                $newStatus = trim((string)$changes['status']);
                if ($newStatus === '' || $newStatus === $row['status']) {
                    throw new RuntimeException('NO_STATUS_CHANGE');
                }

                $sets[] = 'status = :status';
                $params[':status'] = $newStatus;

                $this->emitLifecycleEvent(
                    $tenantId,
                    $caseId,
                    $row['status'],
                    $newStatus,
                    'status updated',
                    $authCtx->actorId
                );

                if ($newStatus === 'closed') {
                    $this->revokeAllCaseCapabilities($tenantId, $caseId);
                }
            }

            $sql = "UPDATE cases SET " . implode(', ', $sets) .
                   " WHERE id = :id AND tenant_id = :tenant";

            $this->db->prepare($sql)->execute($params);

            $fetch = $this->db->prepare(
                "SELECT id, tenant_id, court_id, case_number, status, opened_at
                   FROM cases
                  WHERE id = :id AND tenant_id = :tenant"
            );
            $fetch->execute([':id' => $caseId, ':tenant' => $tenantId]);

            $this->db->commit();

            return [
                'status'  => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['case' => $fetch->fetch(PDO::FETCH_ASSOC)],
            ];

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->mapException($e);
        }
    }

    /* ============================================================
     * GET /cases/{id}
     * ============================================================ */

    public function get(array $req, ?AuthContext $authCtx): array
    {
        if ($authCtx === null) {
            return $this->unauthorized();
        }

        $tenantId = (string)$authCtx->tenantId;
        $caseId   = $req['params']['id'] ?? null;

        if (!is_string($caseId) || $caseId === '') {
            return $this->validationError('Invalid case id');
        }

        $stmt = $this->db->prepare(
            "SELECT id, tenant_id, court_id, case_number, status, opened_at
               FROM cases
              WHERE id = :id AND tenant_id = :tenant"
        );
        $stmt->execute([':id' => $caseId, ':tenant' => $tenantId]);

        $case = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$case) {
            return $this->notFound();
        }

        return [
            'status'  => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['case' => $case],
        ];
    }

    /* ============================================================
     * Response helpers
     * ============================================================ */

    private function unauthorized(): array
    {
        return ['status' => 401, 'headers' => ['Content-Type'=>'application/json'], 'body' => ['error'=>'AUTH_REQUIRED']];
    }

    private function notFound(): array
    {
        return ['status' => 404, 'headers' => ['Content-Type'=>'application/json'], 'body' => ['error'=>'NOT_FOUND']];
    }

    private function invalidRequest(): array
    {
        return ['status' => 400, 'headers' => ['Content-Type'=>'application/json'], 'body' => ['error'=>['code'=>'INVALID_REQUEST']]];
    }

    private function validationError(string $msg): array
    {
        return ['status' => 400, 'headers' => ['Content-Type'=>'application/json'], 'body' => ['error'=>['code'=>'VALIDATION_ERROR','message'=>$msg]]];
    }

    private function mapException(\Throwable $e): array
    {
        return match ($e->getMessage()) {
            'INVALID_COURT_ID_FORMAT' =>
                $this->validationError('court_id must be a valid UUID'),
            'COURT_NOT_SELECTABLE' =>
                ['status'=>422,'headers'=>['Content-Type'=>'application/json'],'body'=>['error'=>['code'=>'COURT_NOT_SELECTABLE']]],
            'CASE_CLOSED' =>
                ['status'=>422,'headers'=>['Content-Type'=>'application/json'],'body'=>['error'=>['code'=>'CASE_CLOSED']]],
            'NO_STATUS_CHANGE' =>
                $this->validationError('status must change'),
            'COURT_REQUIRED_FOR_CASE_NUMBER' =>
                $this->validationError('court_id is required when case_number is provided'),
            default =>
                ['status'=>500,'headers'=>['Content-Type'=>'application/json'],'body'=>['error'=>['code'=>'INTERNAL']]],
        };
    }
}
