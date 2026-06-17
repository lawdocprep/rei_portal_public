<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;

final class StorageController
{
    public function __construct(private \PDO $db) {}

    public function upload(array $req, ?AuthContext $ctx): array
    {
        if (!$ctx) return ['status' => 401];

        if (!isset($_FILES['file'])) {
            return ['status' => 400, 'body' => ['error' => 'FILE_REQUIRED']];
        }

        $file = $_FILES['file'];
        $hash = hash_file('sha256', $file['tmp_name']);

        // Resolve storage root (C:\rei_portal\storage)
        $root = realpath(__DIR__ . '/../../storage');
        if ($root === false) {
            return ['status' => 500, 'body' => ['error' => 'STORAGE_ROOT_NOT_FOUND']];
        }

        // Ensure case-documents subdirectory exists
        $dir = $root . DIRECTORY_SEPARATOR . 'case-documents';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $name = uniqid('doc_', true) . '_' . basename($file['name']);
        $path = $dir . DIRECTORY_SEPARATOR . $name;

        if (!move_uploaded_file($file['tmp_name'], $path)) {
            return ['status' => 500, 'body' => ['error' => 'FILE_MOVE_FAILED']];
        }

        return [
            'status' => 201,
            'body' => [
                'storage_uri' => 'local://' . $name,
                'content_hash' => 'sha256:' . $hash,
                'page_count' => null,
            ],
        ];
    }

    public function resolve(array $req, ?AuthContext $ctx): array
    {
        if (!$ctx) return ['status' => 401];

        $uri = $req['query']['uri'] ?? null;
        if (!$uri || !str_starts_with($uri, 'local://')) {
            return ['status' => 400];
        }

        $root = realpath(__DIR__ . '/../../storage');
        if ($root === false) {
            return ['status' => 500, 'body' => ['error' => 'STORAGE_ROOT_NOT_FOUND']];
        }

        $file = $root . DIRECTORY_SEPARATOR . 'case-documents' . DIRECTORY_SEPARATOR . substr($uri, 8);
        if (!file_exists($file)) {
            return ['status' => 404];
        }

        // Detect MIME type properly
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file) ?: 'application/octet-stream';
        finfo_close($finfo);

        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => $mime,
                'Content-Length' => (string) filesize($file),
                'Cache-Control' => 'no-store',
            ],
            'body' => file_get_contents($file),
        ];
    }
}
