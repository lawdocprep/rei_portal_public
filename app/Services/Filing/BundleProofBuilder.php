<?php
declare(strict_types=1);

namespace App\Services\Filing;

final class BundleProofBuilder
{
    public static function build(array $bundle): array
    {
        return [
            'bundle_hash'            => $bundle['bundle_hash'],
            'intent_hash'            => $bundle['intent_hash'],
            'event_cursor'           => $bundle['event_cursor'],
            'exhibit_manifest_hash'  => hash(
                'sha256',
                json_encode(
                    $bundle['exhibit_manifest_c14n_json'],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            ),
            'generated_at' => gmdate('c'),
            'system'       => 'REI Portal'
        ];
    }

    public static function encode(array $proof): string
    {
        return json_encode(
            $proof,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}