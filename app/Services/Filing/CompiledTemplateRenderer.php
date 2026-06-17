<?php
declare(strict_types=1);

namespace App\Services\Filing;

use RuntimeException;
use TCPDF;

final class CompiledTemplateRenderer
{
    public function render(
        array $compiledTemplate,
        array $fieldValues,
        string $outputPath
    ): void {

        $compiledTemplate = $this->normalizeTemplate($compiledTemplate);

        $this->assertTemplateShape($compiledTemplate);

        $pdf = new TCPDF();

        $pdf->SetCreator('REI Portal');

        $pdf->SetMargins(
            $compiledTemplate['page']['margins']['left'] ?? 72,
            $compiledTemplate['page']['margins']['top'] ?? 72,
            $compiledTemplate['page']['margins']['right'] ?? 72
        );

        $pdf->SetAutoPageBreak(
            true,
            $compiledTemplate['page']['margins']['bottom'] ?? 72
        );

        $pdf->AddPage();

        $this->initializePage($pdf, $compiledTemplate);

        foreach ($compiledTemplate['blocks'] as $block) {
            $this->renderBlock($pdf, $block, $fieldValues, $compiledTemplate);
        }

        $pdf->Output($outputPath, 'F');
    }

    /**
     * ------------------------------------------------------------
     * Template normalization
     * ------------------------------------------------------------
     *
     * Adds layout profile support while preserving backward
     * compatibility for templates that already define pleading_layout.
     */
    private function normalizeTemplate(array $template): array
    {
        $typography = $template['typography'] ?? [];

        $profileName = (string)($typography['pleading_layout_profile'] ?? 'superior');

        $profiles = $this->getPleadingLayoutProfiles();

        $profile = $profiles[$profileName] ?? $profiles['superior'];

        $existingLayout = $typography['pleading_layout'] ?? [];

        if (!is_array($existingLayout)) {
            $existingLayout = [];
        }

        /*
        ------------------------------------------------------------
        Merge order:
        1. built-in profile defaults
        2. explicit template pleading_layout overrides
        ------------------------------------------------------------
        */

        $mergedLayout = array_merge($profile, $existingLayout);

        $template['typography'] = array_merge(
            [
                'font_family' => 'times',
                'font_size' => 13,
            ],
            $typography
        );

        $template['typography']['pleading_layout_profile'] = $profileName;
        $template['typography']['pleading_layout'] = $mergedLayout;

        /*
        ------------------------------------------------------------
        Allow profile to supply default line height if template does not
        explicitly specify one.
        ------------------------------------------------------------
        */

        if (!isset($template['typography']['line_height'])) {
            $template['typography']['line_height'] = (float)($mergedLayout['line_height'] ?? 16.0);
        }

        /*
        ------------------------------------------------------------
        Ensure page margin defaults exist
        ------------------------------------------------------------
        */

        $template['page'] = $template['page'] ?? [];
        $template['page']['margins'] = array_merge(
            [
                'top' => 72,
                'bottom' => 72,
                'left' => 72,
                'right' => 72,
            ],
            is_array($template['page']['margins'] ?? null)
                ? $template['page']['margins']
                : []
        );

        return $template;
    }

