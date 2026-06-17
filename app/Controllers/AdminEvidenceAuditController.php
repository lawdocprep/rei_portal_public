<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use PDO;

final class AdminEvidenceAuditController
{
    public function __construct(private PDO $db) {}

    /* ============================================================
     * GET /admin/evidence-access-audit
     * ============================================================ */
    public function list(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $tenantId = (string)$ctx->tenantId;
        $q = is_array($req['query'] ?? null) ? $req['query'] : [];

        $limit  = $this->intParam($q['limit'] ?? null, 200);
        $offset = $this->intParam($q['offset'] ?? null, 0);

        if ($limit < 1) $limit = 1;
        if ($limit > 1000) $limit = 1000;
        if ($offset < 0) $offset = 0;

        $order = (string)($q['order'] ?? 'desc');
        $orderBy = ($order === 'asc') ? 'ae.event_at ASC' : 'ae.event_at DESC';

        $where = ['ae.tenant_id = :tenant'];
        $params = [':tenant' => $tenantId];

        /* ============================================================
         * Entity type
         * ============================================================ */
        $entityType = $q['entity_type'] ?? null;
        if (is_string($entityType) && $entityType !== '') {
            $where[] = 'ae.entity_type = :entity_type';
            $params[':entity_type'] = $entityType;
        } else {
            $where[] = "ae.entity_type IN ('document_export', 'evidence_access_grant')";
        }

        if (!empty($q['export_id'])) {
            $where[] = 'ae.entity_id = :export_id';
            $params[':export_id'] = (string)$q['export_id'];
        }

        if (!empty($q['grant_id'])) {
            $where[] = 'ae.entity_id = :grant_id';
            $params[':grant_id'] = (string)$q['grant_id'];
        }

        /* ============================================================
         * Operation filter (substring OR semantics)
         * ============================================================ */
        $ops = [];

        if (isset($q['operation'])) {
            if (is_string($q['operation'])) {
                $ops = array_map('trim', explode(',', $q['operation']));
            } elseif (is_array($q['operation'])) {
                $ops = array_map('trim', $q['operation']);
            }
        }

        $ops = array_values(array_filter($ops, fn($v) => $v !== ''));

        if ($ops) {
            $likes = [];
            foreach ($ops as $i => $op) {
                $p = ':op' . $i;
                $likes[] = "ae.operation LIKE $p";
                $params[$p] = '%' . $op . '%';
            }
            $where[] = '(' . implode(' OR ', $likes) . ')';
        }

        /* ============================================================
         * Date range
         * ============================================================ */
        if (!empty($q['from'])) {
            $where[] = 'ae.event_at >= :from';
            $params[':from'] = (string)$q['from'];
        }

        if (!empty($q['to'])) {
            $where[] = 'ae.event_at <= :to';
            $params[':to'] = (string)$q['to'];
        }

        $sql = "
            SELECT
                ae.event_at,
                ae.entity_type,
                ae.entity_id,
                ae.operation,
                ae.summary,
                ae.diff_payload
            FROM audit_events ae
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
        ";

        $st = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);

        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        /* ============================================================
         * Phase 15 – Field-level redaction
         * ============================================================ */
        $roles = $ctx->roles ?? [];
        $canSeeDiff =
            in_array('system_admin', $roles, true) ||
            in_array('internal_staff', $roles, true);

        if (!$canSeeDiff) {
            foreach ($rows as &$r) {
                $r['diff_payload'] = null;
            }
            unset($r);
        }

        $format = (string)($q['format'] ?? 'json');
        if ($format === 'csv') {
            return $this->csv(
                200,
                $this->eventsToCsv($rows),
                'evidence_access_audit.csv'
            );
        }

        return $this->json(200, [
            'events' => $rows,
            'page' => [
                'limit'  => $limit,
                'offset'=> $offset,
                'order' => $order,
            ],
        ]);
    }

    /* ============================================================
     * GET /admin/evidence-access-audit/{event_id}
     * Phase 16.1 – Non-enumerable deep link
     * ============================================================ */
    public function get(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) {
            return $this->unauthorized();
        }

        $eventId = (string)($req['params']['event_id'] ?? '');
        if ($eventId === '') {
            return $this->json(404, ['error' => 'NOT_FOUND']);
        }

        $sql = "
            SELECT
                ae.event_at,
                ae.entity_type,
                ae.entity_id,
                ae.operation,
                ae.summary,
                ae.diff_payload
            FROM audit_events ae
            WHERE ae.id = :id
              AND ae.tenant_id = :tenant
            LIMIT 1
        ";

        $st = $this->db->prepare($sql);
        $st->execute([
            ':id'     => $eventId,
            ':tenant'=> (string)$ctx->tenantId,
        ]);

        $row = $st->fetch(PDO::FETCH_ASSOC);

        // Phase 16.1 rule:
        // unknown OR cross-tenant → 404 (never 403)
        if (!$row) {
            return $this->json(404, ['error' => 'NOT_FOUND']);
        }

        /* ============================================================
         * Field-level redaction (same rules as list)
         * ============================================================ */
        $roles = $ctx->roles ?? [];
        $canSeeDiff =
            in_array('system_admin', $roles, true) ||
            in_array('internal_staff', $roles, true);

        if (!$canSeeDiff) {
            $row['diff_payload'] = null;
        }

        return $this->json(200, ['event' => $row]);
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function intParam(mixed $v, int $default): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && preg_match('/^-?\d+$/', $v)) return (int)$v;
        return $default;
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
                'Content-Type'        => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ],
            'body' => $csv,
        ];
    }

    private function unauthorized(): array
    {
        return $this->json(401, ['error' => 'AUTH_REQUIRED']);
    }

    private function eventsToCsv(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');

        if (!$rows) {
            fputcsv($fh, ['event_at','entity_type','entity_id','operation','summary','diff_payload']);
        } else {
            fputcsv($fh, array_keys($rows[0]));
            foreach ($rows as $r) {
                fputcsv($fh, $r);
            }
        }

        rewind($fh);
        return stream_get_contents($fh);
    }
}
