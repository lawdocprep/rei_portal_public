<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Auth\IdempotencyGuard;
use App\Auth\AuditEmitter;
use PDO;
use Throwable;

final class CaseDocumentsController
{
    public function __construct(
        private PDO $db,
        private ?IdempotencyGuard $idempotency = null,
        private ?AuditEmitter $audit = null
    ) {}

    /* ============================================================
    * GET /cases/{id}/documents
    * Unified list of case_documents with current version facts.
    * ============================================================ */
    public function list(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $caseId = $req['params']['id'] ?? $req['params']['case_id'] ?? null;
        if (!is_string($caseId) || $caseId === '') {
            return $this->badRequest();
        }

        try {
            $st = $this->db->prepare(
                "SELECT
                    cd.id,
                    cd.case_id,
                    cd.document_type_key,
                    cd.title,
                    cd.status,
                    cd.is_evidence,
                    cd.created_at,
                    cd.finalized_at,
                    cd.current_version_id,
                    (cd.metadata->>'document_id') AS document_id,

                    dv.version_number,
                    dv.storage_uri,
                    dv.content_hash,
                    dv.source,
                    dv.page_count,
                    dv.created_at AS version_created_at,
                    dv.finalized_at AS version_finalized_at
                FROM case_documents cd
                LEFT JOIN document_versions dv
                ON dv.id = cd.current_version_id
                WHERE cd.tenant_id = :tenant
                AND cd.case_id   = :case_id
                ORDER BY cd.created_at DESC"
            );

            $st->execute([
                ':tenant'  => $ctx->tenantId,
                ':case_id' => $caseId,
            ]);

            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $items = [];
            foreach ($rows as $r) {
                $items[] = [
                    'case_document_id'   => $r['id'],
                    'case_id'            => $r['case_id'],
                    'document_type_key'  => $r['document_type_key'],
                    'title'              => $r['title'],
                    'status'             => $r['status'],
                    'is_evidence'        => (bool)$r['is_evidence'],
                    'created_at'         => $r['created_at'],
                    'finalized_at'       => $r['finalized_at'],

                    // Link to the underlying "documents" row (stored in metadata)
                    'document_id'        => $r['document_id'] ?: null,

                    // Current version facts (nullable)
                    'current_version' => $r['current_version_id'] ? [
                        'version_id'      => $r['current_version_id'],
                        'version_number'  => $r['version_number'] === null ? null : (int)$r['version_number'],
                        'storage_uri'     => $r['storage_uri'],
                        'content_hash'    => $r['content_hash'],
                        'source'          => $r['source'],
                        'page_count'      => $r['page_count'] === null ? null : (int)$r['page_count'],
                        'created_at'      => $r['version_created_at'],
                        'finalized_at'    => $r['version_finalized_at'],
                    ] : null,
                ];
            }

            return $this->ok([
                'case_id' => $caseId,
                'items'   => $items,
            ]);

        } catch (Throwable $e) {
            error_log('[CASE_DOCUMENT LIST ERROR] ' . $e->getMessage());
            error_log($e->getTraceAsString());
            return $this->internalError($e);
        }
    }

