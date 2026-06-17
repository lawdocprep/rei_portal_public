<?php
declare(strict_types=1);

namespace App\Services\Filing;

use RuntimeException;

final class TemplateFieldResolver
{
    public function resolveComplaintFields(array $bundle): array
    {
        $artifacts = $this->decodeArtifacts($bundle);

        $complaint = $artifacts['complaint'] ?? null;

        if (!is_array($complaint)) {
            throw new RuntimeException('COMPLAINT_ARTIFACT_MISSING');
        }

        $tenantId = (string)($bundle['tenant_id'] ?? '');
        $caseId   = (string)($bundle['case_id'] ?? '');

        $defendants = $this->collectDefendants($complaint);


        /*
        ------------------------------------------------------------
        Caption generation (DB preferred)
        ------------------------------------------------------------
        */

        $caption = $this->buildCaption($complaint);

        $captionGrid = null;

        $tenantId = (string)($bundle['tenant_id'] ?? '');
        $caseId   = (string)($bundle['case_id'] ?? '');

        if ($tenantId !== '' && $caseId !== '') {

            try {

                $rows = $this->loadCaptionParties($tenantId, $caseId);

                if (!empty($rows)) {
                    $captionGrid = $this->buildCaptionFromDb($rows);
                }

            } catch (\Throwable $e) {
                // fallback to artifact caption
            }
        }

        if ($captionGrid === null) {
            $captionGrid = $this->buildCaptionGrid($complaint);
        }

        /*
        ------------------------------------------------------------
        Complaint body generation
        ------------------------------------------------------------
        */

        $plaintiff = trim((string)($complaint['plaintiff_name'] ?? ''));

        $body = [];

        $body[] =
            "Plaintiff {$plaintiff} for its causes of action against Defendant(s) alleges and states as follows:";

        $body[] =
            "1. Plaintiff {$plaintiff} is the purchaser of the real property described below at a trustee sale.";

        $i = 2;

        foreach ($defendants as $defendant) {

            $body[] =
                "{$i}. Defendant {$defendant} is in possession of the property.";

            $i++;
        }

        $body[] =
            "{$i}. The property that is the subject of this action is located at {$complaint['property_address']}.";

        $i++;

        $body[] =
            "{$i}. The property is legally described as follows:";

        $body[] =
            $complaint['property_legal_description'] ?? '';

        $i++;

        $body[] =
            "{$i}. Plaintiff purchased the property at trustee sale on {$complaint['trustee_sale_date']}.";

        $i++;

        $body[] =
            "{$i}. Plaintiff caused a demand for possession to be delivered on {$complaint['demand_sent_date']} by {$complaint['demand_delivery_method']}.";

        $i++;

        $body[] =
            "{$i}. Defendants have failed and refused to surrender possession of the property.";

        $bodyText = implode("\n\n", $body);

        /*
        ------------------------------------------------------------
        Signature block
        ------------------------------------------------------------
        */

        $signatureDate = (string)($complaint['signature_date'] ?? '');

        $signatureBlock = "
    THE REAL ESTATE INVESTORS LAW FIRM

    Blake D. Gunn
    Attorney for Plaintiff

    Dated: {$signatureDate}
    ";

        /*
        ------------------------------------------------------------
        Return template fields
        ------------------------------------------------------------
        */

        return [

            'state' => (string)($complaint['state'] ?? 'ARIZONA'),
            'county_name' => (string)($complaint['county_name'] ?? ''),
            'court_name' => (string)($complaint['court_name'] ?? ''),
            'plaintiff_name' => (string)($complaint['plaintiff_name'] ?? ''),

            'defendant0_name' => (string)($complaint['defendant0_name'] ?? ''),
            'defendant1_name' => (string)($complaint['defendant1_name'] ?? ''),
            'fictitious_defendant_name' => (string)($complaint['fictitious_defendant_name'] ?? ''),

            'entity_type' => (string)($complaint['entity_type'] ?? ''),
            'property_address' => (string)($complaint['property_address'] ?? ''),
            'property_legal_description' => (string)($complaint['property_legal_description'] ?? ''),
            'deed_of_trust_date' => (string)($complaint['deed_of_trust_date'] ?? ''),
            'deed_of_trust_recording_number' => (string)($complaint['deed_of_trust_recording_number'] ?? ''),
            'trustee_sale_date' => (string)($complaint['trustee_sale_date'] ?? ''),
            'trustee_deed_recording_number' => (string)($complaint['trustee_deed_recording_number'] ?? ''),
            'demand_delivery_method' => (string)($complaint['demand_delivery_method'] ?? ''),
            'demand_sent_date' => (string)($complaint['demand_sent_date'] ?? ''),
            'signature_date' => (string)($complaint['signature_date'] ?? ''),

            /*
            --------------------------------------------------------
            Caption fields
            --------------------------------------------------------
            */

            'plaintiff_caption' => $caption['plaintiff_caption'],
            'defendant_caption' => $caption['defendant_caption'],
            'caption_block' => $caption['caption_block'],

            /*
            --------------------------------------------------------
            Structured caption grid (renderer format)
            --------------------------------------------------------
            */

            'caption_grid' => $captionGrid,

            /*
            --------------------------------------------------------
            Paragraph flow fields
            --------------------------------------------------------
            */

            'complaint_body' => $bodyText,
            'signature_block' => trim($signatureBlock)
        ];
    }

