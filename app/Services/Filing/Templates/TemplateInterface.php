<?php
declare(strict_types=1);

namespace App\Services\Filing\Templates;

interface TemplateInterface
{
    public static function get(): array;
}