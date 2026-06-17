<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;

final class MeProfileController
{
    public function __construct(
        private PDO $db
    ) {}

    // --------------------------------------------------
    // GET /me/profile
    // --------------------------------------------------
    public function show(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return [
                'status'  => 401,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'AUTH_REQUIRED'],
            ];
        }

        $stmt = $this->db->prepare(
            <<<SQL
            SELECT
                u.email,
                u.display_name,
                u.status AS user_status,
                ai.first_name,
                ai.middle_name,
                ai.last_name
            FROM users u
            JOIN actor_identities ai
              ON ai.actor_id = :actor_id
             AND ai.tenant_id = :tenant_id
            WHERE u.id = :user_id
              AND u.tenant_id = :tenant_id
            LIMIT 1
            SQL
        );

        $stmt->execute([
            ':user_id'   => $ctx->actorId,
            ':actor_id'  => $ctx->actorId,
            ':tenant_id' => $ctx->tenantId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'status'  => 404,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'PROFILE_NOT_FOUND'],
            ];
        }

        return [
            'status'  => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => [
                'email'        => $row['email'],
                'display_name' => $row['display_name'],
                'status'       => $row['user_status'],
                'name' => [
                    'first'  => $row['first_name'],
                    'middle' => $row['middle_name'],
                    'last'   => $row['last_name'],
                ],
            ],
        ];
    }

    // --------------------------------------------------
    // PATCH /me/profile
    // --------------------------------------------------
    public function patch(array $req, ?AuthContext $ctx): array
    {
        error_log('[ME PROFILE PATCH] entered');

        if ($ctx === null) {
            return [
                'status'  => 401,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'AUTH_REQUIRED'],
            ];
        }

        $body = $req['body'] ?? null;
        if (!is_array($body)) {
            return [
                'status'  => 400,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'INVALID_REQUEST'],
            ];
        }

        $allowedFields = [
            'first_name',
            'middle_name',
            'last_name',
            'display_name',
        ];

        $updates = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $body)) {
                if (!is_string($body[$field])) {
                    return [
                        'status'  => 400,
                        'headers' => ['Content-Type' => 'application/json'],
                        'body'    => ['error' => 'INVALID_FIELD_TYPE'],
                    ];
                }
                $updates[$field] = trim($body[$field]);
            }
        }

        if ($updates === []) {
            return [
                'status'  => 400,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'NO_CHANGES'],
            ];
        }

        $this->db->beginTransaction();

        try {
            $identityFields = array_intersect_key(
                $updates,
                array_flip(['first_name', 'middle_name', 'last_name'])
            );

            if ($identityFields) {
                $setParts = [];
                foreach ($identityFields as $key => $_) {
                    $setParts[] = "$key = :$key";
                }

                $sql =
                    'UPDATE actor_identities SET '
                    . implode(', ', $setParts)
                    . ' WHERE actor_id = :actor_id AND tenant_id = :tenant_id';

                $stmt = $this->db->prepare($sql);
                $stmt->execute($identityFields + [
                    ':actor_id'   => $ctx->actorId,
                    ':tenant_id' => $ctx->tenantId,
                ]);
            }

            if (array_key_exists('display_name', $updates)) {
                $stmt = $this->db->prepare(
                    'UPDATE users
                     SET display_name = :display_name
                     WHERE id = :user_id AND tenant_id = :tenant_id'
                );

                $stmt->execute([
                    ':display_name' => $updates['display_name'],
                    ':user_id'      => $ctx->actorId,
                    ':tenant_id'    => $ctx->tenantId,
                ]);
            }

            $this->db->commit();

            return [
                'status'  => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['status' => 'UPDATED'],
            ];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
