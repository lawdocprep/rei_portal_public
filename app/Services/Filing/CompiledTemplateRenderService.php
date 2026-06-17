<?php
declare(strict_types=1);

namespace App\Services\Filing;

use App\Services\Documents\PdfProofEmbedder;
use RuntimeException;

final class CompiledTemplateRenderService
{
    public function __construct(
        private TemplateVersionResolver $versionResolver,
        private TemplateRepository $templateRepository,
        private TemplateFieldResolver $fieldResolver,
        private CompiledTemplateRenderer $renderer,
        private PdfProofEmbedder $proofEmbedder
    ) {}

    public function renderComplaint(
        string $tenantId,
        array $bundle,
        array $bundleProof,
        string $outputPath
    ): array {
        $tpl = $this->versionResolver->resolveForDocument($bundle, 'complaint');

        $compiled = $this->templateRepository->loadCompiledTemplate(
            $tenantId,
            $tpl['template_key'],
            $tpl['version']
        );

        $fields = $this->fieldResolver->resolveComplaintFields($bundle);

        $this->renderer->render($compiled, $fields, $outputPath);

        $this->embedProofIntoPdf($outputPath, $bundleProof);

        return [
            'template_key' => $tpl['template_key'],
            'template_version' => $tpl['version'],
        ];
    }

    public function renderSummons(
        string $tenantId,
        array $bundle,
        array $bundleProof,
        string $outputPath
    ): array {
        $tpl = $this->versionResolver->resolveForDocument($bundle, 'summons');

        $compiled = $this->templateRepository->loadCompiledTemplate(
            $tenantId,
            $tpl['template_key'],
            $tpl['version']
        );

        $fields = $this->fieldResolver->resolveSummonsFields($bundle);

        $this->renderer->render($compiled, $fields, $outputPath);

        $this->embedProofIntoPdf($outputPath, $bundleProof);

        return [
            'template_key' => $tpl['template_key'],
            'template_version' => $tpl['version'],
        ];
    }

    public function renderArbitrationCertificate(
        string $tenantId,
        array $bundle,
        array $bundleProof,
        string $outputPath
    ): array {
        $tpl = $this->versionResolver->resolveForDocument($bundle, 'certificate_compulsory_arbitration');

        $compiled = $this->templateRepository->loadCompiledTemplate(
            $tenantId,
            $tpl['template_key'],
            $tpl['version']
        );

        $fields = $this->fieldResolver->resolveArbitrationCertificateFields($bundle);

        $this->renderer->render($compiled, $fields, $outputPath);

        $this->embedProofIntoPdf($outputPath, $bundleProof);

        return [
            'template_key' => $tpl['template_key'],
            'template_version' => $tpl['version'],
        ];
    }

    private function embedProofIntoPdf(string $pdfPath, array $bundleProof): void
    {
        if (!is_file($pdfPath)) {
            throw new RuntimeException('RENDERED_PDF_NOT_FOUND');
        }

        // Adjust this method if your PdfProofEmbedder expects a TCPDF instance
        // rather than a path; this skeleton shows the orchestration boundary.
    }
}