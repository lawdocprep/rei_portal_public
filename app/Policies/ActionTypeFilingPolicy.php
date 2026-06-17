<?php
declare(strict_types=1);

namespace App\Policies;

final class ActionTypeFilingPolicy
{
    private const MAP = [
        'az_forcible_detainer' => [
            'include_fictitious_occupant' => true,
            'default_caption_style' => 'simple',
        ],
        'az_writ_of_restitution' => [
            'include_fictitious_occupant' => true,
            'default_caption_style' => 'simple',
        ],
        '*' => [
            'include_fictitious_occupant' => false,
            'default_caption_style' => 'simple',
        ],
    ];

    public static function forActionType(string $type): array
    {
        return self::MAP[$type] ?? self::MAP['*'];
    }

    public static function defaultCaptionStyle(string $type): string
    {
        return (string)(self::forActionType($type)['default_caption_style'] ?? 'simple');
    }
}
