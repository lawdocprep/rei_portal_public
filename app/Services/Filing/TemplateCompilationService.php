<?php
declare(strict_types=1);

namespace App\Services\Filing;

use PDO;

final class TemplateCompilationService
{
    public function __construct(
        private PDO $db,
        private DocxTemplateCompiler $compiler
    ) {}

    public function compileAndStore(
        string $tenantId,
        string $templateKey,
        string $version,
        string $docxPath
    ): array {
        $compiled = $this->compiler->compile(
            $templateKey,
            $version,
            $docxPath
        );

        $st = $this->db->prepare("
            INSERT INTO document_template_versions (
                tenant_id,
                template_key,
                version,
                source_type,
                compiled_template_json,
                created_at
            )
            VALUES (
                :tenant_id,
                :template_key,
                :version,
                :source_type,
                :compiled_template_json,
                now()
            )
            ON CONFLICT (tenant_id, template_key, version)
            DO UPDATE SET
                source_type = EXCLUDED.source_type,
                compiled_template_json = EXCLUDED.compiled_template_json
        ");

        $st->execute([
            ':tenant_id' => $tenantId,
            ':template_key' => $templateKey,
            ':version' => $version,
            ':source_type' => 'docx',
            ':compiled_template_json' => json_encode(
                $compiled,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
        ]);

        return [
            'tenant_id' => $tenantId,
            'template_key' => $templateKey,
            'version' => $version,
        ];
    }
}