    /* ============================================================
    * POST /cases/{id}/documents
    * Creates a case_document + a backing documents row.
    * Stores documents.id in case_documents.metadata->document_id
    * ============================================================ */
public function createForCase(array $req, ?AuthContext $ctx): array
{
    if ($ctx === null) {
        return $this->unauthorized();
    }

    $caseId = $req['params']['id'] ?? $req['params']['case_id'] ?? null;
    $body   = $req['body'] ?? null;

    if (!is_string($caseId) || $caseId === '' || !is_array($body)) {
        return $this->badRequest();
    }

    $documentTypeKey = $body['document_type_key'] ?? null;
    $title           = $body['title'] ?? null;
    $isEvidence      = array_key_exists('is_evidence', $body)
        ? (bool)$body['is_evidence']
        : false;

    if (!is_string($documentTypeKey) || $documentTypeKey === '') {
        return $this->validationError('document_type_key required');
    }

    if ($title !== null && !is_string($title)) {
        return $this->validationError('title must be string');
    }

    try {
        $this->db->beginTransaction();

        /* ============================================================
         * 🔒 Phase 17 Freeze Guard
         * ============================================================ */
        $guard = new \App\Services\Guard\CaseMutationGuard($this->db);

        try {
            $guard->assertCaseMutable((string)$ctx->tenantId, $caseId);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'CASE_FROZEN_PREPARED_FOR_FILING') {
                $this->db->rollBack();
                return $this->conflict('CASE_FROZEN_PREPARED_FOR_FILING');
            }
            throw $e;
        }

        /* ------------------------------------------------------------
         * 1) Create backing row in documents
         * ------------------------------------------------------------ */
        $insDoc = $this->db->prepare(
            "INSERT INTO documents
                (tenant_id, case_id, document_type, status, created_by)
             VALUES
                (:tenant, :case_id, :document_type, 'draft', :created_by)
             RETURNING id"
        );

        $insDoc->execute([
            ':tenant'        => $ctx->tenantId,
            ':case_id'       => $caseId,
            ':document_type' => $documentTypeKey,
            ':created_by'    => $ctx->actorId,
        ]);

        $documentId = (string)$insDoc->fetchColumn();

        /* ------------------------------------------------------------
         * 2) Create case_documents row
         * ------------------------------------------------------------ */
        $meta = json_encode(
            ['document_id' => $documentId],
            JSON_UNESCAPED_SLASHES
        );

        $insCd = $this->db->prepare(
            "INSERT INTO case_documents
                (tenant_id, case_id, document_type_key,
                 title, status, is_evidence,
                 created_by, metadata)
             VALUES
                (:tenant, :case_id, :dtk,
                 :title, 'draft', :is_evidence,
                 :created_by, :metadata::jsonb)
             RETURNING id"
        );

        $insCd->execute([
            ':tenant'      => $ctx->tenantId,
            ':case_id'     => $caseId,
            ':dtk'         => $documentTypeKey,
            ':title'       => $title,
            ':is_evidence' => $isEvidence ? 't' : 'f',
            ':created_by'  => $ctx->actorId,
            ':metadata'    => $meta,
        ]);

        $caseDocumentId = (string)$insCd->fetchColumn();

        $this->db->commit();

        return $this->created([
            'case_document_id'  => $caseDocumentId,
            'case_id'           => $caseId,
            'document_id'       => $documentId,
            'document_type_key' => $documentTypeKey,
            'title'             => $title,
            'status'            => 'draft',
            'is_evidence'       => $isEvidence,
        ]);

    } catch (Throwable $e) {

        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }

        error_log('[CASE_DOCUMENT CREATE_FOR_CASE ERROR] ' . $e->getMessage());
        error_log($e->getTraceAsString());

        return $this->internalError($e);
    }
}


    /* ============================================================
     * POST /case-documents/{id}/versions
     * ============================================================ */
