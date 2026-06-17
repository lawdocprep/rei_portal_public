<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FPDI + TCPDF Integration Test (REI Portal)
|--------------------------------------------------------------------------
|
| Verifies that:
|  - Composer autoload works
|  - TCPDF loads
|  - FPDI loads
|  - PDF page import works
|
| Generates: test.pdf
|
*/

require_once 'C:/rei_portal/vendor/autoload.php';

use setasign\Fpdi\Tcpdf\Fpdi;

echo "------------------------------------\n";
echo "REI Portal FPDI Test\n";
echo "------------------------------------\n";

/*
|--------------------------------------------------------------------------
| Input PDF
|--------------------------------------------------------------------------
*/

$sourcePdf = 'C:/rei_portal/example.pdf';

if (!file_exists($sourcePdf)) {

    echo "ERROR: example.pdf not found\n";
    echo "Expected location:\n";
    echo $sourcePdf . "\n";

    exit(1);
}

/*
|--------------------------------------------------------------------------
| Output PDF
|--------------------------------------------------------------------------
*/

$outputPdf = 'C:/rei_portal/test.pdf';

try {

    echo "Loading FPDI...\n";

    $pdf = new Fpdi();

    echo "TCPDF + FPDI loaded successfully\n";

    $pdf->AddPage();

    echo "Opening source PDF...\n";

    $pageCount = $pdf->setSourceFile($sourcePdf);

    if ($pageCount < 1) {
        throw new RuntimeException("Source PDF contains no pages");
    }

    echo "Source PDF page count: {$pageCount}\n";

    echo "Importing page 1...\n";

    $template = $pdf->importPage(1);

    $pdf->useTemplate($template);

    echo "Writing output file...\n";

    $pdf->Output($outputPdf, 'F');

    if (!file_exists($outputPdf)) {
        throw new RuntimeException("Output file not created");
    }

    echo "\nSUCCESS\n";
    echo "Generated file:\n";
    echo $outputPdf . "\n";

} catch (Throwable $e) {

    echo "\nERROR\n";
    echo $e->getMessage() . "\n";

    exit(1);
}