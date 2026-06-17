<?php
declare(strict_types=1);

namespace App\Services\Filing;

final class CanonicalJsonService
{
    public static function encode(array $data): string
    {
        self::ksortRecursive($data);

        return json_encode(
            $data,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );
    }

    private static function ksortRecursive(array &$data): void
    {
        foreach ($data as &$v) {
            if (is_array($v)) {
                self::ksortRecursive($v);
            }
        }

        ksort($data);
    }
}