    /*
    ------------------------------------------------------------
    Summons
    ------------------------------------------------------------
    */

    public function resolveSummonsFields(array $bundle): array
    {
        $artifacts = $this->decodeArtifacts($bundle);

        $summons = $artifacts['summons'] ?? null;

        if (!is_array($summons)) {
            throw new RuntimeException('SUMMONS_ARTIFACT_MISSING');
        }

        return [

            'state' => (string)($summons['state'] ?? 'ARIZONA'),
            'county_name' => (string)($summons['county_name'] ?? ''),
            'court_name' => (string)($summons['court_name'] ?? ''),
            'plaintiff_name' => (string)($summons['plaintiff_name'] ?? ''),
            'defendant0_name' => (string)($summons['defendant0_name'] ?? ''),
            'defendant1_name' => (string)($summons['defendant1_name'] ?? ''),
            'fictitious_defendant_name' => (string)($summons['fictitious_defendant_name'] ?? ''),
            'case_number' => (string)($summons['case_number'] ?? ''),
            'recipient_name' => (string)($summons['recipient_name'] ?? ''),
            'recipient_address' => (string)($summons['recipient_address'] ?? '')

        ];
    }

    /*
    ------------------------------------------------------------
    Arbitration Certificate
    ------------------------------------------------------------
    */

    public function resolveArbitrationCertificateFields(array $bundle): array
    {
        $artifacts = $this->decodeArtifacts($bundle);

        $certificate =
            $artifacts['certificate_compulsory_arbitration'] ?? null;

        if (!is_array($certificate)) {
            throw new RuntimeException(
                'ARBITRATION_CERTIFICATE_ARTIFACT_MISSING'
            );
        }

        return [

            'state' => (string)($certificate['state'] ?? 'ARIZONA'),
            'county_name' => (string)($certificate['county_name'] ?? ''),
            'court_name' => (string)($certificate['court_name'] ?? ''),
            'plaintiff_name' => (string)($certificate['plaintiff_name'] ?? ''),
            'defendant0_name' => (string)($certificate['defendant0_name'] ?? ''),
            'defendant1_name' => (string)($certificate['defendant1_name'] ?? ''),
            'fictitious_defendant_name' => (string)($certificate['fictitious_defendant_name'] ?? ''),
            'signature_date' => (string)($certificate['signature_date'] ?? '')

        ];
    }

    /*
    ------------------------------------------------------------
    Helpers
    ------------------------------------------------------------
    */

    private function collectDefendants(array $complaint): array
    {
        $defendants = [];

        if (!empty($complaint['defendant0_name'])) {
            $defendants[] = (string)$complaint['defendant0_name'];
        }

        if (!empty($complaint['defendant1_name'])) {
            $defendants[] = (string)$complaint['defendant1_name'];
        }

        if (!empty($complaint['fictitious_defendant_name'])) {
            $defendants[] = (string)$complaint['fictitious_defendant_name'];
        }

        return $defendants;
    }

    private function buildCaption(array $complaint): array
    {
        $plaintiff = trim((string)($complaint['plaintiff_name'] ?? ''));

        $defendants = $this->collectDefendants($complaint);

        $plaintiffCaption =
            $plaintiff !== ''
                ? "{$plaintiff},\n\nPlaintiff,"
                : '';

        $defendantCaption = '';

        if (!empty($defendants)) {

            $lines = [];

            foreach ($defendants as $i => $name) {

                if ($i === count($defendants) - 1) {
                    $lines[] = "{$name},\n\nDefendant.";
                } else {
                    $lines[] = "{$name},";
                }
            }

            $defendantCaption = implode("\n", $lines);
        }

        $captionBlock =
            $plaintiffCaption .
            "\n\nvs.\n\n" .
            $defendantCaption;

        return [

            'plaintiff_caption' => $plaintiffCaption,
            'defendant_caption' => $defendantCaption,
            'caption_block' => $captionBlock

        ];
    }