public function createVersion(array $req, ?AuthContext $ctx): array
{
    if ($ctx === null) {
        return $this->unauthorized();
    }

    return $this->idempotent($req, function () use ($req, $ctx) {

        $caseDocumentId = $req['params']['id'] ?? null;
        $body = $req['body'] ?? null;

        if (!is_string($caseDocumentId) || !is_array($body)) {
            return $this->badRequest();
        }

        $storageUri  = $body['storage_uri'] ?? null;
        $contentHash = $body['content_hash'] ?? null;
        $source      = $body['source'] ?? null;
        $pageCount   = $body['page_count'] ?? null;
        $makeCurrent = array_key_exists('make_current', $body)
            ? (bool)$body['make_current']
            : true;

        if (!is_string($storageUri) || $storageUri === '') {
            return $this->validationError('storage_uri required');
        }
        if (!is_string($contentHash) || $contentHash === '') {
            return $this->validationError('content_hash required');
        }
        if (!is_string($source) || $source === '') {
            return $this->validationError('source required');
        }
        if ($pageCount !== null && !is_int($pageCount)) {
            return $this->validationError('page_count must be integer');
        }

        try {
            $this->db->beginTransaction();

            /* --------------------------------------------------------
             * Lock case_document row
             * -------------------------------------------------------- */
            $cd = $this->lockCaseDocument(
                $caseDocumentId,
                (string)$ctx->tenantId
            );

            /* ============================================================
             * 🔒 Phase 17 Freeze Guard
             * ============================================================ */
            $guard = new \App\Services\Guard\CaseMutationGuard($this->db);

            try {
                $guard->assertCaseMutable(
                    (string)$ctx->tenantId,
                    (string)$cd['case_id']
                );
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'CASE_FROZEN_PREPARED_FOR_FILING') {
                    $this->db->rollBack();
                    return $this->conflict('CASE_FROZEN_PREPARED_FOR_FILING');
                }
                throw $e;
            }

            /* --------------------------------------------------------
             * Only draft documents can be versioned
             * -------------------------------------------------------- */
            if ($cd['status'] !== 'draft') {
                $this->db->rollBack();
                return $this->conflict('DOCUMENT_FINAL');
            }

            $documentId = $this->getBackingDocumentId(
                $caseDocumentId,
                (string)$ctx->tenantId
            );

            if ($documentId === null) {
                throw new \RuntimeException('DOCUMENT_NOT_LINKED');
            }

            /* --------------------------------------------------------
             * Determine next version number
             * -------------------------------------------------------- */
            $vst = $this->db->prepare(
                "SELECT COALESCE(MAX(version_number), 0) + 1
                   FROM document_versions
                  WHERE tenant_id = :tenant
                    AND document_id = :doc"
            );
            $vst->execute([
                ':tenant' => $ctx->tenantId,
                ':doc'    => $documentId,
            ]);

            $nextVersion = (int)$vst->fetchColumn();

            /* --------------------------------------------------------
             * Insert version
             * -------------------------------------------------------- */
            $ins = $this->db->prepare(
                "INSERT INTO document_versions
                    (tenant_id, document_id, version_number,
                     is_final, content_hash, storage_uri,
                     page_count, source)
                 VALUES
                    (:tenant, :doc, :ver,
                     false, :hash, :uri,
                     :page_count, :source)
                 RETURNING id"
            );

            $ins->execute([
                ':tenant'     => $ctx->tenantId,
                ':doc'        => $documentId,
                ':ver'        => $nextVersion,
                ':hash'       => $contentHash,
                ':uri'        => $storageUri,
                ':page_count' => $pageCount,
                ':source'     => $source,
            ]);

            $versionId = (string)$ins->fetchColumn();

            /* --------------------------------------------------------
             * Optionally make current
             * -------------------------------------------------------- */
            if ($makeCurrent) {
                $this->setCurrentVersionInternal(
                    $caseDocumentId,
                    $versionId,
                    $ctx
                );
            }

            $this->db->commit();

            return $this->created([
                'version_id'     => $versionId,
                'version_number' => $nextVersion,
                'current'        => $makeCurrent,
            ]);

        } catch (Throwable $e) {

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[CASE_DOCUMENT CREATE_VERSION ERROR] ' . $e->getMessage());
            error_log($e->getTraceAsString());

            return $this->internalError($e);
        }
    });
}

    /* ============================================================
     * POST /case-documents/{id}/finalize
     * ============================================================ */
    public function finalize(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        return $this->idempotent($req, function () use ($req, $ctx) {

            $caseDocumentId = $req['params']['id'] ?? null;
            if (!is_string($caseDocumentId) || $caseDocumentId === '') {
                return $this->badRequest();
            }

            try {
                $this->db->beginTransaction();

                /* --------------------------------------------------------
                * Lock case_document row
                * -------------------------------------------------------- */
                $cd = $this->lockCaseDocument(
                    $caseDocumentId,
                    (string)$ctx->tenantId
                );

                /* ============================================================
                * 🔒 Phase 17 Freeze Guard
                * ============================================================ */
                $guard = new \App\Services\Guard\CaseMutationGuard($this->db);

                try {
                    $guard->assertCaseMutable(
                        (string)$ctx->tenantId,
                        (string)$cd['case_id']
                    );
                } catch (\RuntimeException $e) {
                    if ($e->getMessage() === 'CASE_FROZEN_PREPARED_FOR_FILING') {
                        $this->db->rollBack();
                        return $this->conflict('CASE_FROZEN_PREPARED_FOR_FILING');
                    }
                    throw $e;
                }

                /* --------------------------------------------------------
                * Idempotent: already final
                * -------------------------------------------------------- */
                if ($cd['status'] === 'final' && !empty($cd['final_version_id'])) {
                    $payload = $this->loadFinalizeState($caseDocumentId);
                    $this->db->commit();
                    return $this->ok($payload);
                }

                /* --------------------------------------------------------
                * Must have current version
                * -------------------------------------------------------- */
                if ($cd['current_version_id'] === null) {
                    $this->db->rollBack();
                    return $this->conflict('NO_CURRENT_VERSION');
                }

                /* --------------------------------------------------------
                * Validate storage binding before finalization
                * -------------------------------------------------------- */
                $st = $this->db->prepare(
                    "SELECT storage_uri, content_hash
                    FROM document_versions
                    WHERE id = :vid
                    LIMIT 1"
                );
                $st->execute([':vid' => $cd['current_version_id']]);
                $v = $st->fetch(PDO::FETCH_ASSOC);

                if (!$v || empty($v['storage_uri']) || empty($v['content_hash'])) {
                    $this->db->rollBack();
                    return $this->conflict('STORAGE_NOT_BOUND');
                }

                /* --------------------------------------------------------
                * Finalize version
                * -------------------------------------------------------- */
                $this->db->prepare(
                    "UPDATE document_versions
                        SET is_final = true,
                            finalized_at = now()
                    WHERE id = :vid"
                )->execute([':vid' => $cd['current_version_id']]);

                /* --------------------------------------------------------
                * Load version_number for final version
                * -------------------------------------------------------- */
                $vnStmt = $this->db->prepare(
                    "SELECT version_number
                    FROM document_versions
                    WHERE id = :vid
                    LIMIT 1"
                );
                $vnStmt->execute([':vid' => $cd['current_version_id']]);
                $versionRow = $vnStmt->fetch(PDO::FETCH_ASSOC);

                if (!$versionRow) {
                    $this->db->rollBack();
                    return $this->conflict('VERSION_NOT_FOUND');
                }

                /* --------------------------------------------------------
                * Finalize case_document (proper immutable binding)
                * -------------------------------------------------------- */
                $this->db->prepare(
                    "UPDATE case_documents
                        SET status = 'final',
                            final_version_id = :vid,
                            final_version_number = :vnum,
                            finalized_at = now()
                    WHERE id = :id
                    AND tenant_id = :tenant"
                )->execute([
                    ':vid'    => $cd['current_version_id'],
                    ':vnum'   => (int)$versionRow['version_number'],
                    ':id'     => $caseDocumentId,
                    ':tenant' => $ctx->tenantId,
                ]);

                $payload = $this->loadFinalizeState($caseDocumentId);

                $this->db->commit();

                return $this->ok($payload);

            } catch (Throwable $e) {

                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                error_log('[CASE_DOCUMENT FINALIZE ERROR] ' . $e->getMessage());
                error_log($e->getTraceAsString());

                return $this->internalError($e);
            }
        });
    }

    /* ============================================================
     * Phase 9 – POST /case-documents/{id}/storage-bind
     *
     * Semantics (deterministic, no mutation of finalized docs):
     * - Binds storage to the CURRENT version only
     * - If already bound to same values → 200 idempotent
     * - If already bound to different values → 409 conflict
     * - If document is final → 409 DOCUMENT_FINAL
     * ============================================================ */
