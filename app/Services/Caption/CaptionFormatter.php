<?php
namespace App\Services\Caption;

interface CaptionFormatter
{
    public function format(array $plaintiffs, array $defendants): array;
}
