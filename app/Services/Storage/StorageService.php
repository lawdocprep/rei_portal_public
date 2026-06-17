<?php
declare(strict_types=1);

namespace App\Services\Storage;

final class StorageService
{
    private string $root;

    public function __construct(
        ?string $root = null
    ) {
        $this->root = $root ?? __DIR__ . '/../../../storage';
    }

    public function put(
        string $key,
        string $localFile
    ): string {

        $target = $this->root . '/' . $key;

        $dir = dirname($target);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (!copy($localFile, $target)) {
            throw new \RuntimeException('STORAGE_WRITE_FAILED');
        }

        return $key;
    }

    public function getPath(string $key): string
    {
        $path = $this->root . '/' . $key;

        if (!file_exists($path)) {
            throw new \RuntimeException('STORAGE_OBJECT_NOT_FOUND');
        }

        return $path;
    }

    public function stream(string $key): string
    {
        $path = $this->getPath($key);

        return file_get_contents($path);
    }
}