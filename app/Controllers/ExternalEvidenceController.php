<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Auth\AuditEmitter;
use PDO;
use Throwable;

final class ExternalEvidenceController
{
    private ?AuditEmitter $audit;

    public function __construct(
        private PDO $db,
        ?AuditEmitter $audit = null
    ) {
        $this->audit = $audit;
    }

    /* ============================================================
     * GET /external/exports/{export_id}/manifest
     * ============================================================ */
    public function manifest(array $req, ?AuthContext $ctx): array
    {
        return $this->handleExternalAccess(
            $req,
            $ctx,
            'GET /external/exports/{export_id}/manifest',
            fn(array $export, array $access) => [
                'export' => [
                    'export_id'            => $export['id'],
                    'case_document_id'     => $export['case_document_id'],
                    'issued_at'            => $export['issued_at'],
                    'purpose'              => $export['purpose'],
                    'format'               => $export['format'],
                    'delivery'             => $export['delivery'],
                    'final_version_id'     => $export['final_version_id'],
                    'final_version_number' => (int)$export['final_version_number'],
                    'content_hash'         => $export['content_hash'],
                    'hash_algorithm'       => 'sha256',
                    'storage_uri'          => $export['storage_uri'],
                ],
                'access' => $access,
                'verification' => [
                    'fact'  => 'content_hash + storage_uri are authoritative facts as recorded at export issuance',
                    'claim' => 'issuer asserts the export binds to the final document version indicated',
                    'proof' => 'external verifier may independently fetch storage_uri and hash bytes to compare',
                ],
            ]
        );
    }

    /* ============================================================
     * GET /external/exports/{export_id}/verify
     * ============================================================ */
    public function verify(array $req, ?AuthContext $ctx): array
    {
        return $this->handleExternalAccess(
            $req,
            $ctx,
            'GET /external/exports/{export_id}/verify',
            fn(array $export, array $access) => [
                'export_id'      => $export['id'],
                'status'         => $access['status'],
                'expires_at'     => $access['expires_at'],
                'revoked_at'     => $access['revoked_at'],
                'hash_algorithm' => 'sha256',
                'content_hash'   => $export['content_hash'],
                'storage_uri'    => $export['storage_uri'],
            ]
        );
    }

    /* ============================================================
     * Core handler
     * ============================================================ */
private function handleExternalAccess(
    array $req,
    ?AuthContext $ctx,
    string $endpoint,
    callable $responseBuilder
): array {

    error_log('EXTERNAL_EVIDENCE_CONTROLLER: handleExternalAccess ENTERED');

    if ($ctx === null) {
        error_log('EXTERNAL_EVIDENCE_CONTROLLER: ctx is NULL');
        return $this->notFound();
    }

    $exportId = $req['params']['export_id'] ?? null;
    if (!is_string($exportId) || !$this->isUuid($exportId)) {
        error_log('EXTERNAL_EVIDENCE_CONTROLLER: invalid export_id');
        return $this->notFound();
    }

    try {
        error_log('EXTERNAL_EVIDENCE_CONTROLLER: loading export ' . $exportId);

        $export = $this->loadExport($exportId);
        if ($export === null) {
            error_log('EXTERNAL_EVIDENCE_CONTROLLER: export not found');
            return $this->notFound();
        }

        $access = $this->deriveAccessState($ctx->claims ?? []);
        if ($access['status'] !== 'active') {
            error_log('EXTERNAL_EVIDENCE_CONTROLLER: access not active');
            return $this->notFound();
        }

        error_log('EXTERNAL_EVIDENCE_CONTROLLER: SUCCESS PATH');

        return $this->json(200, $responseBuilder($export, $access));

    } catch (Throwable $e) {
        error_log('EXTERNAL_EVIDENCE_CONTROLLER: EXCEPTION ' . $e->getMessage());
        return $this->notFound();
    }
}


    /* ============================================================
     * Access derivation
     * ============================================================ */
    private function deriveAccessState(array $claims): array
    {
        $now = time();

        $expiresAt = isset($claims['evidence_expires_at'])
            ? strtotime((string)$claims['evidence_expires_at'])
            : null;

        $revokedAt = isset($claims['evidence_revoked_at'])
            ? strtotime((string)$claims['evidence_revoked_at'])
            : null;

        $status = 'active';

        if ($revokedAt !== null) {
            $status = 'revoked';
        } elseif ($expiresAt !== null && $expiresAt <= $now) {
            $status = 'expired';
        }

        return [
            'grant_id'   => $claims['evidence_grant_id'] ?? null,
            'subject'    => $claims['evidence_subject'] ?? null,
            'purpose'    => $claims['evidence_purpose'] ?? null,
            'issued_at'  => $claims['evidence_issued_at'] ?? null,
            'expires_at' => $claims['evidence_expires_at'] ?? null,
            'revoked_at' => $claims['evidence_revoked_at'] ?? null,
            'status'     => $status,
        ];
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function loadExport(string $exportId): ?array
    {
        $st = $this->db->prepare(
            "SELECT id,
                    case_document_id,
                    issued_at,
                    purpose,
                    format,
                    delivery,
                    final_version_id,
                    final_version_number,
                    content_hash,
                    storage_uri
               FROM document_exports
              WHERE id = :id"
        );
        $st->execute([':id' => $exportId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function isUuid(string $v): bool
    {
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $v
        );
    }

    private function json(int $status, array $body): array
    {
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
        ];
    }

    private function notFound(): array
    {
        return $this->json(404, ['error' => 'NOT_FOUND']);
    }
}
