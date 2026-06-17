<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;

final class CourtsController
{
    public function __construct(
        private PDO $db
    ) {}

    /**
     * GET /courts
     */
    public function list(array $req, ?AuthContext $authCtx): array
    {
        if ($authCtx === null) {
            return [
                'status'  => 401,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'AUTH_REQUIRED'],
            ];
        }

        $stmt = $this->db->query(
            "SELECT id FROM public.courts ORDER BY id"
        );

        $courts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $courts[] = [
                'id' => $row['id'],
            ];
        }

        return [
            'status'  => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['courts' => $courts],
        ];
    }

    /**
     * GET /courts/{id}
     */
    public function get(array $req, ?AuthContext $authCtx): array
    {
        if ($authCtx === null) {
            return [
                'status'  => 401,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'AUTH_REQUIRED'],
            ];
        }

        $courtId = $req['params']['id'] ?? null;

        if (!is_string($courtId) || trim($courtId) === '') {
            return [
                'status'  => 400,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'INVALID_COURT_ID'],
            ];
        }

        $stmt = $this->db->prepare(
            "SELECT id FROM public.courts WHERE id = :id"
        );
        $stmt->execute([':id' => $courtId]);

        $court = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$court) {
            return [
                'status'  => 404,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'NOT_FOUND'],
            ];
        }

        return [
            'status'  => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['court' => ['id' => $court['id']]],
        ];
    }

    /**
     * POST /courts (admin only)
     */
    public function create(array $req, ?AuthContext $authCtx): array
    {
        if ($authCtx === null) {
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

        // Required fields
        $required = ['sovereign_level', 'court_name'];
        foreach ($required as $field) {
            if (empty($body[$field]) || !is_string($body[$field])) {
                return [
                    'status'  => 400,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body'    => ['error' => 'INVALID_FIELD', 'field' => $field],
                ];
            }
        }

        // Generate UUID in application (no DB dependency)
        $id = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );

        $sql = "
            INSERT INTO public.courts (
                id,
                sovereign_level,
                state_code,
                county_name,
                municipality,
                court_name,
                federal_district,
                status,
                created_by,
                updated_by
            ) VALUES (
                :id,
                :sovereign_level,
                :state_code,
                :county_name,
                :municipality,
                :court_name,
                :federal_district,
                :status,
                :created_by,
                :updated_by
            )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id'               => $id,
            ':sovereign_level'  => $body['sovereign_level'],
            ':state_code'       => $body['state_code']       ?? null,
            ':county_name'      => $body['county_name']      ?? null,
            ':municipality'     => $body['municipality']     ?? null,
            ':court_name'       => $body['court_name'],
            ':federal_district' => $body['federal_district'] ?? null,
            ':status'           => $body['status'] ?? 'active',
            ':created_by'       => $authCtx->actorId,
            ':updated_by'       => $authCtx->actorId,
        ]);

        return [
            'status'  => 201,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['court' => ['id' => $id]],
        ];
    }

    /**
     * PATCH /courts/{id} (admin only)
     */
    public function patch(array $req, ?AuthContext $authCtx): array
    {
        if ($authCtx === null) {
            return [
                'status'  => 401,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'AUTH_REQUIRED'],
            ];
        }

        $courtId = $req['params']['id'] ?? null;
        if (!is_string($courtId) || trim($courtId) === '') {
            return [
                'status'  => 400,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'INVALID_COURT_ID'],
            ];
        }

        $body = $req['body'] ?? null;
        if (!is_array($body) || empty($body)) {
            return [
                'status'  => 400,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'INVALID_REQUEST'],
            ];
        }

        // Explicitly mutable fields only
        $allowed = [
            'court_name',
            'county_name',
            'municipality',
            'status',
        ];

        $sets = [];
        $params = [':id' => $courtId];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $sets[] = "$field = :$field";
                $params[":$field"] = $body[$field];
            }
        }

        if (empty($sets)) {
            return [
                'status'  => 400,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'NO_MUTABLE_FIELDS'],
            ];
        }

        $sets[] = "updated_at = now()";
        $sets[] = "updated_by = :updated_by";
        $params[':updated_by'] = $authCtx->actorId;

        $sql = "
            UPDATE public.courts
            SET " . implode(', ', $sets) . "
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            return [
                'status'  => 404,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => ['error' => 'NOT_FOUND'],
            ];
        }

        return [
            'status'  => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['court' => ['id' => $courtId]],
        ];
    }
}
