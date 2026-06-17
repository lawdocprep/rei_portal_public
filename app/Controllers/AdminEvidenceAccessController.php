<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;

final class AdminEvidenceAccessController
{
    public function __construct(private PDO $db) {}

    /* ============================================================
     * GET /admin/evidence-access-grants
     *
     * Query params (req['query']):
     * - export_id
     * - case_document_id
     * - status: active | expired | revoked
     * - issued_from / issued_to (ISO-8601 or any DB-parseable timestamp)
     * - expires_from / expires_to
     * - revoked_from / revoked_to
     * - subject (substring match)
     * - purpose (substring match)
     * - limit (default 100, max 500)
     * - offset (default 0)
     * - order: issued_desc | issued_asc (default issued_desc)
     * - format: json | csv (default json)
     * ============================================================ */
    public function list(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $tenantId = (string)$ctx->tenantId;
        $q = is_array($req['query'] ?? null) ? $req['query'] : [];

        $limit  = $this->intParam($q['limit'] ?? null, 100);
        $offset = $this->intParam($q['offset'] ?? null, 0);

        if ($limit < 1) $limit = 1;
        if ($limit > 500) $limit = 500;
        if ($offset < 0) $offset = 0;

        $order = (string)($q['order'] ?? 'issued_desc');
        $orderBy = match ($order) {
            'issued_asc' => 'g.issued_at ASC',
            default      => 'g.issued_at DESC',
        };

        // Strict tenant isolation:
        // - Always constrain grants by g.tenant_id
        // - Also constrain joined exports by same tenant (defense in depth)
        $where = ['g.tenant_id = :tenant', 'de.tenant_id = :tenant'];
        $params = [':tenant' => $tenantId];

        if (!empty($q['export_id'])) {
            $where[] = 'g.document_export_id = :export';
            $params[':export'] = (string)$q['export_id'];
        }

        if (!empty($q['case_document_id'])) {
            $where[] = 'de.case_document_id = :case_document';
            $params[':case_document'] = (string)$q['case_document_id'];
        }

        if (!empty($q['subject'])) {
            $where[] = 'g.subject ILIKE :subject';
            $params[':subject'] = '%' . (string)$q['subject'] . '%';
        }

        if (!empty($q['purpose'])) {
            $where[] = 'g.purpose ILIKE :purpose';
            $params[':purpose'] = '%' . (string)$q['purpose'] . '%';
        }

        // Status filter semantics:
        // active  => not revoked AND not expired (or no expiry)
        // expired => expiry exists AND <= now (regardless of revoked; caller can also filter revoked range if needed)
        // revoked => revoked_at exists
        if (!empty($q['status'])) {
            $status = (string)$q['status'];
            if ($status === 'active') {
                $where[] = 'g.revoked_at IS NULL AND (g.expires_at IS NULL OR g.expires_at > now())';
            } elseif ($status === 'expired') {
                $where[] = 'g.expires_at IS NOT NULL AND g.expires_at <= now()';
            } elseif ($status === 'revoked') {
                $where[] = 'g.revoked_at IS NOT NULL';
            }
        }

        // Date range filters (fail-soft: ignore if empty)
        $this->addRange($where, $params, 'g.issued_at',  'issued_from',  'issued_to',  $q);
        $this->addRange($where, $params, 'g.expires_at', 'expires_from', 'expires_to', $q);
        $this->addRange($where, $params, 'g.revoked_at', 'revoked_from', 'revoked_to', $q);

        // Ledger select includes case_document_id for governance visibility.
        $sql = "
            SELECT
                g.id,
                g.document_export_id,
                de.case_document_id,
                g.subject,
                g.purpose,
                g.issued_at,
                g.expires_at,
                g.revoked_at,
                g.issued_by_actor_id,
                g.issued_by_actor_type
            FROM evidence_access_grants g
            JOIN document_exports de
              ON de.id = g.document_export_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
        ";

        $st = $this->db->prepare($sql);

        // Bind non-integers
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);

        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $format = (string)($q['format'] ?? 'json');
        if ($format === 'csv') {
            return $this->csv(200, $this->grantsToCsv($rows), 'evidence_access_grants.csv');
        }

        return $this->json(200, [
            'grants' => $rows,
            'page' => [
                'limit' => $limit,
                'offset' => $offset,
                'order' => $order,
            ],
        ]);
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function addRange(array &$where, array &$params, string $col, string $fromKey, string $toKey, array $q): void
    {
        $from = $q[$fromKey] ?? null;
        $to   = $q[$toKey] ?? null;

        if (is_string($from) && $from !== '') {
            $p = ':' . $fromKey;
            $where[] = "{$col} >= {$p}";
            $params[$p] = $from;
        }

        if (is_string($to) && $to !== '') {
            $p = ':' . $toKey;
            $where[] = "{$col} <= {$p}";
            $params[$p] = $to;
        }
    }

    private function intParam(mixed $v, int $default): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && $v !== '' && preg_match('/^-?\d+$/', $v)) return (int)$v;
        return $default;
    }

    private function grantsToCsv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) return '';

        // Deterministic schema / ordering for court-ready export
        fputcsv($out, [
            'grant_id',
            'document_export_id',
            'case_document_id',
            'subject',
            'purpose',
            'issued_at',
            'expires_at',
            'revoked_at',
            'issued_by_actor_id',
            'issued_by_actor_type',
        ]);

        foreach ($rows as $r) {
            fputcsv($out, [
                (string)($r['id'] ?? ''),
                (string)($r['document_export_id'] ?? ''),
                (string)($r['case_document_id'] ?? ''),
                (string)($r['subject'] ?? ''),
                (string)($r['purpose'] ?? ''),
                (string)($r['issued_at'] ?? ''),
                (string)($r['expires_at'] ?? ''),
                (string)($r['revoked_at'] ?? ''),
                (string)($r['issued_by_actor_id'] ?? ''),
                (string)($r['issued_by_actor_type'] ?? ''),
            ]);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return is_string($csv) ? $csv : '';
    }

    private function json(int $status, array $body): array
    {
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
        ];
    }

    private function csv(int $status, string $csv, string $filename): array
    {
        return [
            'status' => $status,
            'headers' => [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ],
            'body' => $csv,
        ];
    }

    private function unauthorized(): array
    {
        return $this->json(401, ['error' => 'AUTH_REQUIRED']);
    }
}
