<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;

final class DocumentsController
{
    public function __construct(
        private PDO $db
    ) {}

    /* ============================================================
     * POST /documents/property-images
     * ============================================================ */
    public function createPropertyImages(array $req, ?AuthContext $ctx): array
{
    if ($ctx === null) {
        return $this->unauthorized();
    }

    $tenantId = (string) $ctx->tenantId;
    $actorId  = (string) $ctx->actorId;

    if ($ctx->actorType !== 'person') {
    return $this->validationError('invalid actor type');
    }

    $propertyId = $req['body']['property_id'] ?? null;
    $metadata   = $req['body']['metadata'] ?? null;

    if (!is_string($propertyId)) {
        return $this->badRequest();
    }

    // Validate UUID format early (avoid DB cast failures)
    if (!preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        $propertyId
    )) {
        return $this->validationError('invalid property_id');
    }

    if (
        !is_array($metadata) ||
        ($metadata['schema'] ?? null) !== 'property-image@1'
    ) {
        return $this->validationError('invalid metadata schema');
    }

    if (
        !isset($_FILES['images']) ||
        !is_array($_FILES['images']['tmp_name'])
    ) {
        return $this->validationError('no images uploaded');
    }

    /* ------------------------------------------------------------
     * Validate property ownership
     * ------------------------------------------------------------ */
    try {
        $chk = $this->db->prepare(
            "SELECT 1
             FROM properties
             WHERE id = :id
               AND tenant_id = :tenant"
        );
        $chk->execute([
            ':id'     => $propertyId,
            ':tenant' => $tenantId,
        ]);
    } catch (\PDOException) {
        return $this->validationError('invalid property_id');
    }

    if (!$chk->fetchColumn()) {
        return $this->notFound();
    }

    $created = [];