public function bindStorage(array $req, ?AuthContext $ctx): array
{
    if ($ctx === null) return $this->unauthorized();

    return $this->idempotent($req, function () use ($req, $ctx) {

        $caseDocumentId = $req['params']['id'] ?? null;
        $body = $req['body'] ?? null;

        if (!is_string($caseDocumentId) || !is_array($body)) {
            return $this->badRequest();
        }

        $storageUri  = $body['storage_uri'] ?? null;
        $contentHash = $body['content_hash'] ?? null;
        $source      = $body['source'] ?? null;
        $pageCount   = $body['page_count'] ?? null;

        if (!is_string($storageUri) || $storageUri === '') {
            return $this->validationError('storage_uri required');
        }
        if (!is_string($contentHash) || $contentHash === '') {
            return $this->validationError('content_hash required');
        }
        if (!is_string($source) || $source === '') {
            return $this->validationError('source required');
        }
        if ($pageCount !== null && !is_int($pageCount)) {
            return $this->validationError('page_count must be integer');
        }

        try {
            $this->db->beginTransaction();

            /* --------------------------------------------------------
             * Lock case_document row
             * -------------------------------------------------------- */
            $cd = $this->lockCaseDocument(
                $caseDocumentId,
                (string)$ctx->tenantId
            );

            /* ============================================================
             * 🔒 Phase 17 Freeze Guard
             * ============================================================ */
            $guard = new \App\Services\Guard\CaseMutationGuard($this->db);

            try {
                $guard->assertCaseMutable(
                    (string)$ctx->tenantId,
                    (string)$cd['case_id']
                );
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'CASE_FROZEN_PREPARED_FOR_FILING') {
                    $this->db->rollBack();
                    return $this->conflict('CASE_FROZEN_PREPARED_FOR_FILING');
                }
                throw $e;
            }

            /* --------------------------------------------------------
             * Draft-only mutation
             * -------------------------------------------------------- */
            if ($cd['status'] !== 'draft') {
                $this->db->rollBack();
                return $this->conflict('DOCUMENT_FINAL');
            }

            if ($cd['current_version_id'] === null) {
                $this->db->rollBack();
                return $this->conflict('NO_CURRENT_VERSION');
            }

            /* --------------------------------------------------------
             * Lock current version
             * -------------------------------------------------------- */
            $st = $this->db->prepare(
                "SELECT id, storage_uri, content_hash, source, page_count
                   FROM document_versions
                  WHERE id = :vid
                  FOR UPDATE"
            );
            $st->execute([':vid' => $cd['current_version_id']]);
            $v = $st->fetch(PDO::FETCH_ASSOC);

            if (!$v) {
                throw new \RuntimeException('VERSION_NOT_FOUND');
            }

            $alreadyBound =
                (is_string($v['storage_uri']) && $v['storage_uri'] !== '') &&
                (is_string($v['content_hash']) && $v['content_hash'] !== '');

            /* --------------------------------------------------------
             * Idempotent or conflict behavior
             * -------------------------------------------------------- */
            if ($alreadyBound) {

                if (
                    $v['storage_uri'] === $storageUri &&
                    $v['content_hash'] === $contentHash
                ) {
                    $this->db->commit();

                    return $this->ok([
                        'bound'         => true,
                        'version_id'    => $v['id'],
                        'storage_uri'   => $v['storage_uri'],
                        'content_hash'  => $v['content_hash'],
                        'source'        => $v['source'],
                        'page_count'    => $v['page_count'],
                    ]);
                }

                $this->db->rollBack();
                return $this->conflict('STORAGE_ALREADY_BOUND');
            }

            /* --------------------------------------------------------
             * Perform deterministic bind
             * -------------------------------------------------------- */
            $up = $this->db->prepare(
                "UPDATE document_versions
                    SET storage_uri  = :uri,
                        content_hash = :hash,
                        source       = :source,
                        page_count   = :page_count
                  WHERE id = :vid"
            );

            $up->execute([
                ':uri'        => $storageUri,
                ':hash'       => $contentHash,
                ':source'     => $source,
                ':page_count' => $pageCount,
                ':vid'        => $v['id'],
            ]);

            $this->db->commit();

            return $this->ok([
                'bound'        => true,
                'version_id'   => $v['id'],
                'storage_uri'  => $storageUri,
                'content_hash' => $contentHash,
                'source'       => $source,
                'page_count'   => $pageCount,
            ]);

        } catch (Throwable $e) {

            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('[CASE_DOCUMENT STORAGE_BIND ERROR] ' . $e->getMessage());
            error_log($e->getTraceAsString());

            return $this->internalError($e);
        }
    });
}

    /* ============================================================
     * Phase 9 – GET /case-documents/{id}/storage
     * Returns storage facts from CURRENT version.
     * ============================================================ */
    public function getStorage(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $caseDocumentId = $req['params']['id'] ?? null;
        if (!is_string($caseDocumentId) || $caseDocumentId === '') {
            return $this->badRequest();
        }

        try {
            $st = $this->db->prepare(
                "SELECT
                    cd.id AS case_document_id,
                    cd.status,
                    cd.current_version_id,
                    dv.version_number,
                    dv.is_final,
                    dv.finalized_at,
                    dv.storage_uri,
                    dv.content_hash,
                    dv.source,
                    dv.page_count
                 FROM case_documents cd
                 JOIN document_versions dv
                   ON dv.id = cd.current_version_id
                WHERE cd.id = :id
                  AND cd.tenant_id = :tenant
                LIMIT 1"
            );
            $st->execute([
                ':id' => $caseDocumentId,
                ':tenant' => $ctx->tenantId,
            ]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return $this->notFound();
            }

            return $this->ok([
                'case_document_id' => $row['case_document_id'],
                'status' => $row['status'],
                'version_id' => $row['current_version_id'],
                'version_number' => (int)$row['version_number'],
                'is_final' => (bool)$row['is_final'],
                'finalized_at' => $row['finalized_at'],
                'storage_uri' => $row['storage_uri'],
                'content_hash' => $row['content_hash'],
                'source' => $row['source'],
                'page_count' => $row['page_count'] === null ? null : (int)$row['page_count'],
            ]);

        } catch (Throwable $e) {
            error_log('[CASE_DOCUMENT GET_STORAGE ERROR] ' . $e->getMessage());
            error_log($e->getTraceAsString());
            return $this->internalError($e);
        }
    }

    /* ============================================================
     * Phase 9 – POST /case-documents/{id}/verify-integrity
     *
     * Deterministic integrity report:
     * - Confirms storage facts are present on current version
     * - Optionally compares to client-provided expected hash/uri
     * - If finalized, confirms version is_final=true (DB trigger enforces immutability thereafter)
     * ============================================================ */
    public function verifyIntegrity(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $caseDocumentId = $req['params']['id'] ?? null;
        if (!is_string($caseDocumentId) || $caseDocumentId === '') {
            return $this->badRequest();
        }

        $body = $req['body'] ?? null;
        if ($body !== null && !is_array($body)) {
            return $this->badRequest();
        }

        $expectedHash = is_array($body) ? ($body['expected_content_hash'] ?? null) : null;
        $expectedUri  = is_array($body) ? ($body['expected_storage_uri'] ?? null) : null;

        try {
            $st = $this->db->prepare(
                "SELECT
                    cd.id AS case_document_id,
                    cd.status,
                    cd.finalized_at AS case_finalized_at,
                    cd.current_version_id,
                    dv.version_number,
                    dv.is_final,
                    dv.finalized_at,
                    dv.storage_uri,
                    dv.content_hash
                 FROM case_documents cd
                 JOIN document_versions dv
                   ON dv.id = cd.current_version_id
                WHERE cd.id = :id
                  AND cd.tenant_id = :tenant
                LIMIT 1"
            );
            $st->execute([
                ':id' => $caseDocumentId,
                ':tenant' => $ctx->tenantId,
            ]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if (!$row) return $this->notFound();

            $hasStorageFacts =
                is_string($row['storage_uri']) && $row['storage_uri'] !== '' &&
                is_string($row['content_hash']) && $row['content_hash'] !== '';

            $hashMatches = null;
            if (is_string($expectedHash) && $expectedHash !== '') {
                $hashMatches = hash_equals($row['content_hash'], $expectedHash);
            }

            $uriMatches = null;
            if (is_string($expectedUri) && $expectedUri !== '') {
                $uriMatches = ($row['storage_uri'] === $expectedUri);
            }

            return $this->ok([
                'case_document_id' => $row['case_document_id'],
                'status' => $row['status'],
                'finalized_at' => $row['case_finalized_at'],
                'version_id' => $row['current_version_id'],
                'version_number' => (int)$row['version_number'],
                'version_is_final' => (bool)$row['is_final'],
                'version_finalized_at' => $row['finalized_at'],
                'has_storage_facts' => $hasStorageFacts,
                'content_hash' => $row['content_hash'],
                'storage_uri' => $row['storage_uri'],
                'expected_content_hash_match' => $hashMatches,
                'expected_storage_uri_match' => $uriMatches,
            ]);

        } catch (Throwable $e) {
            error_log('[CASE_DOCUMENT VERIFY_INTEGRITY ERROR] ' . $e->getMessage());
            error_log($e->getTraceAsString());
            return $this->internalError($e);
        }
    }


    /* ============================================================
     * Helpers / responses
     * ============================================================ */

    private function lockCaseDocument(string $id, string $tenant): array
    {
        $st = $this->db->prepare(
            "SELECT id, tenant_id, case_id, status, current_version_id
               FROM case_documents
              WHERE id = :id
                AND tenant_id = :tenant
              FOR UPDATE"
        );
        $st->execute([':id' => $id, ':tenant' => $tenant]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('CASE_DOCUMENT_NOT_FOUND');
        }
        return $row;
    }

    private function setCurrentVersionInternal(
        string $caseDocumentId,
        string $versionId,
        AuthContext $ctx
    ): void {
        $this->db->prepare(
            "UPDATE case_documents
                SET current_version_id = :ver
              WHERE id = :id
                AND tenant_id = :tenant"
        )->execute([
            ':ver'    => $versionId,
            ':id'     => $caseDocumentId,
            ':tenant' => $ctx->tenantId,
        ]);
    }

    private function loadFinalizeState(string $caseDocumentId): array
    {
        $st = $this->db->prepare(
            "SELECT
                cd.id AS case_document_id,
                cd.status,
                cd.current_version_id AS final_version_id,
                dv.version_number AS final_version_number,
                (cd.status = 'final') AS finalized
             FROM case_documents cd
             LEFT JOIN document_versions dv
               ON dv.id = cd.current_version_id
            WHERE cd.id = :id"
        );
        $st->execute([':id' => $caseDocumentId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $row['finalized'] = (bool)$row['finalized'];
        return $row;
    }

    private function getBackingDocumentId(string $caseDocumentId, string $tenantId): ?string
    {
        $st = $this->db->prepare(
            "SELECT (metadata->>'document_id') AS document_id
            FROM case_documents
            WHERE id = :id
                AND tenant_id = :tenant
            LIMIT 1"
        );
        $st->execute([
            ':id'     => $caseDocumentId,
            ':tenant' => $tenantId,
        ]);

        $docId = $st->fetchColumn();
        if (!is_string($docId) || $docId === '') {
            return null;
        }
        return $docId;
    }


    private function idempotent(array $req, callable $fn): array {
        return $this->idempotency ? $this->idempotency->run($req, $fn) : $fn();
    }

    private function ok(array $b): array {
        return ['status'=>200,'headers'=>['Content-Type'=>'application/json'],'body'=>$b];
    }
    private function created(array $b): array {
        return ['status'=>201,'headers'=>['Content-Type'=>'application/json'],'body'=>$b];
    }
    private function badRequest(): array {
        return ['status'=>400,'headers'=>['Content-Type'=>'application/json'],'body'=>['error'=>'INVALID_REQUEST']];
    }
    private function unauthorized(): array {
        return ['status'=>401,'headers'=>['Content-Type'=>'application/json'],'body'=>['error'=>'AUTH_REQUIRED']];
    }
    private function conflict(string $m): array {
        return ['status'=>409,'headers'=>['Content-Type'=>'application/json'],'body'=>['error'=>$m]];
    }
    private function validationError(string $m): array {
        return ['status'=>422,'headers'=>['Content-Type'=>'application/json'],'body'=>['error'=>$m]];
    }
    private function notFound(): array {
        return ['status'=>404,'headers'=>['Content-Type'=>'application/json'],'body'=>['error'=>'NOT_FOUND']];
    }
    private function internalError(Throwable $e): array {
        return ['status'=>500,'headers'=>['Content-Type'=>'application/json'],'body'=>['error'=>'INTERNAL']];
    }
}
