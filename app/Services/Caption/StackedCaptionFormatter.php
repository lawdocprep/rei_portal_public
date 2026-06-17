<?php
namespace App\Services\Caption;

final class StackedCaptionFormatter implements CaptionFormatter
{
    public function format(array $plaintiffs, array $defendants): array
    {
        return [
            'style' => 'stacked',
            'formatted' =>
                implode("\n", array_column($plaintiffs, 'name')) .
                "\n\nv.\n\n" .
                implode("\n", array_column($defendants, 'name')),
        ];
    }
}