    try {
        $this->db->beginTransaction();

        $count = count($_FILES['images']['tmp_name']);

for ($i = 0; $i < $count; $i++) {
    if (
        $_FILES['images']['error'][$i] !== UPLOAD_ERR_OK ||
        !is_uploaded_file($_FILES['images']['tmp_name'][$i])
    ) {
        throw new \RuntimeException('upload failed');
    }

    /* --------------------------------------------------------
     * Size limit (logical, per-image)
     * -------------------------------------------------------- */
    $maxBytes = 5 * 1024 * 1024; // 5 MB
    $size = $_FILES['images']['size'][$i] ?? 0;

    if ($size <= 0 || $size > $maxBytes) {
        throw new \RuntimeException('image exceeds maximum size');
    }

    /* --------------------------------------------------------
     * MIME allow-list (do NOT trust client MIME alone)
     * -------------------------------------------------------- */
    $clientMime = $_FILES['images']['type'][$i] ?? '';

    $allowed = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    if (!in_array($clientMime, $allowed, true)) {
        throw new \RuntimeException('unsupported image type');
    }

    /* --------------------------------------------------------
     * Magic-byte verification (authoritative)
     * -------------------------------------------------------- */
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file($_FILES['images']['tmp_name'][$i]);

    if ($detectedMime === false || !in_array($detectedMime, $allowed, true)) {
        throw new \RuntimeException('invalid image content');
    }

    /* --------------------------------------------------------
     * Safe to read into memory now
     * -------------------------------------------------------- */
    $binary = file_get_contents($_FILES['images']['tmp_name'][$i]);
    if ($binary === false) {
        throw new \RuntimeException('failed to read upload');
    }

    // Use $detectedMime as the authoritative MIME going forward
    $mime = $detectedMime;


            $hash = 'sha256:' . hash('sha256', $binary);

            $docId = $this->db
                ->query("SELECT gen_random_uuid()")
                ->fetchColumn();

            /* --------------------------------------------------------
             * Local storage (AWS-safe abstraction)
             * -------------------------------------------------------- */
            $root = dirname(__DIR__, 2) . '/storage/property-images';

            if (!is_dir($root) && !mkdir($root, 0775, true)) {
                throw new \RuntimeException('failed to create storage directory');
            }

            $path = $root . '/' . $docId;

            if (file_put_contents($path, $binary) === false) {
                throw new \RuntimeException('failed to write image to storage');
            }

            $storageUri = "local://property-images/{$docId}";

            /* --------------------------------------------------------
             * Persist property image
             * -------------------------------------------------------- */
            $img = $this->db->prepare(
                "INSERT INTO property_images
                 (id, tenant_id, property_id, mime_type, storage_uri,
                  content_hash, metadata, created_by, created_at)
                 VALUES
                 (:id, :tenant, :property, :mime, :uri,
                  :hash, :meta, :actor, now())"
            );

            $img->execute([
                ':id'       => $docId,
                ':tenant'   => $tenantId,
                ':property' => $propertyId,
                ':mime'     => $mime,
                ':uri'      => $storageUri,
                ':hash'     => $hash,
                ':meta'     => json_encode($metadata, JSON_THROW_ON_ERROR),
                ':actor'    => $actorId,
            ]);

/* --------------------------------------------------------
 * Best-effort thumbnail generation (STEP 5.2)
 * -------------------------------------------------------- */
try {
    foreach ([128, 256, 512] as $size) {
        $thumb = $this->makeJpegThumbnail($binary, $size);
        if ($thumb === null) {
            continue;
        }

        $thumbId = $this->db
            ->query("SELECT gen_random_uuid()")
            ->fetchColumn();

        $thumbRoot = dirname(__DIR__, 2) . '/storage/property-images-thumbs';
        if (!is_dir($thumbRoot)) {
            mkdir($thumbRoot, 0775, true);
        }

        $thumbPath = $thumbRoot . '/' . $thumbId;
        file_put_contents($thumbPath, $thumb['binary']);

        $thumbUri  = "local://property-images-thumbs/{$thumbId}";
        $thumbHash = 'sha256:' . hash('sha256', $thumb['binary']);

        $ins = $this->db->prepare(
            "INSERT INTO property_image_derivatives
             (id, tenant_id, image_id, variant, mime_type,
              storage_uri, content_hash, width, height,
              created_by, created_at)
             VALUES
             (:id, :tenant, :image, :variant, 'image/jpeg',
              :uri, :hash, :w, :h, :actor, now())"
        );

        $ins->execute([
            ':id'     => $thumbId,
            ':tenant' => $tenantId,
            ':image'  => $docId,
            ':variant'=> 'thumb:' . $size,
            ':uri'    => $thumbUri,
            ':hash'   => $thumbHash,
            ':w'      => $thumb['width'],
            ':h'      => $thumb['height'],
            ':actor'  => $actorId,
        ]);
    }
} catch (\Throwable) {
    // Best-effort only — do not fail upload
}


            /* --------------------------------------------------------
             * Audit event (evidence-ready)
             * -------------------------------------------------------- */
            $evt = $this->db->prepare(
                "INSERT INTO audit_events
                 (id, tenant_id, entity_type, entity_id, operation, actor_user_id, event_at)
                 VALUES
                 (gen_random_uuid(), :tenant, 'property_image', :entity,
                  'property_image.upload', :actor, now())"
            );

            $evt->execute([
                ':tenant' => $tenantId,
                ':entity' => $docId,
                ':actor'  => $actorId,
            ]);

            $created[] = [
                'document_id' => $docId,
                'mime_type'   => $mime,
                'storage_uri' => $storageUri,
            ];
        }

        $this->db->commit();

        return $this->ok(['documents' => $created]);

    } catch (\Throwable $e) {
        error_log('[PROPERTY IMAGE UPLOAD ERROR] ' . $e->getMessage());

        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }

