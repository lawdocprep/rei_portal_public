<?php
declare(strict_types=1);

namespace App\Console;

use App\Services\JurisdictionBoundaryUpdateService;
use PDO;
use RuntimeException;

final class BoundaryIngestCommand
{
    public function __construct(
        private PDO $db
    ) {}

    public function run(array $opts): void
    {
        $stateCode = $opts['state'] ?? 'AZ';
        $unitType  = $opts['unit_type'] ?? 'county';
        $sourceUrl = $opts['url'] ?? null;
        $stagingTable = $opts['staging_table'] ?? null;

        if (!$sourceUrl || !$stagingTable) {
            throw new RuntimeException('INVALID_INGEST_OPTIONS');
        }

        $tmpDir = sys_get_temp_dir() . '/boundary_ingest_' . time();
        mkdir($tmpDir, 0777, true);

        $zipPath = $tmpDir . '/data.zip';

        echo "Downloading $sourceUrl\n";
        file_put_contents($zipPath, file_get_contents($sourceUrl));

        echo "Extracting...\n";
        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $zip->extractTo($tmpDir);
        $zip->close();

        $shp = glob($tmpDir . '/*.shp')[0] ?? null;
        if (!$shp) {
            throw new RuntimeException('SHP_NOT_FOUND');
        }

        echo "Importing via ogr2ogr...\n";

        $cmd = sprintf(
            'docker run --rm -v %s:/data ghcr.io/osgeo/gdal:ubuntu-small-3.8.5 ogr2ogr -f "PostgreSQL" PG:"host=host.docker.internal port=5432 user=portal dbname=portal password=portal_pw" /data/%s -nln %s -nlt MULTIPOLYGON -t_srs EPSG:4326 -overwrite',
            escapeshellarg($tmpDir),
            basename($shp),
            escapeshellarg($stagingTable)
        );

        exec($cmd, $out, $code);

        if ($code !== 0) {
            throw new RuntimeException('OGR2OGR_FAILED');
        }

        echo "Applying boundary update...\n";

        $svc = new JurisdictionBoundaryUpdateService($this->db);

        $run = $svc->applyFromStaging([
            'state_code' => $stateCode,
            'unit_type' => $unitType,
            'staging_table' => $stagingTable,
            'name_column' => 'name',
            'geom_column' => 'wkb_geometry',
            'source' => 'Automated Import',
            'dry_run' => false,
            'overlap_epsilon' => 1e-9,
        ]);

        echo "Boundary update complete.\n";
        echo json_encode($run, JSON_PRETTY_PRINT) . "\n";
    }
}
