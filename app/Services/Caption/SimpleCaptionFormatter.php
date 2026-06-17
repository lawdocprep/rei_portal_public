<?php
namespace App\Services\Caption;

final class SimpleCaptionFormatter implements CaptionFormatter
{
    public function format(array $plaintiffs, array $defendants): array
    {
        $p = implode(', ', array_column($plaintiffs, 'name')) ?: 'Plaintiff';
        $d = implode(', ', array_column($defendants, 'name')) ?: 'Defendant';

        return [
            'style' => 'simple',
            'formatted' => "{$p} v. {$d}",
        ];
    }
}