        return $this->internalError();
    }
}

    /* ============================================================
     * GET /documents/{id}/binary
     * ============================================================ */
    public function getBinary(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $tenantId   = (string) $ctx->tenantId;
        $documentId = $req['document_id'] ?? $req['params']['id'] ?? null;

        if (!is_string($documentId)) {
            return $this->badRequest();
        }

        $stmt = $this->db->prepare(
            "SELECT mime_type, storage_uri
             FROM documents
             WHERE id = :id
               AND tenant_id = :tenant"
        );
        $stmt->execute([
            ':id'     => $documentId,
            ':tenant' => $tenantId,
        ]);

        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            return $this->notFound();
        }

        if (!str_starts_with($doc['storage_uri'], 'local://')) {
            return $this->internalError();
        }

        $path = dirname(__DIR__, 2) . '/storage/' . substr($doc['storage_uri'], 8);
        if (!is_file($path)) {
            return $this->notFound();
        }

        $binary = file_get_contents($path);
        if ($binary === false) {
            return $this->internalError();
        }

        return [
            'status'  => 200,
            'headers' => [
                'Content-Type'  => $doc['mime_type'],
                'Cache-Control' => 'private, max-age=3600',
            ],
            'body' => $binary,
        ];
    }

    /* ============================================================
     * Helpers
     * ============================================================ */
    private function ok(array $body): array
    {
        return [
            'status'  => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
        ];
    }

    private function badRequest(): array
    {
        return [
            'status'  => 400,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['error' => 'INVALID_REQUEST'],
        ];
    }

    private function unauthorized(): array
    {
        return [
            'status'  => 401,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['error' => 'AUTH_REQUIRED'],
        ];
    }

    private function notFound(): array
    {
        return [
            'status'  => 404,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['error' => 'NOT_FOUND'],
        ];
    }

    private function validationError(string $msg): array
    {
        return [
            'status'  => 422,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => [
                'error' => [
                    'code'    => 'STATE_INVALID',
                    'message' => $msg,
                ],
            ],
        ];
    }

    private function internalError(): array
    {
        return [
            'status'  => 500,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => ['error' => 'INTERNAL'],
        ];
    }

    private function localPathFromUri(string $storageUri): ?string
    {
    // Must be local storage
    if (!str_starts_with($storageUri, 'local://')) {
        return null;
    }

    // Allow-list valid local storage prefixes only
    if (!preg_match(
        '#^local://(property-images|property-images-thumbs)/[0-9a-f-]{36}$#i',
        $storageUri
    )) {
        return null;
    }

    // local://property-images/<uuid>
    // local://property-images-thumbs/<uuid>
    $rel = substr($storageUri, strlen('local://'));
    return dirname(__DIR__, 2) . '/storage/' . $rel;
}


    public function getPropertyImageBinary(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
        return $this->unauthorized();
        }

        $tenantId = (string) $ctx->tenantId;
        $actorId  = (string) $ctx->actorId;
        $imageId  = $req['params']['id'] ?? null;

        if (!is_string($imageId)) {
            return $this->badRequest();
        }

        $st = $this->db->prepare(
            "SELECT id, mime_type, storage_uri
            FROM property_images
            WHERE id = :id
                AND tenant_id = :tenant
                AND deleted_at IS NULL"
        );
        $st->execute([':id' => $imageId, ':tenant' => $tenantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return $this->notFound();
        }

        $path = $this->localPathFromUri((string) $row['storage_uri']);
        if ($path === null || !is_file($path)) {
            return $this->internalError();
        }

        $binary = file_get_contents($path);
        if ($binary === false) {
            return $this->internalError();
        }

       // Evidence-grade audit for access
try {
    if ($ctx->actorType !== 'person') {
        throw new \RuntimeException('invalid actor type');
    }

    $evt = $this->db->prepare(
        "INSERT INTO audit_events
         (id, tenant_id, entity_type, entity_id, operation, actor_user_id, event_at)
         VALUES
         (gen_random_uuid(), :tenant, 'property_image', :entity,
          'property_image.access', :actor, now())"
    );

    $evt->execute([
    ':tenant' => $tenantId,
    ':entity' => $imageId,
    ':actor'  => $actorId,
]);


        } catch (\Throwable) {
        // Do not block access if audit logging fails (your call).
        // If you want strict mode, return internalError() here instead.
        }

        return [
            'status'  => 200,
            'headers' => [
                'Content-Type'  => (string) $row['mime_type'],
                'Cache-Control' => 'private, max-age=3600',
            ],
            'body' => $binary,
        ];
    }

/* ============================================================
 * GET /properties/{id}/images
 * ============================================================ */