    /**
     * ------------------------------------------------------------
     * Built-in pleading layout profiles
     * ------------------------------------------------------------
     *
     * Templates may specify:
     *   'pleading_layout_profile' => 'superior'
     *
     * Existing templates that directly define:
     *   'pleading_layout' => [...]
     *
     * continue to work and override profile defaults.
     */
    private function getPleadingLayoutProfiles(): array
    {
        return [
            'superior' => [
                'line_number_x' => 40,
                'line_number_width' => 20,
                'left_rule_1' => 65,
                'left_rule_2' => 70,
                'text_column_x' => 90,
                'right_rule' => 540,
                'number_gutter_width' => 22,
                'number_body_gap' => 6,
                'caption_divider_offset' => 5,
                'caption_left_width' => 220,
                'caption_right_width' => 220,
                'caption_row_height' => 16,
                'line_height' => 16,
                'line_numbers_count' => 25,
            ],

            'justice' => [
                'line_number_x' => 35,
                'line_number_width' => 18,
                'left_rule_1' => 60,
                'left_rule_2' => 65,
                'text_column_x' => 85,
                'right_rule' => 530,
                'number_gutter_width' => 20,
                'number_body_gap' => 6,
                'caption_divider_offset' => 5,
                'caption_left_width' => 215,
                'caption_right_width' => 215,
                'caption_row_height' => 15,
                'line_height' => 15,
                'line_numbers_count' => 25,
            ],

            'federal' => [
                'line_number_x' => 35,
                'line_number_width' => 18,
                'left_rule_1' => 60,
                'left_rule_2' => 65,
                'text_column_x' => 85,
                'right_rule' => 520,
                'number_gutter_width' => 20,
                'number_body_gap' => 6,
                'caption_divider_offset' => 5,
                'caption_left_width' => 210,
                'caption_right_width' => 210,
                'caption_row_height' => 15,
                'line_height' => 15,
                'line_numbers_count' => 25,
            ],
        ];
    }

    private function initializePage(TCPDF $pdf, array $template): void
    {
        $this->applyTypography($pdf, $template);

        $this->drawPleadingMargins($pdf, $template);

        $this->drawLineNumbers($pdf, $template);
    }

    private function drawLineNumbers(TCPDF $pdf, array $template): void
    {
        $layout = $template['typography']['pleading_layout'] ?? [];

        $lineX = (float)($layout['line_number_x'] ?? 40);
        $lineWidth = (float)($layout['line_number_width'] ?? 20);
        $lineCount = (int)($layout['line_numbers_count'] ?? 25);

        $topMargin = (float)($template['page']['margins']['top'] ?? 72);
        $lineHeight = (float)($template['typography']['line_height'] ?? 16);

        $pdf->SetFont('times', '', 10);

        for ($i = 1; $i <= $lineCount; $i++) {

            $y = $topMargin + (($i - 1) * $lineHeight);

            $pdf->SetXY($lineX, $y);

            $pdf->Cell($lineWidth, $lineHeight, (string)$i, 0, 0, 'R');
        }

        $this->applyTypography($pdf, $template);
    }

    private function drawPleadingMargins(TCPDF $pdf, array $template): void
    {
        $layout = $template['typography']['pleading_layout'] ?? [];

        $left1 = (float)($layout['left_rule_1'] ?? 65);
        $left2 = (float)($layout['left_rule_2'] ?? 70);
        $right = (float)($layout['right_rule'] ?? 540);

        $top = (float)($template['page']['margins']['top'] ?? 72);
        $bottom = (float)($template['page']['margins']['bottom'] ?? 72);

        $pageHeight = (float)$pdf->getPageHeight();

        $y1 = $top;
        $y2 = $pageHeight - $bottom;

        $pdf->Line($left1, $y1, $left1, $y2);
        $pdf->Line($left2, $y1, $left2, $y2);
        $pdf->Line($right, $y1, $right, $y2);
    }

    private function assertTemplateShape(array $compiledTemplate): void
    {
        if (
            !isset($compiledTemplate['page']) ||
            !isset($compiledTemplate['blocks']) ||
            !is_array($compiledTemplate['blocks'])
        ) {
            throw new RuntimeException('COMPILED_TEMPLATE_INVALID');
        }
    }

    private function renderBlock(
        TCPDF $pdf,
        array $block,
        array $fieldValues,
        array $template
    ): void {

        /*
        ------------------------------------------------------------
        Conditional block filtering
        ------------------------------------------------------------
        */

        if (
            isset($block['condition_field'])
            && empty($fieldValues[$block['condition_field']])
        ) {
            return;
        }

        if (
            isset($block['condition_not_field'])
            && !empty($fieldValues[$block['condition_not_field']])
        ) {
            return;
        }

        /*
        ------------------------------------------------------------
        Dispatch block rendering
        ------------------------------------------------------------
        */

        $type = (string)($block['type'] ?? '');

        if ($type === 'paragraph') {
            $this->renderParagraph($pdf, $block, $fieldValues, $template);
            return;
        }

        if ($type === 'textbox') {
            $this->renderTextbox($pdf, $block, $fieldValues, $template);
            return;
        }

        if ($type === 'flow_text') {
            $this->renderFlowText($pdf, $block, $fieldValues, $template);
            return;
        }

        if ($type === 'paragraph_flow') {
            $this->renderParagraphFlow($pdf, $block, $fieldValues, $template);
            return;
        }

        if ($type === 'caption_grid') {
            $this->renderCaptionGrid($pdf, $block, $fieldValues, $template);
            return;
        }

        throw new RuntimeException('UNSUPPORTED_TEMPLATE_BLOCK_TYPE');
    }

