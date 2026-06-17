<?php

namespace App\Services\Arizona;

final class ArizonaArbitrationLimitService
{
    private const LIMITS = [
        'Apache'      => 10000,
        'Cochise'     => 50000,
        'Coconino'    => 65000,
        'Gila'        => 25000,
        'Graham'      => 30000,
        'Greenlee'    => 1000,
        'La Paz'      => 1000,
        'Maricopa'    => 50000,
        'Mohave'      => 50000,
        'Navajo'      => 25000,
        'Pima'        => 1000,
        'Pinal'       => 40000,
        'Santa Cruz'  => 1000,
        'Yavapai'     => 65000,
        'Yuma'        => 50000,
    ];

    public static function getLimit(string $county): ?int
    {
        return self::LIMITS[$county] ?? null;
    }
}