public function listPropertyImages(array $req, ?AuthContext $ctx): array
{
    if ($ctx === null) {
        return $this->unauthorized();
    }

    $tenantId   = (string) $ctx->tenantId;
    $propertyId = $req['params']['id'] ?? null;

    if (!is_string($propertyId)) {
        return $this->badRequest();
    }

    $st = $this->db->prepare(
        "SELECT
            id,
            mime_type,
            storage_uri,
            content_hash,
            metadata,
            created_at
         FROM property_images
         WHERE tenant_id = :tenant
           AND property_id = :property
           AND deleted_at IS NULL
         ORDER BY created_at ASC"
    );
    $st->execute([
        ':tenant'   => $tenantId,
        ':property' => $propertyId,
    ]);

    return $this->ok([
        'images' => $st->fetchAll(PDO::FETCH_ASSOC),
    ]);
}


    public function getPropertyImageThumbnail(array $req, ?AuthContext $ctx): array
{
    if ($ctx === null) {
        return $this->unauthorized();
    }

    $tenantId = (string) $ctx->tenantId;
    $imageId  = $req['params']['id'] ?? null;

    if (!is_string($imageId)) {
        return $this->badRequest();
    }

    // default size=256, allow size=128/256/512
    $size = (string) ($req['query']['size'] ?? '256');
    if (!in_array($size, ['128', '256', '512'], true)) {
        return $this->validationError('invalid thumbnail size');
    }

    $variant = 'thumb:' . $size;

    $st = $this->db->prepare(
        "SELECT mime_type, storage_uri
         FROM property_image_derivatives
         WHERE tenant_id = :tenant
           AND image_id = :id
           AND variant = :variant
           AND deleted_at IS NULL"
    );
    $st->execute([
        ':tenant'  => $tenantId,
        ':id'      => $imageId,
        ':variant' => $variant,
    ]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return $this->notFound();
    }

    $path = $this->localPathFromUri((string) $row['storage_uri']);
    if ($path === null || !is_file($path)) {
        return $this->internalError();
    }

    $binary = file_get_contents($path);
    if ($binary === false) {
        return $this->internalError();
    }

    return [
        'status'  => 200,
        'headers' => [
            'Content-Type'  => (string) $row['mime_type'],
            'Cache-Control' => 'private, max-age=3600',
        ],
        'body' => $binary,
    ];
}

public function deletePropertyImage(array $req, ?AuthContext $ctx): array
{
    if ($ctx === null) {
        return $this->unauthorized();
    }

    $tenantId = (string) $ctx->tenantId;
$actorId  = (string) $ctx->actorId;

if ($ctx->actorType !== 'person') {
    return $this->validationError('invalid actor type');
}

    $imageId  = $req['params']['id'] ?? null;

    if (!is_string($imageId)) {
        return $this->badRequest();
    }

    try {
        $this->db->beginTransaction();

        // Soft delete image row
        $up = $this->db->prepare(
            "UPDATE property_images
             SET deleted_at = now(),
                 deleted_by = :actor
             WHERE id = :id
               AND tenant_id = :tenant
               AND deleted_at IS NULL"
        );
        $up->execute([
            ':id'     => $imageId,
            ':tenant' => $tenantId,
            ':actor'  => $actorId,
        ]);

        if ($up->rowCount() === 0) {
            $this->db->rollBack();
            return $this->notFound();
        }

        // Soft delete derivatives
        $dp = $this->db->prepare(
            "UPDATE property_image_derivatives
             SET deleted_at = now(),
                 deleted_by = :actor
             WHERE image_id = :id
               AND tenant_id = :tenant
               AND deleted_at IS NULL"
        );
        $dp->execute([
            ':id'     => $imageId,
            ':tenant' => $tenantId,
            ':actor'  => $actorId,
        ]);

        // Audit delete
        $evt = $this->db->prepare(
            "INSERT INTO audit_events
             (id, tenant_id, entity_type, entity_id, operation, actor_user_id, event_at)
             VALUES
             (gen_random_uuid(), :tenant, 'property_image', :entity,
              'property_image.delete', :actor, now())"
        );
        $evt->execute([
            ':tenant' => $tenantId,
            ':entity' => $imageId,
            ':actor'  => $actorId,
        ]);

        $this->db->commit();

        return $this->ok(['deleted' => true]);

    } catch (\Throwable $e) {
        error_log('[PROPERTY IMAGE DELETE ERROR] ' . $e->getMessage());

        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }

        return $this->internalError();
    }
}

private function makeJpegThumbnail(string $binary, int $maxSize): ?array
{
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }

    $src = @imagecreatefromstring($binary);
    if ($src === false) {
        return null;
    }

    $w = imagesx($src);
    $h = imagesy($src);
    if ($w <= 0 || $h <= 0) {
        imagedestroy($src);
        return null;
    }

    $scale = min($maxSize / $w, $maxSize / $h, 1.0);
    $nw = max(1, (int) floor($w * $scale));
    $nh = max(1, (int) floor($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    if ($dst === false) {
        imagedestroy($src);
        return null;
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    ob_start();
    imagejpeg($dst, null, 85);
    $jpg = ob_get_clean();

    imagedestroy($dst);
    imagedestroy($src);

    if (!is_string($jpg) || $jpg === '') {
        return null;
    }

    return [
        'binary' => $jpg,
        'width'  => $nw,
        'height' => $nh,
    ];
}

}
