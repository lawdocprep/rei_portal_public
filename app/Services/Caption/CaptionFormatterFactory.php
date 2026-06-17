<?php
namespace App\Services\Caption;

final class CaptionFormatterFactory
{
    public static function make(string $style): CaptionFormatter
    {
        return match ($style) {
            'stacked' => new StackedCaptionFormatter(),
            default   => new SimpleCaptionFormatter(),
        };
    }
}
