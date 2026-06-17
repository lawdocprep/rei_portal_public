<?php
declare(strict_types=1);

namespace App\Services\Filing;

use RuntimeException;

final class DocxTemplateCompiler
{
    public function compile(
        string $templateKey,
        string $version,
        string $docxPath
    ): array {
        if (!is_file($docxPath)) {
            throw new RuntimeException('DOCX_TEMPLATE_NOT_FOUND');
        }

        $xml = $this->loadDocumentXml($docxPath);

        $blocks = $this->extractBlocks($xml);
        $fields = $this->extractFields($blocks);

        return [
            'template_key' => $templateKey,
            'version' => $version,
            'source_type' => 'docx',
            'page' => [
                'size' => 'letter',
                'orientation' => 'portrait',
                'margins' => [
                    'top' => 72,
                    'right' => 72,
                    'bottom' => 72,
                    'left' => 72,
                ],
            ],
            'fields' => $fields,
            'blocks' => $blocks,
        ];
    }

    private function loadDocumentXml(string $docxPath): string
    {
        $zip = new \ZipArchive();

        if ($zip->open($docxPath) !== true) {
            throw new RuntimeException('DOCX_OPEN_FAILED');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!is_string($xml) || $xml === '') {
            throw new RuntimeException('DOCX_DOCUMENT_XML_MISSING');
        }

        return $xml;
    }

    private function extractBlocks(string $xml): array
    {
        // Phase 20.6 skeleton:
        // parse DOCX XML into normalized text/layout blocks
        return [];
    }

    private function extractFields(array $blocks): array
    {
        // detect {{token}} references and return schema map
        return [];
    }
}