<?php
declare(strict_types=1);

namespace App\Services\Filing;

use PDO;
use RuntimeException;

final class TemplateRepository
{
    public function __construct(private PDO $db) {}

    public function loadCompiledTemplate(
        string $tenantId,
        string $templateKey,
        string $version
    ): array {
        $st = $this->db->prepare("
            SELECT compiled_template_json
            FROM document_template_versions
            WHERE tenant_id = :tenant_id
              AND template_key = :template_key
              AND version = :version
            LIMIT 1
        ");

        $st->execute([
            ':tenant_id' => $tenantId,
            ':template_key' => $templateKey,
            ':version' => $version,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('COMPILED_TEMPLATE_NOT_FOUND');
        }

        $compiled = json_decode((string)$row['compiled_template_json'], true);

        if (!is_array($compiled)) {
            throw new RuntimeException('COMPILED_TEMPLATE_INVALID');
        }

        return $compiled;
    }
}