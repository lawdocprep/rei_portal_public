<?php
declare(strict_types=1);

namespace App\Services\Filing\Templates;

use RuntimeException;

final class TemplateResolver
{
    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = $basePath ?? __DIR__ . '/../../../../templates';
    }

    public function resolve(
        string $tenant,
        string $courtType,
        string $actionType,
        string $documentType,
        ?string $state = null,
        ?string $county = null
    ): array {

        $paths = $this->buildSearchPaths(
            $tenant,
            $courtType,
            $actionType,
            $documentType,
            $state,
            $county
        );

        foreach ($paths as $path) {

            if (file_exists($path)) {
                return require $path;
            }
        }

        throw new RuntimeException(
            'TEMPLATE_NOT_FOUND: ' . implode(' → ', $paths)
        );
    }

    private function buildSearchPaths(
        string $tenant,
        string $courtType,
        string $actionType,
        string $documentType,
        ?string $state,
        ?string $county
    ): array {

        $base = rtrim($this->basePath, '/');

        $paths = [];

        if ($county && $state) {
            $paths[] = "$base/$tenant/$state/$county/$courtType/$actionType/$documentType.php";
        }

        if ($state) {
            $paths[] = "$base/$tenant/$state/$courtType/$actionType/$documentType.php";
        }

        if ($state) {
            $paths[] = "$base/$tenant/$state/$actionType/$documentType.php";
        }

        $paths[] = "$base/$tenant/$actionType/$documentType.php";

        $paths[] = "$base/default/$documentType.php";

        return $paths;
    }
}