    private function renderParagraphFlow(
        TCPDF $pdf,
        array $block,
        array $fieldValues,
        array $template
    ): void {

        $layout = $template['typography']['pleading_layout'] ?? [];

        $x = (float)($block['x'] ?? $layout['text_column_x'] ?? 90);
        $y = (float)($block['y'] ?? 0.0);
        $w = (float)($block['w'] ?? 450.0);

        $field = (string)($block['field'] ?? '');
        $text = trim((string)($fieldValues[$field] ?? ''));

        if ($text === '') {
            return;
        }

        $paragraphSpacing = (float)($block['paragraph_spacing'] ?? 4.0);
        $widowLines = (int)($block['widow_lines'] ?? 2);

        $pdf->SetXY($x, $y);

        $paragraphs = $this->splitParagraphs($text);

        foreach ($paragraphs as $paragraph) {

            $node = $this->parsePleadingParagraph($paragraph);

            $this->renderParagraphNode(
                $pdf,
                $node,
                $x,
                $w,
                $template,
                $paragraphSpacing,
                $widowLines
            );
        }
    }

    private function splitParagraphs(string $text): array
    {
        $parts = preg_split("/(?:\r\n|\r|\n){2,}/", trim($text)) ?: [];

        $out = [];

        foreach ($parts as $part) {
            $p = trim($part);
            if ($p !== '') {
                $out[] = preg_replace("/[ \t]+/", ' ', $p) ?? $p;
            }
        }

        return $out;
    }

    private function parsePleadingParagraph(string $paragraph): array
    {
        if (preg_match('/^(\d+)\.\s+(.*)$/su', $paragraph, $m)) {
            return [
                'kind' => 'numbered',
                'number' => $m[1] . '.',
                'text' => trim($m[2]),
            ];
        }

        return [
            'kind' => 'plain',
            'number' => null,
            'text' => $paragraph,
        ];
    }

    private function renderParagraphNode(
        TCPDF $pdf,
        array $node,
        float $x,
        float $w,
        array $template,
        float $paragraphSpacing,
        int $widowLines
    ): void {

        $lineHeight = (float)($template['typography']['line_height'] ?? 16.0);
        $topMargin = (float)($template['page']['margins']['top'] ?? 72.0);
        $bottomMargin = (float)($template['page']['margins']['bottom'] ?? 72.0);
        $pageHeight = (float)$pdf->getPageHeight();
        $maxY = $pageHeight - $bottomMargin;

        $layout = $template['typography']['pleading_layout'] ?? [];

        $numberWidth = (float)($layout['number_gutter_width'] ?? 22.0);
        $bodyGap = (float)($layout['number_body_gap'] ?? 6.0);

        $isNumbered = (($node['kind'] ?? '') === 'numbered');

        $bodyX = $isNumbered ? ($x + $numberWidth + $bodyGap) : $x;
        $bodyW = $isNumbered ? max(20.0, $w - $numberWidth - $bodyGap) : $w;

        $remainingText = (string)($node['text'] ?? '');
        $printedNumber = false;

        while ($remainingText !== '') {

            $availableHeight = $maxY - (float)$pdf->GetY();
            $minimumUsefulHeight = $lineHeight * max(1, $widowLines);

            if ($availableHeight < $minimumUsefulHeight) {
                $pdf->AddPage();
                $this->initializePage($pdf, $template);
                $pdf->SetXY($x, $topMargin);
                $availableHeight = $maxY - (float)$pdf->GetY();
            }

            $chunk = $this->fitTextChunkToHeight(
                $pdf,
                $remainingText,
                $bodyW,
                $availableHeight,
                $lineHeight
            );

            if ($chunk === '') {
                $pdf->AddPage();
                $this->initializePage($pdf, $template);
                $pdf->SetXY($x, $topMargin);
                continue;
            }

            if ($isNumbered && !$printedNumber) {
                $currentY = (float)$pdf->GetY();

                $pdf->SetXY($x, $currentY);
                $pdf->MultiCell(
                    $numberWidth,
                    $lineHeight,
                    (string)$node['number'],
                    0,
                    'R',
                    false,
                    0
                );
            }

            $currentY = (float)$pdf->GetY();

            $pdf->SetXY($bodyX, $currentY);
            $pdf->MultiCell(
                $bodyW,
                $lineHeight,
                $chunk,
                0,
                'L',
                false,
                1
            );

            $printedNumber = true;
            $remainingText = ltrim(substr($remainingText, strlen($chunk)));

            if ($remainingText !== '') {
                $pdf->AddPage();
                $this->initializePage($pdf, $template);
                $pdf->SetXY($x, $topMargin);
            } else {
                $pdf->Ln($paragraphSpacing);
            }
        }
    }

