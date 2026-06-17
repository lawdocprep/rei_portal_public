<?php
declare(strict_types=1);

namespace App\Services\Filing;

use RuntimeException;

final class DocxToPdfConverter
{
    public function convert(string $docxPath, string $outputDir): string
    {
        $cmd = sprintf(
            'soffice --headless --convert-to pdf --outdir %s %s',
            escapeshellarg($outputDir),
            escapeshellarg($docxPath)
        );

        exec($cmd, $out, $code);

        if ($code !== 0) {
            throw new RuntimeException('DOCX_TO_PDF_FAILED');
        }

        $pdfPath = $outputDir . '/' . basename($docxPath, '.docx') . '.pdf';

        if (!file_exists($pdfPath)) {
            throw new RuntimeException('PDF_NOT_CREATED');
        }

        return $pdfPath;
    }
}