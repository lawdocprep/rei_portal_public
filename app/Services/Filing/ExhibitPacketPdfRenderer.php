<?php
declare(strict_types=1);

namespace App\Services\Filing;

use RuntimeException;
use TCPDF;
use App\Services\Documents\PdfProofEmbedder;
use setasign\Fpdi\Tcpdf\Fpdi;

final class ExhibitPacketPdfRenderer
{
    public function __construct(
        private PdfProofEmbedder $proofEmbedder
    ) {}

    /**
     * Render the full exhibit packet from the immutable committed-bundle manifest.
     *
     * @param array<int,array<string,mixed>> $manifest
     *   Expected per-row keys:
     *   - label
     *   - case_document_id
     *   - document_version_id
     *   - storage_key
     *   - content_sha256
     *   - mime_type
     *   - filename
     *   - byte_length (optional)
     *
     * @param array<string,mixed> $bundleProof
     */
    public function render(
        array $manifest,
        array $bundleProof,
        string $outputPath
    ): void {
        $this->assertManifestValid($manifest);

        $pdf = new Fpdi('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->SetCreator('REI Portal');
        $pdf->SetAuthor('REI Portal');
        $pdf->SetTitle('Exhibit Packet');
        $pdf->SetSubject('Exhibit Packet');
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        foreach ($manifest as $index => $exhibit) {
            $label = (string)$exhibit['label'];
            $path  = (string)$exhibit['storage_key'];
            $mime  = strtolower((string)$exhibit['mime_type']);
            $name  = (string)($exhibit['filename'] ?? ('exhibit_' . $label));

            $this->verifyManifestRow($exhibit);
            $this->verifyExhibitPayload($exhibit);

            $this->renderDividerPage(
                pdf: $pdf,
                label: $label,
                filename: $name,
                exhibitIndex: $index + 1,
                exhibitCount: count($manifest),
                sha256: (string)$exhibit['content_sha256']
            );

            if ($mime === 'application/pdf') {
                $this->appendPdfExhibit(
                    pdf: $pdf,
                    sourcePath: $path,
                    exhibitLabel: $label
                );
                continue;
            }

            if (in_array($mime, ['image/jpeg', 'image/jpg', 'image/png'], true)) {
                $this->appendImageExhibit(
                    pdf: $pdf,
                    sourcePath: $path,
                    mimeType: $mime,
                    exhibitLabel: $label,
                    filename: $name
                );
                continue;
            }

            throw new RuntimeException('EXHIBIT_UNSUPPORTED_MIME_TYPE:' . $mime);
        }

        // Embed proof after packet content is complete so proof reflects final packet identity context.
        $this->proofEmbedder->embed($pdf, $bundleProof);

        $pdf->Output($outputPath, 'F');

        $this->assertOutputCreated($outputPath);
    }

    /**
     * @param array<int,array<string,mixed>> $manifest
     */
    private function assertManifestValid(array $manifest): void
    {
        if ($manifest === []) {
            throw new RuntimeException('EXHIBIT_MANIFEST_EMPTY');
        }

        $seen = [];

        foreach ($manifest as $i => $row) {
            if (!is_array($row)) {
                throw new RuntimeException('EXHIBIT_MANIFEST_ROW_INVALID:' . $i);
            }

            $label = (string)($row['label'] ?? '');
            if ($label === '') {
                throw new RuntimeException('EXHIBIT_LABEL_MISSING:' . $i);
            }

            if (isset($seen[$label])) {
                throw new RuntimeException('EXHIBIT_LABEL_DUPLICATE:' . $label);
            }

            $seen[$label] = true;
        }

        // Deterministic order: packet must follow manifest exactly.
        // No sorting here; manifest order is canonical.
        $expected = 1;
        foreach ($manifest as $row) {
            $label = (string)$row['label'];

            // Optional stricter rule for A, B, C ... sequence
            $expectedLabel = $this->alphaLabel($expected);
            if ($label !== $expectedLabel) {
                throw new RuntimeException(
                    'EXHIBIT_LABEL_SEQUENCE_INVALID:expected='
                    . $expectedLabel
                    . ';actual='
                    . $label
                );
            }

            $expected++;
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private function verifyManifestRow(array $row): void
    {
        $required = [
            'label',
            'case_document_id',
            'document_version_id',
            'storage_key',
            'content_sha256',
            'mime_type',
            'filename',
        ];

        foreach ($required as $key) {
            if (!array_key_exists($key, $row)) {
                throw new RuntimeException('EXHIBIT_MANIFEST_FIELD_MISSING:' . $key);
            }

            $value = $row[$key];
            if (!is_string($value) || trim($value) === '') {
                throw new RuntimeException('EXHIBIT_MANIFEST_FIELD_INVALID:' . $key);
            }
        }

        $hash = (string)$row['content_sha256'];
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new RuntimeException('EXHIBIT_MANIFEST_HASH_INVALID');
        }

        $path = (string)$row['storage_key'];
        if (!is_file($path)) {
            throw new RuntimeException('EXHIBIT_STORAGE_OBJECT_MISSING:' . $path);
        }

        if (!is_readable($path)) {
            throw new RuntimeException('EXHIBIT_STORAGE_OBJECT_UNREADABLE:' . $path);
        }

        if (isset($row['byte_length']) && $row['byte_length'] !== null) {
            $expectedBytes = (int)$row['byte_length'];
            $actualBytes   = filesize($path);
            if ($expectedBytes > 0 && $actualBytes !== false && $actualBytes !== $expectedBytes) {
                throw new RuntimeException('EXHIBIT_BYTE_LENGTH_MISMATCH:' . (string)$row['label']);
            }
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private function verifyExhibitPayload(array $row): void
    {
        $path         = (string)$row['storage_key'];
        $expectedHash = (string)$row['content_sha256'];

        $actualHash = hash_file('sha256', $path);
        if (!is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('EXHIBIT_HASH_MISMATCH:' . (string)$row['label']);
        }

        // Optional mime sanity check based on file signature / finfo
        $detectedMime = $this->detectMimeType($path);
        $manifestMime = strtolower((string)$row['mime_type']);

        if ($detectedMime !== null) {
            $normalizedDetected = $this->normalizeMime($detectedMime);
            $normalizedManifest = $this->normalizeMime($manifestMime);

            if ($normalizedDetected !== $normalizedManifest) {
                throw new RuntimeException(
                    'EXHIBIT_MIME_MISMATCH:' . (string)$row['label']
                    . ':manifest=' . $normalizedManifest
                    . ':actual=' . $normalizedDetected
                );
            }
        }
    }

    private function renderDividerPage(
        Fpdi $pdf,
        string $label,
        string $filename,
        int $exhibitIndex,
        int $exhibitCount,
        string $sha256
    ): void {
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 24);
        $pdf->Ln(20);
        $pdf->Cell(0, 14, 'Exhibit ' . $label, 0, 1, 'C');

        $pdf->Ln(8);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->MultiCell(
            0,
            8,
            "Exhibit {$exhibitIndex} of {$exhibitCount}\n"
            . "Filename: {$filename}\n"
            . "SHA-256: {$sha256}",
            0,
            'C'
        );

        $pdf->Ln(12);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(
            0,
            7,
            'This divider page is part of a deterministic exhibit packet generated from a committed filing bundle.',
            0,
            'C'
        );
    }

    private function appendPdfExhibit(
        Fpdi $pdf,
        string $sourcePath,
        string $exhibitLabel
    ): void {
        try {
            $pageCount = $pdf->setSourceFile($sourcePath);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'EXHIBIT_SOURCE_PDF_OPEN_FAILED:' . $exhibitLabel . ':' . $e->getMessage(),
                0,
                $e
            );
        }

        if ($pageCount < 1) {
            throw new RuntimeException('EXHIBIT_SOURCE_PDF_EMPTY:' . $exhibitLabel);
        }

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            try {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
            } catch (\Throwable $e) {
                throw new RuntimeException(
                    'EXHIBIT_SOURCE_PDF_IMPORT_FAILED:' . $exhibitLabel . ':page=' . $pageNo,
                    0,
                    $e
                );
            }

            $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId);

            $this->stampExhibitPage(
                pdf: $pdf,
                exhibitLabel: $exhibitLabel
            );
        }
    }