    private function fitTextChunkToHeight(
        TCPDF $pdf,
        string $text,
        float $width,
        float $maxHeight,
        float $lineHeight
    ): string {

        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $fullHeight = $this->measureTextHeight($pdf, $width, $text, $lineHeight);

        if ($fullHeight <= $maxHeight) {
            return $text;
        }

        $words = preg_split('/\s+/u', $text) ?: [];

        if (count($words) === 0) {
            return '';
        }

        $low = 1;
        $high = count($words);
        $best = '';

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);

            $candidate = implode(' ', array_slice($words, 0, $mid));
            $height = $this->measureTextHeight($pdf, $width, $candidate, $lineHeight);

            if ($height <= $maxHeight) {
                $best = $candidate;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return trim($best);
    }

    private function measureTextHeight(
        TCPDF $pdf,
        float $width,
        string $text,
        float $lineHeight
    ): float {
        $text = trim($text);

        if ($text === '') {
            return 0.0;
        }

        return (float)$pdf->getStringHeight(
            $width,
            $text,
            false,
            true,
            '',
            $lineHeight
        );
    }

    private function renderFlowText(
        TCPDF $pdf,
        array $block,
        array $fieldValues,
        array $template
    ): void {

        $layout = $template['typography']['pleading_layout'] ?? [];

        $x = (float)($block['x'] ?? $layout['text_column_x'] ?? 90);
        $y = (float)($block['y'] ?? 0);
        $w = (float)($block['w'] ?? 450);

        $lineHeight = (float)($template['typography']['line_height'] ?? 16);

        $field = (string)($block['field'] ?? '');
        $text = (string)($fieldValues[$field] ?? '');

        if ($text === '') {
            return;
        }

        $pdf->SetXY($x, $y);

        $bottomMargin = (float)($template['page']['margins']['bottom'] ?? 72);
        $pageHeight = (float)$pdf->getPageHeight();
        $maxY = $pageHeight - $bottomMargin;

        $lines = preg_split('/\R/', $text) ?: [];

        foreach ($lines as $line) {

            if ((float)$pdf->GetY() + $lineHeight > $maxY) {

                $pdf->AddPage();

                $this->initializePage($pdf, $template);

                $pdf->SetXY(
                    $x,
                    (float)($template['page']['margins']['top'] ?? 72)
                );
            }

            $pdf->MultiCell(
                $w,
                $lineHeight,
                $line,
                0,
                'L',
                false,
                1
            );
        }
    }

