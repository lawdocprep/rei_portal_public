<?php
declare(strict_types=1);

namespace App\Services\Documents;

use TCPDF;
use RuntimeException;

final class PdfProofEmbedder
{
    public function embed(
        TCPDF $pdf,
        array $bundleProof
    ): void {

        if (empty($bundleProof['bundle_hash'])) {
            throw new RuntimeException('BUNDLE_HASH_MISSING');
        }

        if (!preg_match('/^[a-f0-9]{64}$/', $bundleProof['bundle_hash'])) {
            throw new RuntimeException('BUNDLE_HASH_INVALID');
        }

        $bundleHash = $bundleProof['bundle_hash'];

        $json = json_encode(
            $bundleProof,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException('BUNDLE_PROOF_JSON_FAILED');
        }

        // ------------------------------------------------
        // 1. Metadata embedding
        // ------------------------------------------------

        $pdf->SetCreator('REI Portal');
        $pdf->SetSubject('REI Filing Bundle');
        $pdf->SetKeywords('REI_BUNDLE_HASH:' . $bundleHash);

        $pdf->setHeaderData('', 0, '', '');

        $pdf->setFooterData([
            'bundle_hash' => $bundleHash
        ]);

        // ------------------------------------------------
        // 2. Visible footer text
        // ------------------------------------------------

        $pdf->SetFont('helvetica', '', 7);

        $footerText =
            "REI Portal Filing Bundle\n" .
            "Bundle Hash: " . $bundleHash;

        $pdf->SetY(-18);

        $pdf->MultiCell(
            0,
            10,
            $footerText,
            0,
            'C'
        );

        // ------------------------------------------------
        // 3. QR Code (bundle hash)
        // ------------------------------------------------

        /*
         * TCPDF QR code parameters:
         * type: QRCODE,H (high error correction)
         */

        $style = [
            'border' => false,
            'padding' => 0,
            'fgcolor' => [0,0,0],
            'bgcolor' => false
        ];

        // Page dimensions
        $pageWidth  = $pdf->getPageWidth();
        $pageHeight = $pdf->getPageHeight();

        // QR placement (bottom-right)
        $qrSize = 24;

        $x = $pageWidth - $qrSize - 10;
        $y = $pageHeight - $qrSize - 15;

        $pdf->write2DBarcode(
            $bundleHash,
            'QRCODE,H',
            $x,
            $y,
            $qrSize,
            $qrSize,
            $style,
            'N'
        );

        // ------------------------------------------------
        // 4. Machine-readable proof (hidden page object)
        // ------------------------------------------------

        $pdf->SetFont('helvetica', '', 1);

        $pdf->SetXY(0, 0);

        $pdf->Cell(
            0,
            0,
            'REI_BUNDLE_PROOF:' . $json
        );
    }
}