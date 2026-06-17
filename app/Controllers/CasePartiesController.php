<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\Guard\CaseMutationGuard;
use PDO;
use RuntimeException;

final class CasePartiesController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * POST /cases/{id}/parties
     * ============================================================ */
    public function create(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $caseId   = $req['params']['id'] ?? null;
        $tenantId = (string)$ctx->tenantId;
        $body     = $req['body'] ?? [];

        if (!$this->isUuid($caseId)) {
            return $this->badRequest('Invalid case id');
        }

        /* ========================================================
         * 🔒 Phase 17 Freeze Guard
         * ======================================================== */
        try {
            $guard = new CaseMutationGuard($this->db);
            $guard->assertCaseMutable($tenantId, $caseId);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'CASE_FROZEN_PREPARED_FOR_FILING') {
                return $this->conflict('CASE_FROZEN_PREPARED_FOR_FILING');
            }
            throw $e;
        }

        $partyRole = $body['party_role'] ?? null;
        if (!is_string($partyRole) || trim($partyRole) === '') {
            return $this->badRequest('party_role is required');
        }

        $firstName  = trim((string)($body['first_name'] ?? ''));
        $middleName = trim((string)($body['middle_name'] ?? '')) ?: null;
        $lastName   = trim((string)($body['last_name'] ?? ''));

        if ($firstName === '' || $lastName === '') {
            return $this->badRequest('first_name and last_name are required');
        }

        $email = $body['email'] ?? null;
        $phone = $body['phone'] ?? null;

        $address = $body['address'] ?? null;

        if ($address === null) {
            $address = $this->loadPropertyAddressForCase($caseId, $tenantId);
        }

        /* --------------------------------------------------------
        * Property requirement for non-owner parties
        * -------------------------------------------------------- */
        if ($address === null && $partyRole !== 'owner') {
            return $this->badRequest(
                'Property must be associated with case before adding occupants or parties'
            );
        }

        $this->db->beginTransaction();

        try {
            /* --------------------------------------------------------
             * 1. Create subject actor
             * -------------------------------------------------------- */
            $actorId = $this->createActor($tenantId);

            /* --------------------------------------------------------
             * 2. Insert case-scoped contact
             * -------------------------------------------------------- */
            $st = $this->db->prepare("
                INSERT INTO case_party_contacts (
                    tenant_id,
                    case_id,
                    actor_id,
                    first_name,
                    middle_name,
                    last_name,
                    email,
                    phone,
                    address_line1,
                    address_line2,
                    city,
                    state,
                    postal_code
                ) VALUES (
                    :tenant_id,
                    :case_id,
                    :actor_id,
                    :first_name,
                    :middle_name,
                    :last_name,
                    :email,
                    :phone,
                    :address_line1,
                    :address_line2,
                    :city,
                    :state,
                    :postal_code
                )
            ");

            $st->execute([
                ':tenant_id'     => $tenantId,
                ':case_id'       => $caseId,
                ':actor_id'      => $actorId,
                ':first_name'    => $firstName,
                ':middle_name'   => $middleName,
                ':last_name'     => $lastName,
                ':email'         => $email,
                ':phone'         => $phone,
                ':address_line1' => $address['line1'],
                ':address_line2' => $address['line2'] ?? null,
                ':city'          => $address['city'],
                ':state'         => $address['state'],
                ':postal_code'   => $address['postal_code'],
            ]);

            /* --------------------------------------------------------
             * 3. Assign temporal role
             * -------------------------------------------------------- */
            $st = $this->db->prepare("
                INSERT INTO case_parties (
                    tenant_id,
                    case_id,
                    actor_id,
                    party_role,
                    starts_at
                ) VALUES (
                    :tenant_id,
                    :case_id,
                    :actor_id,
                    :party_role,
                    now()
                )
            ");

            $st->execute([
                ':tenant_id'  => $tenantId,
                ':case_id'    => $caseId,
                ':actor_id'   => $actorId,
                ':party_role' => $partyRole,
            ]);

            $this->db->commit();

            return [
                'status' => 201,
                'body' => [
                    'actor_id'   => $actorId,
                    'party_role' => $partyRole,
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
     * Helpers
     * ============================================================ */

    private function createActor(string $tenantId): string
    {
        $st = $this->db->prepare("
            INSERT INTO actors (
                tenant_id,
                actor_type
            ) VALUES (
                :tenant_id,
                'person'
            )
            RETURNING id
        ");

        $st->execute([':tenant_id' => $tenantId]);
        return (string)$st->fetchColumn();
    }

    private function loadPropertyAddressForCase(string $caseId, string $tenantId): ?array
    {
        $st = $this->db->prepare("
            SELECT
                p.address_line1 AS line1,
                p.address_line2 AS line2,
                p.city,
                p.state,
                p.zip AS postal_code
            FROM case_properties cp
            JOIN properties p ON p.id = cp.property_id
            WHERE cp.case_id = :case_id
              AND cp.tenant_id = :tenant_id
              AND cp.ends_at IS NULL
            ORDER BY cp.starts_at DESC
            LIMIT 1
        ");

        $st->execute([
            ':case_id'   => $caseId,
            ':tenant_id' => $tenantId,
        ]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function isUuid(?string $value): bool
    {
        if (!$value) return false;

        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }

    private function badRequest(string $msg): array
    {
        return [
            'status' => 400,
            'body' => [
                'error'   => 'BAD_REQUEST',
                'message' => $msg,
            ],
        ];
    }

    private function conflict(string $code): array
    {
        return [
            'status' => 409,
            'body' => [
                'error' => $code,
            ],
        ];
    }

    private function unauthorized(): array
    {
        return [
            'status' => 401,
            'body' => [
                'error' => 'UNAUTHORIZED',
            ],
        ];
    }
}