<?php
declare(strict_types=1);

namespace App\Services\Filing;

use App\Services\Storage\StorageService;
use App\Services\Filing\FilingPacketManifestService;

$packetManifestService = new FilingPacketManifestService();
use PDO;
use RuntimeException;
use TCPDF;
use Throwable;
use App\Services\Documents\PdfProofEmbedder;
use App\Services\Filing\Templates\TemplateRegistry;
use App\Services\Filing\TemplateFieldResolver;
use App\Services\Filing\CompiledTemplateRenderer;
use App\Services\Filing\ReisAttachmentService;

final class FilingDocumentRenderService
{
    private PdfProofEmbedder $proofEmbedder;

    private StorageService $storage;

    public function __construct(
        private PDO $db,
        private TemplateRegistry $templateRegistry,
        private TemplateFieldResolver $templateFieldResolver,
        private CompiledTemplateRenderer $compiledTemplateRenderer,
        private ReisAttachmentService $reisAttachmentService,
        ?PdfProofEmbedder $proofEmbedder = null,
        ?StorageService $storage = null
    ) {
        $this->proofEmbedder = $proofEmbedder ?? new PdfProofEmbedder();
        $this->storage = $storage ?? new StorageService();
    }

    /**
     * Phase 20 entrypoint
     *
     * Deterministic + idempotent:
     * - only renders from a committed bundle
     * - serializes concurrent generation per case/legal action
     * - returns existing generated documents if already present
     * - fills in any missing document types if a prior attempt was partial
     */
    public function generateFromCommittedBundle(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $actorId
    ): array {
        $startedTx = false;

        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
            $startedTx = true;
        }

