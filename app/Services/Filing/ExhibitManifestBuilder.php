<?php
declare(strict_types=1);

namespace App\Services\Filing;

use PDO;
use RuntimeException;

final class ExhibitManifestBuilder
{
    public function __construct(private PDO $db) {}

    public function build(
        string $tenantId,
        string $caseId,
        array $exhibitLinkage
    ): array {

        $manifest = [];

        foreach ($exhibitLinkage as $exhibit) {

            $docId = $exhibit['case_document_id'];
            $label = $exhibit['label'];

            $version = $this->resolveFinalDocumentVersion(
                $tenantId,
                $caseId,
                $docId
            );

            $storage = $this->resolveStorageObject(
                $version['id']
            );

            $hash = hash_file('sha256', $storage['file_path']);

            if ($hash !== $storage['file_hash']) {
                throw new RuntimeException(
                    'EXHIBIT_HASH_MISMATCH: ' . $docId
                );
            }

            $manifest[] = [
                'label' => $label,
                'case_document_id' => $docId,
                'document_version_id' => $version['id'],
                'storage_key' => $storage['file_path'],
                'content_sha256' => $hash,
                'mime_type' => $storage['mime_type'],
                'filename' => $storage['filename'],
                'byte_length' => filesize($storage['file_path']),
            ];
        }

        usort($manifest, fn($a,$b) => strcmp($a['label'],$b['label']));

        return $manifest;
    }

    private function resolveFinalDocumentVersion(
        string $tenantId,
        string $caseId,
        string $docId
    ): array {

        $st = $this->db->prepare("
            SELECT dv.id
            FROM case_document_versions dv
            JOIN case_documents d ON d.id = dv.document_id
            WHERE d.tenant_id = :tenant
              AND d.case_id = :case
              AND d.id = :doc
              AND dv.status = 'final'
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case' => $caseId,
            ':doc' => $docId
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException(
                'EXHIBIT_FINAL_VERSION_NOT_FOUND: ' . $docId
            );
        }

        return $row;
    }

    private function resolveStorageObject(
        string $versionId
    ): array {

        $st = $this->db->prepare("
            SELECT file_path,
                   file_hash,
                   mime_type,
                   filename
            FROM document_storage
            WHERE document_version_id = :vid
            LIMIT 1
        ");

        $st->execute([
            ':vid' => $versionId
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException(
                'EXHIBIT_STORAGE_NOT_FOUND: ' . $versionId
            );
        }

        return $row;
    }
}