    private function appendImageExhibit(
        Fpdi $pdf,
        string $sourcePath,
        string $mimeType,
        string $exhibitLabel,
        string $filename
    ): void {
        [$imgWidthPx, $imgHeightPx] = $this->imageDimensions($sourcePath, $exhibitLabel);

        $pdf->AddPage();
        $this->stampExhibitPage($pdf, $exhibitLabel);

        $pageW = $pdf->getPageWidth() - 30.0;
        $pageH = $pdf->getPageHeight() - 40.0;

        $scale = min($pageW / $imgWidthPx, $pageH / $imgHeightPx);
        $renderW = $imgWidthPx * $scale;
        $renderH = $imgHeightPx * $scale;

        $x = ($pdf->getPageWidth() - $renderW) / 2.0;
        $y = 20.0 + (($pageH - $renderH) / 2.0);

        $imageType = match ($this->normalizeMime($mimeType)) {
            'image/jpeg' => 'JPG',
            'image/png'  => 'PNG',
            default      => throw new RuntimeException('EXHIBIT_IMAGE_TYPE_UNSUPPORTED:' . $mimeType),
        };

        try {
            $pdf->Image(
                $sourcePath,
                $x,
                $y,
                $renderW,
                $renderH,
                $imageType,
                '',
                '',
                false,
                300,
                '',
                false,
                false,
                0,
                false,
                false,
                false
            );
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'EXHIBIT_IMAGE_RENDER_FAILED:' . $exhibitLabel . ':' . $filename,
                0,
                $e
            );
        }
    }

    private function stampExhibitPage(
        TCPDF $pdf,
        string $exhibitLabel
    ): void {
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(80, 80, 80);

        $pdf->SetXY(12, 8);
        $pdf->Cell(0, 4, 'Exhibit ' . $exhibitLabel, 0, 0, 'L');

        $pdf->SetXY(-45, 8);
        $pdf->Cell(33, 4, 'REI Portal Exhibit Packet', 0, 0, 'R');

        $pdf->SetTextColor(0, 0, 0);
    }

    private function assertOutputCreated(string $outputPath): void
    {
        if (!is_file($outputPath)) {
            throw new RuntimeException('EXHIBIT_PACKET_OUTPUT_MISSING');
        }

        if (!is_readable($outputPath)) {
            throw new RuntimeException('EXHIBIT_PACKET_OUTPUT_UNREADABLE');
        }

        $size = filesize($outputPath);
        if ($size === false || $size <= 0) {
            throw new RuntimeException('EXHIBIT_PACKET_OUTPUT_EMPTY');
        }
    }

    private function detectMimeType(string $path): ?string
    {
        if (!class_exists(\finfo::class)) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    private function normalizeMime(string $mime): string
    {
        $mime = strtolower(trim($mime));

        return match ($mime) {
            'image/jpg' => 'image/jpeg',
            default => $mime,
        };
    }

    /**
     * @return array{0:int,1:int}
     */
    private function imageDimensions(string $path, string $label): array
    {
        $size = @getimagesize($path);
        if (!is_array($size) || !isset($size[0], $size[1])) {
            throw new RuntimeException('EXHIBIT_IMAGE_DIMENSIONS_FAILED:' . $label);
        }

        return [(int)$size[0], (int)$size[1]];
    }

    private function alphaLabel(int $n): string
    {
        $label = '';
        while ($n > 0) {
            $n--;
            $label = chr(65 + ($n % 26)) . $label;
            $n = intdiv($n, 26);
        }
        return $label;
    }
}