    private function renderParagraph(
        TCPDF $pdf,
        array $block,
        array $fieldValues,
        array $template
    ): void {

        $layout = $template['typography']['pleading_layout'] ?? [];

        $x = (float)($block['x'] ?? $layout['text_column_x'] ?? 90);
        $y = (float)($block['y'] ?? 0);
        $w = (float)($block['w'] ?? 450);

        $lineHeight = (float)($template['typography']['line_height'] ?? 16);

        $text = $this->resolveContent(
            is_array($block['content'] ?? null) ? $block['content'] : [],
            $fieldValues
        );

        $pdf->SetXY($x, $y);

        $pdf->MultiCell(
            $w,
            $lineHeight,
            $text,
            0,
            'L',
            false,
            1
        );
    }

    private function renderTextbox(
        TCPDF $pdf,
        array $block,
        array $fieldValues,
        array $template
    ): void {

        $layout = $template['typography']['pleading_layout'] ?? [];

        $x = (float)($block['x'] ?? $layout['text_column_x'] ?? 90);
        $y = (float)($block['y'] ?? 0);
        $w = (float)($block['w'] ?? 450);
        $h = (float)($block['h'] ?? 0);

        $lineHeight = (float)($template['typography']['line_height'] ?? 16);

        $field = (string)($block['field'] ?? '');
        $text = (string)($fieldValues[$field] ?? '');

        $pdf->SetXY($x, $y);

        $pdf->MultiCell(
            $w,
            $lineHeight,
            $text,
            0,
            'L',
            false,
            1,
            '',
            '',
            true,
            0,
            false,
            true,
            $h
        );
    }

    /**
     * ------------------------------------------------------------
     * Caption grid
     * ------------------------------------------------------------
     *
     * Expected field format:
     * [
     *   'left_column'  => [...],
     *   'right_column' => [...]
     * ]
     */
    private function renderCaptionGrid(
        TCPDF $pdf,
        array $block,
        array $fieldValues,
        array $template
    ): void {

        $field = (string)($block['field'] ?? '');
        $grid = $fieldValues[$field] ?? null;

        if (!is_array($grid)) {
            return;
        }

        $left = isset($grid['left_column']) && is_array($grid['left_column'])
            ? array_values($grid['left_column'])
            : [];

        $right = isset($grid['right_column']) && is_array($grid['right_column'])
            ? array_values($grid['right_column'])
            : [];

        $rows = max(count($left), count($right));

        if ($rows === 0) {
            return;
        }

        $layout = $template['typography']['pleading_layout'] ?? [];

        $x = (float)($block['x'] ?? ($layout['text_column_x'] ?? 90));
        $y = (float)($block['y'] ?? 0.0);

        $leftWidth = (float)($block['left_width'] ?? ($layout['caption_left_width'] ?? 220));
        $rightWidth = (float)($block['right_width'] ?? ($layout['caption_right_width'] ?? 220));
        $rowHeight = (float)($block['row_height'] ?? ($layout['caption_row_height'] ?? $template['typography']['line_height'] ?? 16));

        /*
        ------------------------------------------------------------
        Expand left column if party names exceed nominal width
        ------------------------------------------------------------
        */

        $maxTextWidth = 0.0;

        foreach ($left as $text) {

            $t = trim((string)$text);

            if ($t === '') {
                continue;
            }

            $width = (float)$pdf->GetStringWidth($t);

            if ($width > $maxTextWidth) {
                $maxTextWidth = $width;
            }
        }

        $padding = 10.0;

        if (($maxTextWidth + $padding) > $leftWidth) {
            $leftWidth = $maxTextWidth + $padding;
        }

        /*
        ------------------------------------------------------------
        Divider aligned to pleading geometry
        ------------------------------------------------------------
        */

        $leftRule = (float)($layout['left_rule_2'] ?? 70);
        $rightRule = (float)($layout['right_rule'] ?? 540);
        $dividerOffset = (float)($layout['caption_divider_offset'] ?? 5);

        $dividerX = ($leftRule + $rightRule) / 2.0;

        /*
        ------------------------------------------------------------
        Page boundaries
        ------------------------------------------------------------
        */

        $topMargin = (float)($template['page']['margins']['top'] ?? 72);
        $bottomMargin = (float)($template['page']['margins']['bottom'] ?? 72);
        $pageHeight = (float)$pdf->getPageHeight();
        $maxY = $pageHeight - $bottomMargin;

        $pageStartY = $y;

        for ($i = 0; $i < $rows; $i++) {

            $l = (string)($left[$i] ?? '');
            $r = (string)($right[$i] ?? '');

            /*
            ------------------------------------------------------------
            Prevent page break between "vs." and the next row
            ------------------------------------------------------------
            */

            if (trim($l) === 'vs.' && ($i + 1) < $rows) {

                if (($y + ($rowHeight * 2.0)) > $maxY) {

                    $pdf->Line($dividerX, $pageStartY, $dividerX, $y);

                    $pdf->AddPage();
                    $this->initializePage($pdf, $template);

                    $y = $topMargin;
                    $pageStartY = $y;
                }
            }

            /*
            ------------------------------------------------------------
            Prevent page break inside caption role block
            Example:
            John Doe
            Jane Doe
            DEFENDANTS.
            ------------------------------------------------------------
            */

            $blockRows = 1;

            if ($l !== '' && !preg_match('/\.$/', $l)) {

                for ($j = $i; $j < $rows; $j++) {

                    $blockRows++;

                    if (preg_match('/\.$/', (string)($left[$j] ?? ''))) {
                        break;
                    }
                }

                $requiredHeight = $blockRows * $rowHeight;

                if (($y + $requiredHeight) > $maxY) {

                    $pdf->Line($dividerX, $pageStartY, $dividerX, $y);

                    $pdf->AddPage();
                    $this->initializePage($pdf, $template);

                    $y = $topMargin;
                    $pageStartY = $y;
                }
            }

            /*
            ------------------------------------------------------------
            Normal page break
            ------------------------------------------------------------
            */

            if (($y + $rowHeight) > $maxY) {

                $pdf->Line($dividerX, $pageStartY, $dividerX, $y);

                $pdf->AddPage();
                $this->initializePage($pdf, $template);

                $y = $topMargin;
                $pageStartY = $y;
            }

            $pdf->SetXY($x, $y);
            $pdf->MultiCell($leftWidth, $rowHeight, $l, 0, 'L', false, 0);

            $pdf->SetXY($dividerX + $dividerOffset, $y);
            $pdf->MultiCell($rightWidth, $rowHeight, $r, 0, 'L', false, 1);

            $y += $rowHeight;
        }

        /*
        ------------------------------------------------------------
        Final divider for current page segment
        ------------------------------------------------------------
        */

        $pdf->Line($dividerX, $pageStartY, $dividerX, $y);

        /*
        ------------------------------------------------------------
        Bottom rule under left caption column
        ------------------------------------------------------------
        */

        $pdf->Line($x, $y, $x + $leftWidth, $y);
    }

