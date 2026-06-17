<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;

final class MeProfileBootstrapController
{
    public function __construct(
        private PDO $db
    ) {}

    public function create(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->json(401, ['error' => 'AUTH_REQUIRED']);
        }

        $this->db->beginTransaction();

        try {
            // --------------------------------------------------
            // 1. Ensure actor exists
            // --------------------------------------------------
            $actorCheck = $this->db->prepare(
                'SELECT 1 FROM actors WHERE id = :actor_id AND tenant_id = :tenant_id'
            );
            $actorCheck->execute([
                ':actor_id'  => $ctx->actorId,
                ':tenant_id'=> $ctx->tenantId,
            ]);

            if (!$actorCheck->fetchColumn()) {
                $actorInsert = $this->db->prepare(
                    <<<SQL
                    INSERT INTO actors (
                        id,
                        tenant_id,
                        actor_type
                    ) VALUES (
                        :actor_id,
                        :tenant_id,
                        :actor_type
                    )
                    SQL
                );

                $actorInsert->execute([
                    ':actor_id'   => $ctx->actorId,
                    ':tenant_id' => $ctx->tenantId,
                    ':actor_type'=> $ctx->actorType,
                ]);
            }

            // --------------------------------------------------
            // 2. Ensure actor_identity exists
            // --------------------------------------------------
            $identityCheck = $this->db->prepare(
                'SELECT 1 FROM actor_identities
                 WHERE actor_id = :actor_id AND tenant_id = :tenant_id'
            );
            $identityCheck->execute([
                ':actor_id'   => $ctx->actorId,
                ':tenant_id' => $ctx->tenantId,
            ]);

            if ($identityCheck->fetchColumn()) {
                $this->db->commit();
                return $this->json(200, ['status' => 'ALREADY_EXISTS']);
            }

            $identityInsert = $this->db->prepare(
                <<<SQL
                INSERT INTO actor_identities (
                    tenant_id,
                    actor_id,
                    effective_from,
                    first_name,
                    middle_name,
                    last_name,
                    source
                ) VALUES (
                    :tenant_id,
                    :actor_id,
                    NOW(),
                    '',
                    '',
                    '',
                    'bootstrap'
                )
                SQL
            );

            $identityInsert->execute([
                ':tenant_id' => $ctx->tenantId,
                ':actor_id'  => $ctx->actorId,
            ]);

            $this->db->commit();

            return $this->json(201, ['status' => 'CREATED']);

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function json(int $status, array $body): array
    {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
        ];
    }
}
