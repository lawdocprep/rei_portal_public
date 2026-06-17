<?php
declare(strict_types=1);

namespace App\Cron;

use App\Services\Procedures\JurisdictionBoundaryUpdateService;
use PDO;
use RuntimeException;

final class BoundaryUpdateJob
{
    public function __construct(
        private PDO $db
    ) {}

    /**
     * Context keys:
     *  - state (string)
     *  - unit_type (string)
     *  - url (string) REQUIRED
     *  - staging_table (string) REQUIRED
     *  - dry_run (bool)
     *  - verbose (bool)
     *  - ogr_pg (string) optional override for PG connection string
     */
    public function run(array $context = []): array
    {
        $stateCode    = $context['state'] ?? 'AZ';
        $unitType     = $context['unit_type'] ?? 'county';
        $sourceUrl    = $context['url'] ?? null;
        $stagingTable = $context['staging_table'] ?? null;
        /*
        ------------------------------------------------------------
        NEW — configurable staging column mapping
        ------------------------------------------------------------
        */

        $nameColumn   = $context['name_column'] ?? 'name';
        $dryRun       = (bool)($context['dry_run'] ?? false);
        $verbose      = (bool)($context['verbose'] ?? false);
        $ogrPg        = $context['ogr_pg']
            ?? 'host=host.docker.internal port=5432 user=portal dbname=portal password=portal_pw';

        if (!$sourceUrl || !$stagingTable) {
            throw new RuntimeException('INVALID_INGEST_OPTIONS');
        }

        $tmpDir = sys_get_temp_dir() . '/boundary_ingest_' . uniqid('', true);

        if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('TMPDIR_CREATE_FAILED');
        }

        try {

            $zipPath = $tmpDir . '/data.zip';

            if ($verbose) {
                fwrite(STDOUT, "Downloading {$sourceUrl}\n");
            }

            $data = @file_get_contents($sourceUrl);
            if ($data === false) {
                throw new RuntimeException('DOWNLOAD_FAILED');
            }

            if (@file_put_contents($zipPath, $data) === false) {
                throw new RuntimeException('ZIP_WRITE_FAILED');
            }

            if ($verbose) {
                fwrite(STDOUT, "Extracting archive...\n");
            }

            $zip = new \ZipArchive();
            $zipOpen = $zip->open($zipPath);

            if ($zipOpen !== true) {
                throw new RuntimeException('ZIP_OPEN_FAILED');
            }

            if (!$zip->extractTo($tmpDir)) {
                $zip->close();
                throw new RuntimeException('ZIP_EXTRACT_FAILED');
            }

            $zip->close();

            $shpFiles = glob($tmpDir . '/*.shp');
            $shp = $shpFiles[0] ?? null;

            if (!$shp) {
                throw new RuntimeException('SHP_NOT_FOUND');
            }

            if ($verbose) {
                fwrite(STDOUT, "Importing via ogr2ogr...\n");
            }

            $cmd = sprintf(
                'docker run --rm -v %s:/data ghcr.io/osgeo/gdal:ubuntu-small-3.8.5 ogr2ogr -f "PostgreSQL" PG:"%s" /data/%s -nln %s -nlt MULTIPOLYGON -t_srs EPSG:4326 -overwrite',
                escapeshellarg($tmpDir),
                $ogrPg,
                basename($shp),
                escapeshellarg($stagingTable)
            );

            exec($cmd, $out, $code);

            if ($code !== 0) {
                throw new RuntimeException('OGR2OGR_FAILED');
            }

            if ($verbose) {
                fwrite(STDOUT, "Applying boundary update...\n");
            }

            $svc = new JurisdictionBoundaryUpdateService($this->db);

            $effectiveFrom = $context['effective_from'] ?? '2026-01-01';

            /*
            ------------------------------------------------------------
            Dataset identity + provenance
            ------------------------------------------------------------
            */

            $datasetId = $context['dataset_id']
                ?? $this->generateUuidV4();

            $datasetHash = $context['dataset_hash_sha256']
                ?? hash_file('sha256', $zipPath);

            $run = $svc->applyFromStaging([
                'dataset_id' => $datasetId,
                'dataset_hash_sha256' => $datasetHash,

                'state_code' => $stateCode,
                'unit_type' => $unitType,
                'staging_table' => $stagingTable,

                'name_column' => $nameColumn,
                'geom_column' => 'wkb_geometry',

                'source' => 'Automated Import',
                'source_url' => $sourceUrl,

                'effective_from' => $effectiveFrom,
            ]);

            if ($verbose) {
                fwrite(STDOUT, "Boundary update complete.\n");
            }

            return [
                'exit_code' => 0,
                'message'   => 'Boundary update completed successfully.',
                'metrics'   => $run,
            ];

        } finally {
            // Safe temp directory cleanup (always runs)
            $this->recursiveRemove($tmpDir);
        }
    }

    /**
     * Recursively remove temp directory.
     * Silent cleanup — never throws.
     */
    private function recursiveRemove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $file;

            if (is_dir($path)) {
                $this->recursiveRemove($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    private function generateUuidV4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}