    private function renderCaptionGrid(
        TCPDF $pdf,
        array $block,
        array $fields,
        array $template
    ): void {

        $grid = $fields[$block['field']] ?? null;

        if (!is_array($grid)) {
            return;
        }

        $left  = $grid['left_column'] ?? [];
        $right = $grid['right_column'] ?? [];

        $rows = max(count($left), count($right));

        $x = (float)$block['x'];
        $y = (float)$block['y'];

        $leftWidth  = (float)$block['left_width'];
        $rightWidth = (float)$block['right_width'];

        $rowHeight = (float)($block['row_height'] ?? 16);

        /*
        ------------------------------------------------------------
        Expand caption width if party names would wrap
        ------------------------------------------------------------
        */

        $maxTextWidth = 0;

        foreach ($left as $text) {

            if ($text === '') {
                continue;
            }

            $width = $pdf->GetStringWidth($text);

            if ($width > $maxTextWidth) {
                $maxTextWidth = $width;
            }
        }

        $padding = 10;

        if ($maxTextWidth + $padding > $leftWidth) {
            $leftWidth = $maxTextWidth + $padding;
        }

        /*
        ------------------------------------------------------------
        Divider fixed to pleading layout
        ------------------------------------------------------------
        */

        $layout = $template['typography']['pleading_layout'] ?? [];

        $leftRule  = (float)($layout['left_rule_2'] ?? 70);
        $rightRule = (float)($layout['right_rule'] ?? 540);

        $dividerX = ($leftRule + $rightRule) / 2;

        /*
        ------------------------------------------------------------
        Page boundaries
        ------------------------------------------------------------
        */

        $topMargin    = (float)($template['page']['margins']['top'] ?? 72);
        $bottomMargin = (float)($template['page']['margins']['bottom'] ?? 72);

        $pageHeight = $pdf->getPageHeight();
        $maxY = $pageHeight - $bottomMargin;

        $pageStartY = $y;

        /*
        ------------------------------------------------------------
        Render rows
        ------------------------------------------------------------
        */

        for ($i = 0; $i < $rows; $i++) {

            $l = $left[$i] ?? '';
            $r = $right[$i] ?? '';

            /*
            ------------------------------------------------------------
            Prevent page break between "vs." and first defendant
            ------------------------------------------------------------
            */

            if ($l === 'vs.' && ($i + 1) < $rows) {

                if ($y + ($rowHeight * 2) > $maxY) {

                    $pdf->Line($dividerX, $pageStartY, $dividerX, $y);

                    $pdf->AddPage();

                    $this->applyTypography($pdf, $template);
                    $this->drawPleadingMargins($pdf, $template);
                    $this->initializePleadingPage($pdf, $template);

                    $y = $topMargin;
                    $pageStartY = $y;
                }
            }

            /*
            ------------------------------------------------------------
            Normal page break
            ------------------------------------------------------------
            */

            if ($y + $rowHeight > $maxY) {

                $pdf->Line($dividerX, $pageStartY, $dividerX, $y);

                $pdf->AddPage();

                $this->applyTypography($pdf, $template);
                $this->drawPleadingMargins($pdf, $template);
                $this->initializePleadingPage($pdf, $template);

                $y = $topMargin;
                $pageStartY = $y;
            }

            $pdf->SetXY($x, $y);
            $pdf->MultiCell($leftWidth, $rowHeight, $l, 0, 'L', false, 0);

            $pdf->SetXY($dividerX + 5, $y);
            $pdf->MultiCell($rightWidth, $rowHeight, $r, 0, 'L', false, 1);

            $y += $rowHeight;
        }

        /*
        ------------------------------------------------------------
        Final divider
        ------------------------------------------------------------
        */

        $pdf->Line($dividerX, $pageStartY, $dividerX, $y);

        /*
        ------------------------------------------------------------
        Bottom rule under left column only
        ------------------------------------------------------------
        */

        $pdf->Line($x, $y, $x + $leftWidth, $y);
    }

    private function buildCaptionFromParties(array $parties): array
    {
        $left = [];

        foreach ($parties as $role => $names) {

            foreach ($names as $name) {
                $left[] = $name;
            }

            $left[] = '';
            $left[] = $role . '.';
            $left[] = '';
        }

        return $left;
    }

    private function loadCaptionParties(string $tenantId, string $caseId): array
    {
        $st = $this->db->prepare("
            SELECT
                caption_role,
                display_name
            FROM case_parties
            WHERE tenant_id = :tenant
            AND case_id = :case
            ORDER BY caption_order, display_name
        ");

        $st->execute([
            ':tenant' => $tenantId,
            ':case'   => $caseId
        ]);

        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function buildCaptionFromDb(array $rows): array
    {
        $groups = [];

        foreach ($rows as $r) {

            $role = (string)($r['caption_role'] ?? '');
            $name = (string)($r['display_name'] ?? '');

            if ($role === '' || $name === '') {
                continue;
            }

            $groups[$role][] = $name;
        }

        $left = [];

        foreach ($groups as $role => $names) {

            foreach ($names as $name) {
                $left[] = $name;
            }

            $left[] = '';
            $left[] = strtoupper($role) . '.';
            $left[] = '';
        }

        // Remove trailing blank rows
        while (!empty($left) && trim((string)end($left)) === '') {
            array_pop($left);
        }

        return [
            'left_column'  => $left,
            'right_column' => []
        ];
    }

    private function decodeArtifacts(array $bundle): array
    {
        $artifacts = json_decode(
            (string)($bundle['structured_artifacts_json'] ?? '{}'),
            true
        );

        if (!is_array($artifacts)) {
            throw new RuntimeException('STRUCTURED_ARTIFACTS_INVALID');
        }

        return $artifacts;
    }
}