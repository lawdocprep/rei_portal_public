<?php
declare(strict_types=1);

namespace App\Services\Filing\Templates;

/**
 * Arizona Superior Court
 * Certificate Regarding Compulsory Arbitration
 */
final class ReiLawFDCertificateCompulsoryArbitrationTemplate
{
    public static function getTemplate(): array
    {
        return [

            'template_key'  => 'reilaw_superior_az_forcible_detainer_certificate_compulsory_arbitration',
            'document_type' => 'certificate_compulsory_arbitration',

            'typography' => [
                'font_family' => 'times',
                'font_size' => 13,
                'line_height' => 16,
                'pleading_layout_profile' => 'superior',
            ],

            'blocks' => [

                /*
                --------------------------------------------------------
                Attorney block
                --------------------------------------------------------
                */

                [
                    'type' => 'textbox',
                    'field' => 'attorney_header_block',
                    'align' => 'left',
                    'margin_bottom' => 10,
                ],

                /*
                --------------------------------------------------------
                Caption
                --------------------------------------------------------
                */

                [
                    'type' => 'caption_grid',
                    'field' => 'caption_grid',
                    'margin_bottom' => 10,
                ],

                /*
                --------------------------------------------------------
                Case number
                --------------------------------------------------------
                */

                [
                    'type' => 'paragraph',
                    'text' => 'Case No.: {{case_number_line}}',
                    'margin_bottom' => 12,
                ],

                /*
                --------------------------------------------------------
                Title
                --------------------------------------------------------
                */

                [
                    'type' => 'textbox',
                    'text' => 'CERTIFICATE REGARDING COMPULSORY ARBITRATION',
                    'align' => 'center',
                    'style' => [
                        'font_style' => 'B',
                        'font_size'  => 14,
                    ],
                    'margin_bottom' => 16,
                ],

                /*
                --------------------------------------------------------
                Certification body
                --------------------------------------------------------
                */

                [
                    'type' => 'paragraph_flow',
                    'field' => 'certificate_body',
                    'margin_bottom' => 20,
                ],

                /*
                --------------------------------------------------------
                Signature / submission block
                --------------------------------------------------------
                */

                [
                    'type' => 'paragraph',
                    'field' => 'respectfully_submitted_line',
                    'margin_bottom' => 8,
                ],

                [
                    'type' => 'paragraph',
                    'field' => 'firm_name',
                    'margin_bottom' => 18,
                ],

                [
                    'type' => 'textbox',
                    'field' => 'signature_block',
                    'align' => 'left',
                    'margin_bottom' => 24,
                ],

                /*
                --------------------------------------------------------
                Filing block
                --------------------------------------------------------
                */

                [
                    'type' => 'textbox',
                    'field' => 'filing_block',
                    'align' => 'left',
                ],
            ],
        ];
    }
}