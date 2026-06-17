<?php
declare(strict_types=1);

namespace App\Services\Filing\Templates;

final class ReiLawFDComplaintTemplate
{
    public static function get(): array
    {
        return [

            'template_key' => 'reilaw.superior.fd_trustee_sale.complaint',

            'page' => [
                'size' => 'letter',
                'orientation' => 'portrait',
                'margins' => [
                    'top' => 72,
                    'right' => 72,
                    'bottom' => 72,
                    'left' => 72
                ]
            ],

            'typography' => [
                'font_family' => 'times',
                'font_size' => 13,
                'line_height' => 16,
                'pleading_layout_profile' => 'superior'
            ],

            'blocks' => [

                [
                    'type' => 'caption_grid',
                    'field' => 'caption_grid',
                    'y' => 110
                ],

                [
                    'type' => 'paragraph',
                    'y' => 250,
                    'w' => 450,
                    'content' => [
                        'COMPLAINT'
                    ]
                ],

                [
                    'type' => 'paragraph_flow',
                    'y' => 300,
                    'w' => 450,
                    'field' => 'complaint_body',
                    'paragraph_spacing' => 4,
                    'widow_lines' => 2
                ],

                [
                    'type' => 'textbox',
                    'y' => 900,
                    'w' => 300,
                    'h' => 80,
                    'field' => 'signature_block'
                ]

            ]

        ];
    }
}