    private function ensureCaptionGroupFits(
        TCPDF $pdf,
        float $rowHeight,
        float $topMargin,
        float $bottomMargin,
        array $template
    ): void {

        $pageHeight = $pdf->getPageHeight();
        $maxY = $pageHeight - $bottomMargin;

        $minimumRows = 3;

        $requiredHeight = $minimumRows * $rowHeight;

        if ($pdf->GetY() + $requiredHeight > $maxY) {

            $pdf->AddPage();

            $this->initializePage($pdf, $template);

            $pdf->SetY($topMargin);
        }
    }

    private function ensureCaptionBlockFits(
        TCPDF $pdf,
        array $rows,
        int $index,
        float $rowHeight,
        float $bottomMargin,
        array $template
    ): void {

        $pageHeight = $pdf->getPageHeight();
        $maxY = $pageHeight - $bottomMargin;

        $count = 0;

        for ($i = $index; $i < count($rows); $i++) {
            $count++;

            if (preg_match('/\.$/', (string)$rows[$i])) {
                break;
            }
        }

        $requiredHeight = $count * $rowHeight;

        if ($pdf->GetY() + $requiredHeight > $maxY) {
            $pdf->AddPage();
            $this->initializePage($pdf, $template);
        }
    }

    private function applyTypography(TCPDF $pdf, array $template): void
    {
        $font = (string)($template['typography']['font_family'] ?? 'times');
        $size = (float)($template['typography']['font_size'] ?? 13);

        $pdf->SetFont($font, '', $size);
    }
}