        try {
            $this->acquireGenerationLock($tenantId, $caseId, $legalActionId);

            $bundle = $this->loadCommittedBundleForUpdate(
                $tenantId,
                $caseId,
                $legalActionId
            );

            if ($bundle === null) {
                throw new RuntimeException('COMMITTED_BUNDLE_NOT_FOUND');
            }

            if ((string)$bundle['freeze_level'] !== 'committed') {
                throw new RuntimeException('BUNDLE_NOT_COMMITTED');
            }

            if (!$this->toBool($bundle['frozen'] ?? false)) {
                throw new RuntimeException('BUNDLE_NOT_FROZEN');
            }

            $bundleId = (string)($bundle['id'] ?? '');
            if ($bundleId === '') {
                throw new RuntimeException('BUNDLE_ID_MISSING');
            }

            $bundleProof = BundleProofBuilder::build($bundle);

            $manifest = json_decode(
                (string)($bundle['exhibit_manifest_c14n_json'] ?? '[]'),
                true
            );

            if (!is_array($manifest)) {
                throw new RuntimeException('EXHIBIT_MANIFEST_INVALID');
            }

            $courtType = strtolower(trim((string)($bundle['court_type'] ?? '')));

            $existing = $this->loadExistingDocumentsByType($tenantId, $bundleId);

            $documents = [];

            $documents['complaint'] = $existing['complaint']
                ?? $this->renderComplaint(
                    $tenantId,
                    $caseId,
                    $legalActionId,
                    $actorId,
                    $bundleId,
                    $bundle,
                    $bundleProof
                );

            $documents['summons'] = $existing['summons']
                ?? $this->renderSummons(
                    $tenantId,
                    $caseId,
                    $legalActionId,
                    $actorId,
                    $bundleId,
                    $bundle,
                    $bundleProof
                );

            if ($courtType === 'superior') {
                $documents['certificate_compulsory_arbitration']
                    = $existing['certificate_compulsory_arbitration']
                    ?? $this->renderCertificateCompulsoryArbitration(
                        $tenantId,
                        $caseId,
                        $legalActionId,
                        $actorId,
                        $bundleId,
                        $bundle,
                        $bundleProof
                    );
            }

            $documents['exhibit_packet'] = $existing['exhibit_packet']
                ?? $this->renderExhibitPacket(
                    $tenantId,
                    $caseId,
                    $legalActionId,
                    $actorId,
                    $bundleId,
                    $manifest,
                    $bundleProof
                );

            /*
            |--------------------------------------------------------------------------
            | Reload canonical document set before packet generation
            |--------------------------------------------------------------------------
            */

            $existing = $this->loadExistingDocumentsByType($tenantId, $bundleId);

            $requiredDocs = [
                'complaint',
                'summons',
                'exhibit_packet',
            ];

            if ($courtType === 'superior') {
                $requiredDocs[] = 'certificate_compulsory_arbitration';
            }

            foreach ($requiredDocs as $req) {
                if (!isset($existing[$req])) {
                    throw new RuntimeException(
                        'DOCUMENT_GENERATION_INCOMPLETE:' . $req
                    );
                }
            }

            $documents = $existing;

            $documents['filing_packet'] = $existing['filing_packet']
                ?? $this->renderFilingPacket(
                    $tenantId,
                    $caseId,
                    $legalActionId,
                    $actorId,
                    $bundleId,
                    $documents,
                    $bundleProof
                );

            if ($startedTx) {
                $this->db->commit();
            }

            $docList = [
                $documents['complaint'],
                $documents['summons'],
            ];

            if (isset($documents['certificate_compulsory_arbitration'])) {
                $docList[] = $documents['certificate_compulsory_arbitration'];
            }

            $docList[] = $documents['exhibit_packet'];
            $docList[] = $documents['filing_packet'];

            return [
                'bundle_id' => $bundleId,
                'documents' => $docList,
            ];

        } catch (Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ($e instanceof RuntimeException) {
                throw $e;
            }

            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Backward-compatible alias if the controller calls generateDocuments().
     */
    public function generateDocuments(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $actorId
    ): array {
        return $this->generateFromCommittedBundle(
            $tenantId,
            $caseId,
            $legalActionId,
            $actorId
        );
    }

    /**
     * ------------------------------------------------------------
     * Complaint
     * ------------------------------------------------------------
     */

    private function renderComplaintFromTemplate(
        string $tenantId,
        string $caseId,
        string $bundleId,
        array $data
    ): string {

        /*
        ------------------------------------------------------------
        Resolve template (deterministic registry)
        ------------------------------------------------------------
        */

        $template = $this->templateRegistry->resolve(
            $data['tenant_key'],
            $data['court_type'],
            $data['action_type'],
            'complaint'
        );

        /*
        ------------------------------------------------------------
        Resolve template fields
        ------------------------------------------------------------
        */

        $fieldValues = $data;

        /*
        ------------------------------------------------------------
        Render PDF using compiled template
        ------------------------------------------------------------
        */

        $renderer = new \App\Services\Filing\CompiledTemplateRenderer();

        $pdfPath = $this->createTempPdfPath('complaint');

        $renderer->render(
            $template,
            $fieldValues,
            $pdfPath
        );

        return $pdfPath;
    }

    /**
     * ------------------------------------------------------------
     * Summons
     * ------------------------------------------------------------
     */

    private function renderSummons(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $actorId,
        string $bundleId,
        array $bundle,
        array $bundleProof
    ): array {

        /*
        ------------------------------------------------------------
        Temporary paths
        ------------------------------------------------------------
        */

        $summonsPath = $this->createTempPdfPath('summons');
        $finalPath   = $this->createTempPdfPath('summons_with_reis');

        try {

            /*
            /*
            ------------------------------------------------------------
            1) Resolve template (deterministic registry)
            ------------------------------------------------------------
            */

            $template = $this->templateRegistry->resolve(
                $bundle['tenant_key'],
                $bundle['court_type'],
                $bundle['action_type'],
                'summons'
            );

            /*
            ------------------------------------------------------------
            2) Resolve template fields
            ------------------------------------------------------------
            */

            $fieldValues = $this->templateFieldResolver
                ->resolveSummonsFields($bundle);
           /* ------------------------------------------------------------
            3) Render summons using compiled renderer
            ------------------------------------------------------------
            */

            $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();

            $layout = $template['typography']['pleading_layout_profile'] ?? 'superior';

            $pdf->SetMargins(20, 20, 20);
            $pdf->SetAutoPageBreak(true, 25);

            $this->compiledTemplateRenderer->render(
                $pdf,
                $template,
                $fieldValues,
                $layout
            );

            /*
            ------------------------------------------------------------
            Embed proof metadata BEFORE merge
            ------------------------------------------------------------
            */

            $this->proofEmbedder->embed($pdf, $bundleProof);

            $pdf->Output($summonsPath, 'F');

            /*
            ------------------------------------------------------------
            4) Append REIS (static regulatory attachment)
            ------------------------------------------------------------
            */

            $reisPath = $this->getReisStaticPath();

            if (!is_file($reisPath)) {
                throw new RuntimeException('REIS_FILE_NOT_FOUND');
            }

            $merged = new \setasign\Fpdi\Tcpdf\Fpdi();

            $merged->SetMargins(0, 0, 0);
            $merged->SetAutoPageBreak(false);

            /*
            ------------------------------------------------------------
            Import summons pages
            ------------------------------------------------------------
            */

            $pageCount = $merged->setSourceFile($summonsPath);

            for ($i = 1; $i <= $pageCount; $i++) {

                $tplId = $merged->importPage($i);

                $size = $merged->getTemplateSize($tplId);

                $merged->AddPage(
                    $size['orientation'],
                    [$size['width'], $size['height']]
                );

                $merged->useTemplate($tplId);
            }

            /*
            ------------------------------------------------------------
            Import REIS pages
            ------------------------------------------------------------
            */

            $reisPages = $merged->setSourceFile($reisPath);

            for ($i = 1; $i <= $reisPages; $i++) {

                $tplId = $merged->importPage($i);

                $size = $merged->getTemplateSize($tplId);

                $merged->AddPage(
                    $size['orientation'],
                    [$size['width'], $size['height']]
                );

                $merged->useTemplate($tplId);
            }

            $merged->Output($finalPath, 'F');

            /*
            ------------------------------------------------------------
            5) Store final summons document
            ------------------------------------------------------------
            */

            return $this->storeGeneratedDocument(
                $tenantId,
                $caseId,
                $legalActionId,
                $actorId,
                $bundleId,
                'summons',
                $finalPath
            );

        } finally {

            /*
            ------------------------------------------------------------
            Cleanup temporary files
            ------------------------------------------------------------
            */

            $this->safeUnlink($summonsPath);
            $this->safeUnlink($finalPath);
        }
    }

    /**
     * ------------------------------------------------------------
     * Exhibit Packet
     * ------------------------------------------------------------
     */

    private function renderExhibitPacket(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $actorId,
        string $bundleId,
        array $manifest,
        array $bundleProof
    ): array {
        $path = $this->createTempPdfPath('exhibit_packet');

        try {
            $renderer = new ExhibitPacketPdfRenderer($this->proofEmbedder);

            $renderer->render(
                manifest: $manifest,
                bundleProof: $bundleProof,
                outputPath: $path
            );

            return $this->storeGeneratedDocument(
                $tenantId,
                $caseId,
                $legalActionId,
                $actorId,
                $bundleId,
                'exhibit_packet',
                $path
            );
        } finally {
            $this->safeUnlink($path);
        }
    }

    private function renderCertificateCompulsoryArbitration(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $actorId,
        string $bundleId,
        array $bundle,
        array $bundleProof
    ): array {

        $proofPath = $this->createTempPdfPath(
            'certificate_compulsory_arbitration'
        );

        try {

            /*
            ------------------------------------------------------------
            1) Resolve template
            ------------------------------------------------------------
            */

            $template = $this->templateRegistry->resolve(
                (string)$bundle['tenant_key'],
                (string)$bundle['court_type'],
                (string)$bundle['action_type'],
                'certificate_compulsory_arbitration'
            );

            /*
            ------------------------------------------------------------
            2) Resolve fields
            ------------------------------------------------------------
            */

            $fieldValues = $this->templateFieldResolver
                ->resolveArbitrationCertificateFields($bundle);

            /*
            ------------------------------------------------------------
            3) Render template
            ------------------------------------------------------------
            */

            $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();

            $layout =
                $template['typography']['pleading_layout_profile']
                ?? 'superior';

            $pdf->SetMargins(20, 20, 20);
            $pdf->SetAutoPageBreak(true, 25);

            $this->compiledTemplateRenderer->render(
                $pdf,
                $template,
                $fieldValues,
                $layout
            );

            /*
            ------------------------------------------------------------
            4) Embed proof directly
            ------------------------------------------------------------
            */

            $this->proofEmbedder->embed(
                $pdf,
                $bundleProof
            );

            $pdf->Output($proofPath, 'F');

            /*
            ------------------------------------------------------------
            5) Store document
            ------------------------------------------------------------
            */

            return $this->storeGeneratedDocument(
                $tenantId,
                $caseId,
                $legalActionId,
                $actorId,
                $bundleId,
                'certificate_compulsory_arbitration',
                $proofPath
            );

        } finally {

            $this->safeUnlink($proofPath);

        }
    }

    /**
     * ------------------------------------------------------------
     * Filing Packet
     * ------------------------------------------------------------
     */

    private function renderFilingPacket(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $actorId,
        string $bundleId,
        array $documents,
        array $bundleProof
    ): array {

        $path = $this->createTempPdfPath('filing_packet');

        try {

            $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
            $pdf->SetMargins(20, 20, 20);
            $pdf->SetAutoPageBreak(true, 25);

            /* ------------------------------------------------------------
            * Normalize documents by type
            * ------------------------------------------------------------ */

            $docsByType = [];

            foreach ($documents as $doc) {
                if (!empty($doc['document_type'])) {
                    $docsByType[$doc['document_type']] = $doc;
                }
            }

            /*
            ------------------------------------------------------------
            Required document guard
            ------------------------------------------------------------
            */

            $requiredPacketDocs = [
                'summons',
                'complaint',
                'exhibit_packet'
            ];

            foreach ($requiredPacketDocs as $required) {

                if (!isset($docsByType[$required])) {

                    throw new \RuntimeException(
                        'REQUIRED_PACKET_DOCUMENT_MISSING:' . $required
                    );

                }

                if (empty($docsByType[$required]['storage_key'])) {

                    throw new \RuntimeException(
                        'DOCUMENT_STORAGE_KEY_MISSING:' . $required
                    );

                }

            }

            /* ------------------------------------------------------------
            * Canonical packet order
            * ------------------------------------------------------------ */

            $orderedTypes = [
                'summons',
                'complaint',
                'exhibit_packet'
            ];

            /* ------------------------------------------------------------
            * Superior Court: arbitration certificate
            * ------------------------------------------------------------ */

            if (isset($docsByType['certificate_compulsory_arbitration'])) {
                $orderedTypes[] = 'certificate_compulsory_arbitration';
            }

            /* ------------------------------------------------------------
            * Merge PDFs
            * ------------------------------------------------------------ */

            foreach ($orderedTypes as $type) {

                if (!isset($docsByType[$type])) {
                    continue;
                }

                $doc = $docsByType[$type];

                if (empty($doc['storage_key'])) {
                    throw new \RuntimeException(
                        'DOCUMENT_STORAGE_KEY_MISSING:' . $type
                    );
                }

                $tmp = $this->createTempPdfPath('merge');

                try {

                    $stream = $this->storage->stream($doc['storage_key']);

                    if ($stream === false) {
                        throw new \RuntimeException(
                            'DOCUMENT_STORAGE_READ_FAILED:' . $type
                        );
                    }

                    file_put_contents($tmp, $stream);

                    $pageCount = $pdf->setSourceFile($tmp);

                    for ($page = 1; $page <= $pageCount; $page++) {

                        $tpl = $pdf->importPage($page);

                        $size = $pdf->getTemplateSize($tpl);

                        $pdf->AddPage(
                            $size['orientation'],
                            [$size['width'], $size['height']]
                        );

                        $pdf->useTemplate($tpl);
                    }

                } finally {

                    $this->safeUnlink($tmp);

                }
            }

            /* ------------------------------------------------------------
            * Phase 20.5 — Packet Manifest
            * ------------------------------------------------------------ */

            $packetManifestService = new \App\Services\Filing\FilingPacketManifestService();

            $sourceDocs = [];

            foreach ($docsByType as $doc) {

                if (!empty($doc['document_type']) && !empty($doc['file_hash'])) {

                    $sourceDocs[] = [
                        'document_type' => $doc['document_type'],
                        'document_id'   => $doc['id'] ?? null,
                        'file_hash'     => $doc['file_hash']
                    ];
                }
            }

            $manifest = $packetManifestService->buildPacketManifest(
                $tenantId,
                $caseId,
                $legalActionId,
                $bundleId,
                $bundleProof['bundle_hash'],
                (string)($bundle['court_type'] ?? 'unknown'),
                $sourceDocs
            );

            $packetManifestHash = $packetManifestService->computePacketManifestHash($manifest);

            $packetProof = $packetManifestService->buildEmbeddedProof(
                $manifest,
                $packetManifestHash
            );

            /* ------------------------------------------------------------
            * Combine bundle proof + packet proof
            * ------------------------------------------------------------ */

            $pdfProof = [
                'proof_type' => 'filing_packet_proof_bundle_v1',
                'bundle_proof' => $bundleProof,
                'packet_manifest_proof' => $packetProof
            ];

            /* ------------------------------------------------------------
            * Embed combined proof
            * ------------------------------------------------------------ */

            $this->proofEmbedder->embed(
                $pdf,
                $pdfProof
            );

            $pdf->Output($path, 'F');

            return $this->storeGeneratedDocument(
                $tenantId,
                $caseId,
                $legalActionId,
                $actorId,
                $bundleId,
                'filing_packet',
                $path
            );

        } finally {

            $this->safeUnlink($path);

        }
    }

    /**
     * ------------------------------------------------------------
     * Document Storage
     * ------------------------------------------------------------
     */

    private function storeGeneratedDocument(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $actorId,
        string $bundleId,
        string $documentType,
        string $tempFile
    ): array {

        $hash = hash_file('sha256', $tempFile);

        $storageKey = sprintf(
            'filings/%s/%s/%s/%s.pdf',
            $tenantId,
            $caseId,
            $bundleId,
            $documentType
        );

        $this->storage->put(
            $storageKey,
            $tempFile
        );

        $documentId = $this->computeDocumentId(
            $bundleId,
            $documentType
        );

        $st = $this->db->prepare("
            INSERT INTO filing_documents (
                id,
                tenant_id,
                case_id,
                legal_action_id,
                bundle_id,
                document_type,
                storage_key,
                file_hash,
                created_at
            )
            VALUES (
                :id,
                :tenant,
                :case,
                :action,
                :bundle,
                :dtype,
                :storage,
                :hash,
                now()
            )
            ON CONFLICT (bundle_id, document_type)
            DO NOTHING
        ");

        $st->execute([
            ':id' => $documentId,
            ':tenant' => $tenantId,
            ':case' => $caseId,
            ':action' => $legalActionId,
            ':bundle' => $bundleId,
            ':dtype' => $documentType,
            ':storage' => $storageKey,
            ':hash' => $hash
        ]);

        return [
            'id'            => $documentId,
            'document_type' => $documentType,
            'storage_key'   => $storageKey,
            'file_hash'     => $hash
        ];
    }

    private function fetchStoredGeneratedDocument(
        string $tenantId,
        string $bundleId,
        string $documentType
    ): ?array {
        $st = $this->db->prepare("
            SELECT
                id,
                tenant_id,
                case_id,
                legal_action_id,
                bundle_id,
                document_type,
                file_path,
                file_hash,
                created_at
            FROM filing_documents
            WHERE tenant_id = :tenant
              AND bundle_id = :bundle
              AND document_type = :dtype
            LIMIT 1
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':bundle' => $bundleId,
            ':dtype'  => $documentType,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'id'              => (string)$row['id'],
            'bundle_id'       => (string)$row['bundle_id'],
            'document_type'   => (string)$row['document_type'],
            'file_path'       => (string)$row['file_path'],
            'file_hash'       => (string)$row['file_hash'],
            'created_at'      => $row['created_at'],
        ];
    }

    /**
     * ------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------
     */

    private function createTempPdfPath(string $type): string
    {
        return sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . $type
            . '_'
            . bin2hex(random_bytes(8))
            . '.pdf';
    }

    private function loadCommittedBundleForUpdate(
        string $tenantId,
        string $caseId,
        string $legalActionId
    ): ?array {
        $st = $this->db->prepare("
            SELECT *
            FROM filing_bundles
            WHERE tenant_id = :tenant
              AND case_id = :case_id
              AND legal_action_id = :legal_action_id
              AND freeze_level = 'committed'
            LIMIT 1
            FOR UPDATE
        ");

        $st->execute([
            ':tenant'          => $tenantId,
            ':case_id'         => $caseId,
            ':legal_action_id' => $legalActionId,
        ]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Serialize document generation per tenant/case/legal action.
     */
    private function acquireGenerationLock(
        string $tenantId,
        string $caseId,
        string $legalActionId
    ): void {
        $lockKey = 'filing-docgen:' . $tenantId . ':' . $caseId . ':' . $legalActionId;

        $st = $this->db->prepare("
            SELECT pg_advisory_xact_lock(hashtext(:lock_key))
        ");
        $st->execute([
            ':lock_key' => $lockKey,
        ]);
    }

    /**
     * Load any already-generated documents for this bundle.
     *
     * @return array<string,array<string,mixed>>
     */
    private function loadExistingDocumentsByType(
        string $tenantId,
        string $bundleId
    ): array {
        $st = $this->db->prepare("
            SELECT
                id,
                bundle_id,
                document_type,
                file_path,
                file_hash,
                created_at
            FROM filing_documents
            WHERE tenant_id = :tenant
              AND bundle_id = :bundle
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':bundle' => $bundleId,
        ]);

        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];

        foreach ($rows as $row) {
            $type = (string)$row['document_type'];
            $out[$type] = [
                'id'            => (string)$row['id'],
                'bundle_id'     => (string)$row['bundle_id'],
                'document_type' => $type,
                'file_path'     => (string)$row['file_path'],
                'file_hash'     => (string)$row['file_hash'],
                'created_at'    => $row['created_at'],
            ];
        }

        return $out;
    }

    /**
     * Deterministic UUID derived from bundle_id + document_type.
     */
    private function computeDocumentId(
        string $bundleId,
        string $documentType
    ): string {
        $hex = sha1($bundleId . ':' . $documentType);

        $timeLow = substr($hex, 0, 8);
        $timeMid = substr($hex, 8, 4);
        $timeHi  = substr($hex, 12, 4);
        $clock   = substr($hex, 16, 4);
        $node    = substr($hex, 20, 12);

        // UUIDv5-style bit normalization
        $timeHiInt = hexdec($timeHi);
        $timeHiInt = ($timeHiInt & 0x0fff) | 0x5000;
        $timeHi    = str_pad(dechex($timeHiInt), 4, '0', STR_PAD_LEFT);

        $clockInt = hexdec($clock);
        $clockInt = ($clockInt & 0x3fff) | 0x8000;
        $clock    = str_pad(dechex($clockInt), 4, '0', STR_PAD_LEFT);

        return sprintf(
            '%s-%s-%s-%s-%s',
            $timeLow,
            $timeMid,
            $timeHi,
            $clock,
            $node
        );
    }

    private function renderTemplateDocument(
        string $tenantId,
        string $caseId,
        string $legalActionId,
        string $actorId,
        string $bundleId,
        string $documentType,
        array $bundle,
        array $bundleProof
    ): array {

        $tempPath = $this->createTempPdfPath($documentType);

        try {

            /*
            ------------------------------------------------------------
            Resolve template fields
            ------------------------------------------------------------
            */

            $resolver = new TemplateFieldResolver($this->db);

            $method = match ($documentType) {
                'complaint' => 'resolveComplaintFields',
                'summons' => 'resolveSummonsFields',
                'certificate_compulsory_arbitration' => 'resolveArbitrationCertificateFields',
                default => throw new RuntimeException(
                    'UNKNOWN_DOCUMENT_TYPE:' . $documentType
                )
            };

            $fields = $resolver->$method($bundle);

            /*
            ------------------------------------------------------------
            Resolve compiled template
            ------------------------------------------------------------
            */

            /*
            ------------------------------------------------------------
            Resolve compiled template (deterministic registry)
            ------------------------------------------------------------
            */

            $template = $this->templateRegistry->resolve(
                $bundle['tenant_key'],
                $bundle['court_type'],
                $bundle['action_type'],
                $documentType
            );

            /*
            ------------------------------------------------------------
            Render PDF
            ------------------------------------------------------------
            */

            $renderer = new \App\Services\Filing\CompiledTemplateRenderer();

            $renderer->render(
                $template,
                $fields,
                $tempPath
            );

            /*
            ------------------------------------------------------------
            Embed proof
            ------------------------------------------------------------
            */

            $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();

            $pageCount = $pdf->setSourceFile($tempPath);

            for ($page = 1; $page <= $pageCount; $page++) {

                $tpl = $pdf->importPage($page);

                $size = $pdf->getTemplateSize($tpl);

                $pdf->AddPage(
                    $size['orientation'],
                    [$size['width'], $size['height']]
                );

                $pdf->useTemplate($tpl);
            }

            $this->proofEmbedder->embed($pdf, $bundleProof);

            $proofPath = $this->createTempPdfPath($documentType . '_proof');

            $pdf->Output($proofPath, 'F');

            return $this->storeGeneratedDocument(
                $tenantId,
                $caseId,
                $legalActionId,
                $actorId,
                $bundleId,
                $documentType,
                $proofPath
            );

        } finally {

            $this->safeUnlink($tempPath);
        }
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 't', 'true', 'y', 'yes'], true);
        }

        return false;
    }

    private function safeUnlink(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}