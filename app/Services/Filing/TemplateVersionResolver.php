<?php
declare(strict_types=1);

namespace App\Services\Filing;

use RuntimeException;

final class TemplateVersionResolver
{
    public function resolveForDocument(array $bundle, string $documentType): array
    {
        $meta = json_decode((string)($bundle['document_templates_c14n_json'] ?? '{}'), true);

        if (!is_array($meta)) {
            throw new RuntimeException('DOCUMENT_TEMPLATE_METADATA_INVALID');
        }

        $doc = $meta[$documentType] ?? null;

        if (!is_array($doc)) {
            throw new RuntimeException('DOCUMENT_TEMPLATE_VERSION_MISSING');
        }

        $templateKey = (string)($doc['template_key'] ?? '');
        $version = (string)($doc['version'] ?? '');

        if ($templateKey === '' || $version === '') {
            throw new RuntimeException('DOCUMENT_TEMPLATE_VERSION_INVALID');
        }

        return [
            'template_key' => $templateKey,
            'version' => $version,
        ];
    }
}