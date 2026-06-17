<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;
use RuntimeException;

final class PropertiesController
{
    public function __construct(
        private PDO $db,
        private ?object $audit = null
    ) {}

    /* ============================================================
     * GET /properties
     * ============================================================ */
    public function list(?AuthContext $auth, array $req): array
    {
        if ($auth === null) {
            return $this->json(401, ['error' => 'AUTH_REQUIRED']);
        }

        $tenantId = $auth->tenantId;
        $actorId  = $auth->actorId;

        $isAdmin =
            in_array('system_admin', $auth->roles, true) ||
            in_array('tenant_admin', $auth->roles, true);

        $limit  = max(1, min((int)($req['query']['limit'] ?? 200), 500));
        $offset = max(0, (int)($req['query']['offset'] ?? 0));

        if ($isAdmin) {
            $sql = <<<SQL
SELECT
  id,
  address_line1,
  address_line2,
  city,
  state,
  zip,
  county,
  apn,
  legal_description,
  owner_actor_id,
  created_at
FROM properties
WHERE tenant_id = :tenant_id
ORDER BY created_at DESC
LIMIT :limit OFFSET :offset
SQL;
        } else {
            $sql = <<<SQL
SELECT
  id,
  address_line1,
  address_line2,
  city,
  state,
  zip,
  county,
  apn,
  legal_description,
  owner_actor_id,
  created_at
FROM properties
WHERE tenant_id = :tenant_id
  AND owner_actor_id = :actor_id
ORDER BY created_at DESC
LIMIT :limit OFFSET :offset
SQL;
        }

        $st = $this->db->prepare($sql);
        $st->bindValue(':tenant_id', $tenantId);
        if (!$isAdmin) {
            $st->bindValue(':actor_id', $actorId);
        }
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();

        return $this->json(200, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /* ============================================================
     * POST /properties
     * ============================================================ */
    public function create(?AuthContext $auth, array $req): array
    {
        if ($auth === null) {
            return $this->json(401, ['error' => 'AUTH_REQUIRED']);
        }

        $tenantId = $auth->tenantId;
        $actorId  = $auth->actorId;

        $isAdmin =
            in_array('system_admin', $auth->roles, true) ||
            in_array('tenant_admin', $auth->roles, true);

        $body = $req['body'] ?? null;
        if (!is_array($body)) {
            return $this->json(400, ['error' => 'INVALID_JSON']);
        }

        $address1 = $this->reqString($body, 'address_line1');
        $city     = $this->reqString($body, 'city');
        $state    = $this->reqString($body, 'state');
        $zip      = $this->reqString($body, 'zip');
        $legal    = $this->reqString($body, 'legal_description');

        $address2 = $this->optStringOrNull($body, 'address_line2');
        $county   = $this->optStringOrNull($body, 'county');
        $apn      = $this->optStringOrNull($body, 'apn');

        $ownerActorId = $actorId;
        if ($isAdmin && isset($body['owner_actor_id']) && is_string($body['owner_actor_id'])) {
            $ownerActorId = $body['owner_actor_id'];
        }

        $sql = <<<SQL
INSERT INTO properties (
  tenant_id,
  owner_actor_id,
  source,
  address_line1,
  address_line2,
  city,
  state,
  zip,
  county,
  apn,
  legal_description,
  created_at
) VALUES (
  :tenant_id,
  :owner_actor_id,
  :source,
  :address_line1,
  :address_line2,
  :city,
  :state,
  :zip,
  :county,
  :apn,
  :legal_description,
  NOW()
)
RETURNING
  id,
  address_line1,
  address_line2,
  city,
  state,
  zip,
  county,
  apn,
  legal_description,
  owner_actor_id,
  created_at
SQL;

        $st = $this->db->prepare($sql);
        $st->bindValue(':tenant_id', $tenantId);
        $st->bindValue(':owner_actor_id', $ownerActorId);
        $st->bindValue(':source', 'ui');
        $st->bindValue(':address_line1', $address1);
        $st->bindValue(':address_line2', $address2, $address2 === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(':city', $city);
        $st->bindValue(':state', $state);
        $st->bindValue(':zip', $zip);
        $st->bindValue(':county', $county, $county === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(':apn', $apn, $apn === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(':legal_description', $legal);
        $st->execute();

        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $this->json(500, ['error' => 'CREATE_FAILED']);
        }

        return $this->json(201, $row);
    }

    /* ============================================================
     * GET /properties/{id}
     * ============================================================ */
    public function get(?AuthContext $auth, array $req): array
    {
        if ($auth === null) {
            return $this->json(401, ['error' => 'AUTH_REQUIRED']);
        }

        $tenantId = $auth->tenantId;
        $actorId  = $auth->actorId;
        $id       = $req['params']['id'] ?? null;

        if (!is_string($id)) {
            return $this->json(404, ['error' => 'NOT_FOUND']);
        }

        $isAdmin =
            in_array('system_admin', $auth->roles, true) ||
            in_array('tenant_admin', $auth->roles, true);

        if ($isAdmin) {
            $sql = <<<SQL
SELECT
  id,
  address_line1,
  address_line2,
  city,
  state,
  zip,
  county,
  apn,
  legal_description,
  owner_actor_id,
  created_at
FROM properties
WHERE tenant_id = :tenant_id
  AND id = :id
LIMIT 1
SQL;
        } else {
            $sql = <<<SQL
SELECT
  id,
  address_line1,
  address_line2,
  city,
  state,
  zip,
  county,
  apn,
  legal_description,
  owner_actor_id,
  created_at
FROM properties
WHERE tenant_id = :tenant_id
  AND id = :id
  AND owner_actor_id = :actor_id
LIMIT 1
SQL;
        }

        $st = $this->db->prepare($sql);
        $st->bindValue(':tenant_id', $tenantId);
        $st->bindValue(':id', $id);
        if (!$isAdmin) {
            $st->bindValue(':actor_id', $actorId);
        }
        $st->execute();

        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $this->json(404, ['error' => 'NOT_FOUND']);
        }

        return $this->json(200, $row);
    }

/* ============================================================
 * GET /properties/{id}/images
 * ============================================================ */
public function listImages(?AuthContext $ctx, array $req): array
{
    if ($ctx === null) {
        return [
            'status'  => 401,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['error' => 'AUTH_REQUIRED'],
        ];
    }

    $tenantId   = (string) $ctx->tenantId;
    $propertyId = $req['params']['id'] ?? null;

    if (!is_string($propertyId)) {
        return [
            'status'  => 400,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['error' => 'INVALID_REQUEST'],
        ];
    }

    // Ensure property belongs to tenant (prevents information leak)
    $chk = $this->db->prepare(
        "SELECT 1 FROM properties WHERE id = :id AND tenant_id = :tenant"
    );
    $chk->execute([':id' => $propertyId, ':tenant' => $tenantId]);
    if (!$chk->fetchColumn()) {
        return [
            'status'  => 404,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['error' => 'NOT_FOUND'],
        ];
    }

    $st = $this->db->prepare(
        "SELECT id, mime_type, storage_uri, content_hash, metadata, created_by, created_at
         FROM property_images
         WHERE tenant_id = :tenant
           AND property_id = :property
           AND deleted_at IS NULL
         ORDER BY created_at ASC"
    );
    $st->execute([':tenant' => $tenantId, ':property' => $propertyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Optional: attach derivative info (thumb variants)
    $ids = array_values(array_filter(array_map(fn($r) => $r['id'] ?? null, $rows), 'is_string'));
    $thumbsByImage = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $dt = $this->db->prepare(
            "SELECT image_id, variant, storage_uri, mime_type, width, height
             FROM property_image_derivatives
             WHERE deleted_at IS NULL
               AND image_id IN ($in)"
        );
        $dt->execute($ids);
        foreach ($dt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $thumbsByImage[$d['image_id']][] = $d;
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $imageId = (string) $r['id'];
        $out[] = [
            'image_id'     => $imageId,
            'mime_type'    => $r['mime_type'],
            'storage_uri'  => $r['storage_uri'],
            'content_hash' => $r['content_hash'],
            'metadata'     => is_string($r['metadata']) ? json_decode($r['metadata'], true) : $r['metadata'],
            'created_by'   => $r['created_by'],
            'created_at'   => $r['created_at'],
            'derivatives'  => $thumbsByImage[$imageId] ?? [],
        ];
    }

    return [
        'status'  => 200,
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => ['images' => $out],
    ];
}



    /* ============================================================
     * Helpers
     * ============================================================ */

    private function json(int $status, array $body): array
    {
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
        ];
    }

    private function reqString(array $body, string $key): string
    {
        if (!isset($body[$key]) || !is_string($body[$key])) {
            throw new RuntimeException('VALIDATION_ERROR');
        }
        $v = trim($body[$key]);
        if ($v === '') {
            throw new RuntimeException('VALIDATION_ERROR');
        }
        return $v;
    }

    private function optStringOrNull(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        if (!is_string($body[$key])) {
            throw new RuntimeException('VALIDATION_ERROR');
        }
        $v = trim($body[$key]);
        return $v === '' ? null : $v;
    }
}
