<?php
declare(strict_types=1);

namespace App\Services\Filing;

use RuntimeException;

final class TemplateRenderer
{
    /**
     * Render a template by replacing {{tokens}} with values.
     *
     * @param string $templatePath
     * @param array<string,string> $data
     */
    public function render(string $templatePath, array $data): string
    {
        if (!file_exists($templatePath)) {
            throw new RuntimeException("TEMPLATE_NOT_FOUND: {$templatePath}");
        }

        $template = file_get_contents($templatePath);

        if ($template === false) {
            throw new RuntimeException("TEMPLATE_READ_FAILED");
        }

        $replacements = [];

        foreach ($data as $key => $value) {
            $replacements['{{' . $key . '}}'] = $value;
        }

        $output = strtr($template, $replacements);

        return $output;
    }
}