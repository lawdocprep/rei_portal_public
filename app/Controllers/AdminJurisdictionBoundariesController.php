<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthContext;
use App\Services\JurisdictionBoundaryUpdateService;
use PDO;

final class AdminJurisdictionBoundariesController
{
    public function __construct(
        private PDO $db
    ) {}

    /**
     * POST /admin/jurisdiction-boundaries/update
     * Body:
     * {
     *   "state_code": "AZ",
     *   "unit_type": "county",
     *   "staging_table": "staging_counties",
     *   "name_column": "name",
     *   "geom_column": "wkb_geometry",
     *   "source": "US Census TIGER 2023",
     *   "parent_unit_id": null,
     *   "dry_run": true,
     *   "overlap_epsilon": 1e-9
     * }
     */
    public function apply(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $body = $req['body'] ?? [];
        if (!is_array($body)) return $this->badRequest('INVALID_BODY');

        $svc = new JurisdictionBoundaryUpdateService($this->db);

        $payload = [
            'state_code' => $body['state_code'] ?? null,
            'unit_type' => $body['unit_type'] ?? null,
            'staging_table' => $body['staging_table'] ?? null,
            'name_column' => $body['name_column'] ?? null,
            'geom_column' => $body['geom_column'] ?? null,
            'source' => $body['source'] ?? null,
            'parent_unit_id' => $body['parent_unit_id'] ?? null,
            'dry_run' => $body['dry_run'] ?? true,
            'overlap_epsilon' => $body['overlap_epsilon'] ?? 1e-9,
            'initiated_by' => (string)$ctx->actorId,
        ];

        $run = $svc->applyFromStaging($payload);

        return [
            'ok' => true,
            'run' => $run,
        ];
    }

    /**
     * GET /admin/jurisdiction-boundaries/update-runs
     */
    public function listRuns(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $st = $this->db->prepare("
            SELECT *
            FROM jurisdiction_boundary_update_runs
            ORDER BY started_at DESC
            LIMIT 50
        ");
        $st->execute();

        return [
            'ok' => true,
            'runs' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    /**
     * GET /admin/jurisdiction-boundaries/update-runs/{id}
     */
    public function readRun(array $req, ?AuthContext $ctx): array
    {
        if ($ctx === null) return $this->unauthorized();

        $id = $req['params']['id'] ?? null;
        if (!is_string($id) || $id === '') return $this->badRequest('INVALID_RUN_ID');

        $st = $this->db->prepare("SELECT * FROM jurisdiction_boundary_update_runs WHERE id = :id");
        $st->execute([':id' => $id]);
        $run = $st->fetch(PDO::FETCH_ASSOC);
        if (!$run) return $this->notFound('RUN_NOT_FOUND');

        $chg = $this->db->prepare("
            SELECT c.*, ju.name AS unit_name
            FROM jurisdiction_boundary_update_changes c
            JOIN jurisdiction_units ju ON ju.id = c.jurisdiction_unit_id
            WHERE c.run_id = :id
            ORDER BY c.created_at ASC
        ");
        $chg->execute([':id' => $id]);

        return [
            'ok' => true,
            'run' => $run,
            'changes' => $chg->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    /* ============================================================
     * Common helpers (match existing style)
     * ============================================================ */

    private function unauthorized(): array
    {
        return ['error' => 'UNAUTHORIZED'];
    }

    private function badRequest(string $code): array
    {
        return ['error' => $code];
    }

    private function notFound(string $code): array
    {
        return ['error' => $code];
    }
}
