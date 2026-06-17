<?php
declare(strict_types=1);

namespace App\Services\Procedures;

use PDO;
use RuntimeException;

final class JurisdictionBoundaryUpdateService
{
    public function __construct(
        private PDO $db
    ) {}

    /**
     * Apply a boundary update from a staging table into jurisdiction_unit_boundaries.
     *
     * Required options:
     * - state_code (e.g. 'AZ')
     * - unit_type  (e.g. 'county' or 'justice_precinct')
     * - staging_table (e.g. 'staging_counties')
     * - name_column   (e.g. 'name' or 'precinct')
     * - geom_column   (e.g. 'wkb_geometry')
     * - source        (human readable)
     * - effective_from (YYYY-MM-DD)  [LEGAL HARDENING: replaces now()]
     * - dataset_id (uuid)            [LEGAL HARDENING: provenance]
     * - dataset_hash_sha256 (string) [LEGAL HARDENING: provenance]
     *
     * Optional:
     * - parent_unit_id (uuid) => restrict to children under parent
     * - dry_run (bool)
     * - overlap_epsilon (float) => sliver threshold
     * - initiated_by (uuid)
     * - snap_grid (float) => ST_SnapToGrid precision (default 1e-9)
     *
     * Returns: run row payload
     */
    public function applyFromStaging(array $opts): array
    {
        $stateCode     = strtoupper(trim((string)($opts['state_code'] ?? '')));
        $unitType      = trim((string)($opts['unit_type'] ?? ''));
        $stagingTable  = trim((string)($opts['staging_table'] ?? ''));
        $nameCol       = trim((string)($opts['name_column'] ?? ''));
        $geomCol       = trim((string)($opts['geom_column'] ?? ''));
        $source        = trim((string)($opts['source'] ?? ''));
        $sourceUrl     = trim((string)($opts['source_url'] ?? ''));

        $effectiveFrom = trim((string)($opts['effective_from'] ?? ''));
        $datasetId     = trim((string)($opts['dataset_id'] ?? ''));
        $datasetHash   = trim((string)($opts['dataset_hash_sha256'] ?? ''));

        $dryRun        = (bool)($opts['dry_run'] ?? true);
        $overlapEps    = (float)($opts['overlap_epsilon'] ?? 1e-9);
        $snapGrid      = (float)($opts['snap_grid'] ?? 1e-9);

        $parentUnitId  = $opts['parent_unit_id'] ?? null;
        $initiatedBy   = $opts['initiated_by'] ?? null;

        if (
            $stateCode === '' ||
            $unitType === '' ||
            $stagingTable === '' ||
            $nameCol === '' ||
            $geomCol === '' ||
            $source === '' ||
            $sourceUrl === ''
        ) {
            throw new RuntimeException('INVALID_UPDATE_REQUEST');
        }

        // LEGAL HARDENING: effective date + dataset provenance required.
        if (!$this->isIsoDate($effectiveFrom)) {
            throw new RuntimeException('INVALID_EFFECTIVE_FROM_DATE');
        }
        if (!$this->isUuid($datasetId)) {
            throw new RuntimeException('INVALID_DATASET_ID');
        }
        if ($datasetHash === '') {
            throw new RuntimeException('DATASET_HASH_REQUIRED');
        }

        // Prevent SQL injection via identifiers: only allow simple identifiers.
        $this->assertSafeIdent($stagingTable, 'staging_table');
        $this->assertSafeIdent($nameCol, 'name_column');
        $this->assertSafeIdent($geomCol, 'geom_column');
        $this->assertColumnExists($stagingTable, $nameCol);
        $this->assertColumnExists($stagingTable, $geomCol);

        // Validate optional UUIDs
        if ($parentUnitId !== null && !$this->isUuid((string)$parentUnitId)) {
            throw new RuntimeException('INVALID_PARENT_UNIT_ID');
        }
        if ($initiatedBy !== null && !$this->isUuid((string)$initiatedBy)) {
            throw new RuntimeException('INVALID_INITIATED_BY');
        }

        // snap_grid must be positive and small-ish (avoid nonsense)
        if ($snapGrid <= 0.0 || $snapGrid > 1.0) {
            throw new RuntimeException('INVALID_SNAP_GRID');
        }
        $this->db->beginTransaction();

        try {

            /*
            ------------------------------------------------------------
            Ensure dataset provenance exists
            ------------------------------------------------------------
            Must occur before any boundary insert to satisfy FK:
                jurisdiction_unit_boundaries.dataset_id
                    → jurisdiction_boundary_datasets.id

            ON CONFLICT makes this idempotent and safe for retries.
            ------------------------------------------------------------
            */

            $st = $this->db->prepare("
                INSERT INTO jurisdiction_boundary_datasets (
                    id,
                    state_code,
                    unit_type,
                    source_url,
                    source_label,
                    source_hash_sha256,
                    effective_from
                )
                VALUES (
                    :id,
                    :state_code,
                    :unit_type,
                    :source_url,
                    :source_label,
                    :source_hash,
                    :effective_from::date
                )
                ON CONFLICT (
                    state_code,
                    unit_type,
                    source_hash_sha256
                )
                DO UPDATE
                SET
                    created_at = jurisdiction_boundary_datasets.created_at
                RETURNING id
            ");

            $st->execute([
                ':id' => $datasetId,
                ':state_code' => $stateCode,
                ':unit_type' => $unitType,
                ':source_url' => $sourceUrl,
                ':source_label' => 'Automated Import',
                ':source_hash' => $datasetHash,
                ':effective_from' => $effectiveFrom
            ]);

            $datasetId = (string)$st->fetchColumn();

            /*
            ------------------------------------------------------------
            Create run record (store dataset metadata in stats)
            ------------------------------------------------------------
            */

            $runId = $this->insertRun([
                'state_code' => $stateCode,
                'unit_type' => $unitType,
                'staging_table' => $stagingTable,
                'name_column' => $nameCol,
                'geom_column' => $geomCol,
                'source' => $source,
                'parent_unit_id' => $parentUnitId,
                'dry_run' => $dryRun,
                'overlap_epsilon' => $overlapEps,
                'initiated_by' => $initiatedBy,
            ]);
            // Load all candidate matches from staging -> jurisdiction_units
            $rows = $this->loadStagingMatches($stateCode, $unitType, $stagingTable, $nameCol, $geomCol, $parentUnitId, $snapGrid);

            $stats = [
                'matched_unit_count' => count($rows),
                'inserted' => 0,
                'updated' => 0,
                'noop' => 0,
                'skipped_invalid_geom' => 0,
                'skipped_invalid_effective_date' => 0,
                'dry_run' => $dryRun,
                'overlap_epsilon' => $overlapEps,
                'effective_from' => $effectiveFrom,
                'dataset_id' => $datasetId,
                'dataset_hash_sha256' => $datasetHash,
                'snap_grid' => $snapGrid,
            ];

            foreach ($rows as $r) {
                $unitId = (string)$r['jurisdiction_unit_id'];
                $newHash = (string)$r['new_hash'];
                $geomIsNull = (bool)$r['geom_is_null'];

                if ($geomIsNull || $newHash === '') {
                    $this->insertChange($runId, $unitId, 'skipped_invalid_geom', null, null, null, null, [
                        'reason' => 'NULL_GEOMETRY_OR_HASH',
                        'staging_name' => $r['staging_name'],
                    ]);
                    $stats['skipped_invalid_geom']++;
                    continue;
                }

                $current = $this->loadCurrentBoundary($unitId);
                if ($current === null) {
                    if (!$dryRun) {
                        $newBoundaryId = $this->insertBoundary(
                            unitId: $unitId,
                            stagingTable: $stagingTable,
                            geomCol: $geomCol,
                            fid: (int)$r['staging_fid'],
                            source: $source,
                            datasetId: $datasetId,
                            datasetHash: $datasetHash,
                            effectiveFrom: $effectiveFrom,
                            snapGrid: $snapGrid
                        );
                        $this->insertChange($runId, $unitId, 'inserted', null, $newBoundaryId, null, $newHash, [
                            'staging_name' => $r['staging_name'],
                            'effective_from' => $effectiveFrom,
                            'dataset_id' => $datasetId,
                        ]);
                    } else {
                        $this->insertChange($runId, $unitId, 'inserted', null, null, null, $newHash, [
                            'dry_run' => true,
                            'staging_name' => $r['staging_name'],
                            'effective_from' => $effectiveFrom,
                            'dataset_id' => $datasetId,
                        ]);
                    }
                    $stats['inserted']++;
                    continue;
                }

                $oldBoundaryId = (string)$current['id'];
                $oldHash = (string)$current['boundary_hash'];
                $currentEffectiveFrom = (string)($current['effective_from'] ?? '');

                if ($oldHash === $newHash) {
                    $this->insertChange($runId, $unitId, 'noop', $oldBoundaryId, null, $oldHash, $newHash, [
                        'staging_name' => $r['staging_name'],
                    ]);
                    $stats['noop']++;
                    continue;
                }

                // LEGAL HARDENING: effective_from must advance; never allow retroactive overwrite.
                // If current effective_from is empty (legacy), allow update.
                if ($currentEffectiveFrom !== '' && $this->isIsoDate($currentEffectiveFrom)) {
                    if ($effectiveFrom <= $currentEffectiveFrom) {
                        $this->insertChange($runId, $unitId, 'skipped_invalid_effective_date', $oldBoundaryId, null, $oldHash, $newHash, [
                            'reason' => 'EFFECTIVE_FROM_NOT_AFTER_CURRENT',
                            'current_effective_from' => $currentEffectiveFrom,
                            'requested_effective_from' => $effectiveFrom,
                            'staging_name' => $r['staging_name'],
                        ]);
                        $stats['skipped_invalid_effective_date']++;
                        continue;
                    }
                }

                // Changed boundary
                if (!$dryRun) {
                    $this->closeCurrentBoundary($unitId, $effectiveFrom);
                    $newBoundaryId = $this->insertBoundary(
                        unitId: $unitId,
                        stagingTable: $stagingTable,
                        geomCol: $geomCol,
                        fid: (int)$r['staging_fid'],
                        source: $source,
                        datasetId: $datasetId,
                        datasetHash: $datasetHash,
                        effectiveFrom: $effectiveFrom,
                        snapGrid: $snapGrid
                    );
                    $this->insertChange($runId, $unitId, 'updated', $oldBoundaryId, $newBoundaryId, $oldHash, $newHash, [
                        'staging_name' => $r['staging_name'],
                        'effective_from' => $effectiveFrom,
                        'dataset_id' => $datasetId,
                    ]);
                } else {
                    $this->insertChange($runId, $unitId, 'updated', $oldBoundaryId, null, $oldHash, $newHash, [
                        'dry_run' => true,
                        'staging_name' => $r['staging_name'],
                        'effective_from' => $effectiveFrom,
                        'dataset_id' => $datasetId,
                    ]);
                }
                $stats['updated']++;
            }

            // Post-apply safety: material overlap check (slivers ignored)
            // Only check once changes are applied; dry_run is advisory only.
            if (!$dryRun) {
                $materialOverlaps = $this->countMaterialOverlaps(
                    stateCode: $stateCode,
                    unitType: $unitType,
                    parentUnitId: $parentUnitId,
                    eps: $overlapEps,
                    datasetId: $datasetId
                );
                $stats['material_overlap_count'] = $materialOverlaps;

                if ($materialOverlaps > 0) {
                    $this->markRunFailed($runId, $stats, 'MATERIAL_OVERLAPS_DETECTED');
                    $this->db->rollBack();
                    throw new RuntimeException('BOUNDARY_UPDATE_BLOCKED:MATERIAL_OVERLAPS');
                }
            }

            // Mark run no-op if nothing changed (helps auditing / idempotency)
            if (($stats['inserted'] ?? 0) === 0 && ($stats['updated'] ?? 0) === 0) {
                $this->markRunNoop($runId, $stats);
            } else {
                $this->markRunApplied($runId, $stats);
            }

            $this->db->commit();

            return $this->loadRun($runId);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /* ============================================================
     * Internals
     * ============================================================ */

    private function insertRun(array $p): string
    {
        $st = $this->db->prepare("
            INSERT INTO jurisdiction_boundary_update_runs (
                state_code, unit_type,
                staging_table, name_column, geom_column,
                source,
                parent_unit_id,
                dry_run,
                overlap_epsilon,
                initiated_by,
                status
            )
            VALUES (
                :state_code, :unit_type,
                :staging_table, :name_column, :geom_column,
                :source,
                :parent_unit_id,
                :dry_run,
                :overlap_epsilon,
                :initiated_by,
                'running'
            )
            RETURNING id
        ");

        $st->bindValue(':state_code', $p['state_code']);
        $st->bindValue(':unit_type', $p['unit_type']);
        $st->bindValue(':staging_table', $p['staging_table']);
        $st->bindValue(':name_column', $p['name_column']);
        $st->bindValue(':geom_column', $p['geom_column']);
        $st->bindValue(':source', $p['source']);
        $st->bindValue(':parent_unit_id', $p['parent_unit_id']);
        $st->bindValue(':dry_run', (bool)$p['dry_run'], PDO::PARAM_BOOL);
        $st->bindValue(':overlap_epsilon', (float)$p['overlap_epsilon']);
        $st->bindValue(':initiated_by', $p['initiated_by']);
        $st->execute();

        $id = (string)$st->fetchColumn();
        if ($id === '') throw new RuntimeException('RUN_CREATE_FAILED');
        return $id;
    }

    private function markRunApplied(string $runId, array $stats): void
    {
        $st = $this->db->prepare("
            UPDATE jurisdiction_boundary_update_runs
            SET status='applied',
                finished_at=now(),
                stats = :stats
            WHERE id = :id
        ");
        $st->bindValue(':id', $runId);
        $st->bindValue(':stats', json_encode($stats, JSON_UNESCAPED_SLASHES));
        $st->execute();
    }

    private function markRunNoop(string $runId, array $stats): void
    {
        $st = $this->db->prepare("
            UPDATE jurisdiction_boundary_update_runs
            SET status='noop',
                finished_at=now(),
                stats = :stats
            WHERE id = :id
        ");
        $st->bindValue(':id', $runId);
        $st->bindValue(':stats', json_encode($stats, JSON_UNESCAPED_SLASHES));
        $st->execute();
    }

    private function markRunFailed(string $runId, array $stats, string $error): void
    {
        $st = $this->db->prepare("
            UPDATE jurisdiction_boundary_update_runs
            SET status='failed',
                finished_at=now(),
                stats = :stats,
                error = :error
            WHERE id = :id
        ");
        $st->bindValue(':id', $runId);
        $st->bindValue(':stats', json_encode($stats, JSON_UNESCAPED_SLASHES));
        $st->bindValue(':error', $error);
        $st->execute();
    }

    private function loadRun(string $runId): array
    {
        $st = $this->db->prepare("SELECT * FROM jurisdiction_boundary_update_runs WHERE id = :id");
        $st->execute([':id' => $runId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: ['error' => 'RUN_NOT_FOUND'];
    }

    private function insertChange(
        string $runId,
        string $unitId,
        string $action,
        ?string $oldBoundaryId,
        ?string $newBoundaryId,
        ?string $oldHash,
        ?string $newHash,
        array $notes
    ): void {
        $st = $this->db->prepare("
            INSERT INTO jurisdiction_boundary_update_changes (
                run_id, jurisdiction_unit_id,
                action,
                old_boundary_id, new_boundary_id,
                old_hash, new_hash,
                notes
            )
            VALUES (
                :run_id, :unit_id,
                :action,
                :old_bid, :new_bid,
                :old_hash, :new_hash,
                :notes
            )
        ");

        $st->bindValue(':run_id', $runId);
        $st->bindValue(':unit_id', $unitId);
        $st->bindValue(':action', $action);
        $st->bindValue(':old_bid', $oldBoundaryId);
        $st->bindValue(':new_bid', $newBoundaryId);
        $st->bindValue(':old_hash', $oldHash);
        $st->bindValue(':new_hash', $newHash);
        $st->bindValue(':notes', json_encode($notes, JSON_UNESCAPED_SLASHES));
        $st->execute();
    }

    private function loadCurrentBoundary(string $unitId): ?array
    {
        $st = $this->db->prepare("
            SELECT id, boundary_hash, effective_from
            FROM jurisdiction_unit_boundaries
            WHERE jurisdiction_unit_id = :uid
              AND effective_to IS NULL
            LIMIT 1
        ");
        $st->execute([':uid' => $unitId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * LEGAL HARDENING:
     * close current boundary at (effective_from - 1 day) end-of-day semantics by using date.
     * Using now() can create ambiguous overlaps with backdated effective_from.
     */
    private function closeCurrentBoundary(string $unitId, string $newEffectiveFrom): void
    {
        $st = $this->db->prepare("
            UPDATE jurisdiction_unit_boundaries
            SET effective_to = (:new_eff::date - INTERVAL '1 day')::date
            WHERE jurisdiction_unit_id = :uid
              AND effective_to IS NULL
        ");
        $st->bindValue(':uid', $unitId);
        $st->bindValue(':new_eff', $newEffectiveFrom);
        $st->execute();
    }

    /**
     * Inserts a new boundary row by selecting geometry directly from staging
     * (avoids shipping geometry through PHP).
     *
     * LEGAL HARDENING:
     * - deterministic effective_from
     * - dataset provenance (dataset_id + sha256)
     * - snap-to-grid for stable hashing
     */
    private function insertBoundary(
        string $unitId,
        string $stagingTable,
        string $geomCol,
        int $fid,
        string $source,
        string $datasetId,
        string $datasetHash,
        string $effectiveFrom,
        float $snapGrid
    ): string {
        // NOTE: snapGrid is bound as a parameter; geometry expression repeats deterministically.
        $sql = "
            INSERT INTO jurisdiction_unit_boundaries (
                jurisdiction_unit_id,
                boundary_geometry,
                boundary_hash,
                effective_from,
                source,
                dataset_id,
                dataset_hash_sha256
            )
            SELECT
                :unit_id,
                ST_SnapToGrid(ST_Multi(ST_MakeValid(s.$geomCol)), :snap_grid),
                md5(ST_AsEWKB(ST_SnapToGrid(ST_Multi(ST_MakeValid(s.$geomCol)), :snap_grid))::text),
                :effective_from::date,
                :source,
                :dataset_id,
                :dataset_hash
            FROM $stagingTable s
            WHERE s.ogc_fid = :fid
            RETURNING id
        ";

        $st = $this->db->prepare($sql);
        $st->bindValue(':unit_id', $unitId);
        $st->bindValue(':source', $source);
        $st->bindValue(':dataset_id', $datasetId);
        $st->bindValue(':dataset_hash', $datasetHash);
        $st->bindValue(':effective_from', $effectiveFrom);
        $st->bindValue(':snap_grid', $snapGrid);
        $st->bindValue(':fid', $fid, PDO::PARAM_INT);
        $st->execute();

        $id = (string)$st->fetchColumn();
        if ($id === '') throw new RuntimeException('BOUNDARY_INSERT_FAILED');
        return $id;
    }

    /**
     * Loads all staging matches (unit_id + new_hash) with safe identifier use.
     * LEGAL HARDENING: snap-to-grid used for stable hashing.
     */
    private function loadStagingMatches(
        string $stateCode,
        string $unitType,
        string $stagingTable,
        string $nameCol,
        string $geomCol,
        ?string $parentUnitId,
        float $snapGrid
    ): array {
        $parentClause = $parentUnitId ? "AND ju.parent_id = :parent_id" : "";

        $sql = "
            SELECT
                s.ogc_fid AS staging_fid,
                s.$nameCol AS staging_name,
                ju.id AS jurisdiction_unit_id,
                (s.$geomCol IS NULL) AS geom_is_null,
                md5(ST_AsEWKB(ST_SnapToGrid(ST_Multi(ST_MakeValid(s.$geomCol)), :snap_grid))::text) AS new_hash
            FROM $stagingTable s
            JOIN jurisdiction_units ju
              ON ju.state_code = :state_code
             AND ju.unit_type  = :unit_type
             $parentClause
             AND lower(ju.name) = lower(s.$nameCol)
        ";

        $st = $this->db->prepare($sql);
        $st->bindValue(':state_code', $stateCode);
        $st->bindValue(':unit_type', $unitType);
        $st->bindValue(':snap_grid', $snapGrid);
        if ($parentUnitId) {
            $st->bindValue(':parent_id', $parentUnitId);
        }
        $st->execute();

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function countMaterialOverlaps(
        string $stateCode,
        string $unitType,
        ?string $parentUnitId,
        float $eps,
        string $datasetId
    ): int {
        $parentClause = $parentUnitId ? "AND ju_a.parent_id = :pid AND ju_b.parent_id = :pid" : "";

        $sql = "
            SELECT COUNT(*)
            FROM jurisdiction_unit_boundaries a
            JOIN jurisdiction_unit_boundaries b
              ON a.effective_to IS NULL
             AND b.effective_to IS NULL
             AND a.jurisdiction_unit_id < b.jurisdiction_unit_id
             AND a.dataset_id = :ds
             AND b.dataset_id = :ds
            JOIN jurisdiction_units ju_a ON ju_a.id = a.jurisdiction_unit_id
            JOIN jurisdiction_units ju_b ON ju_b.id = b.jurisdiction_unit_id
            WHERE ju_a.state_code = :sc
              AND ju_b.state_code = :sc
              AND ju_a.unit_type  = :ut
              AND ju_b.unit_type  = :ut
              $parentClause
              AND ST_Area(ST_Intersection(a.boundary_geometry, b.boundary_geometry)) > :eps
        ";

        $st = $this->db->prepare($sql);
        $st->bindValue(':sc', $stateCode);
        $st->bindValue(':ut', $unitType);
        $st->bindValue(':ds', $datasetId);
        if ($parentUnitId) $st->bindValue(':pid', $parentUnitId);
        $st->bindValue(':eps', $eps);
        $st->execute();

        return (int)$st->fetchColumn();
    }

    private function assertSafeIdent(string $ident, string $label): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $ident)) {
            throw new RuntimeException('UNSAFE_IDENTIFIER:' . $label);
        }
    }

    private function isUuid(string $s): bool
    {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
    }

    private function isIsoDate(string $s): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $s));
        return checkdate($m, $d, $y);
    }

    private function assertColumnExists(
        string $table,
        string $column
    ): void {
        $st = $this->db->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_name = :table
            AND column_name = :column
            LIMIT 1
        ");

        $st->execute([
            ':table' => $table,
            ':column' => $column
        ]);

        if (!$st->fetch()) {
            throw new RuntimeException(
                'STAGING_COLUMN_NOT_FOUND:' . $column
            );
        }
    }

    private function ensureDatasetExists(
        string $datasetId,
        string $datasetHash,
        string $source,
        string $effectiveFrom
    ): void {
        $st = $this->db->prepare("
            INSERT INTO jurisdiction_boundary_datasets (
                id,
                dataset_hash_sha256,
                source,
                effective_from,
                created_at
            )
            VALUES (
                :id,
                :hash,
                :source,
                :effective_from::date,
                now()
            )
            ON CONFLICT (id) DO NOTHING
        ");

        $st->execute([
            ':id' => $datasetId,
            ':hash' => $datasetHash,
            ':source' => $source,
            ':effective_from' => $effectiveFrom
        ]);
    }
}
