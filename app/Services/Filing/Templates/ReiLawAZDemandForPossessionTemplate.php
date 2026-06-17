<?php
declare(strict_types=1);

namespace App\Services\Filing\Templates;

/**
 * Arizona Demand for Possession
 *
 * A.R.S. § 12-1173.01
 *
 * Deterministic compiled template used by CompiledTemplateRenderer.
 *
 * One demand document should be rendered for each occupant target
 * identified in case_occupant_action_targets.
 *
 * An additional demand must also be generated for the fictitious
 * occupant "Occupant" at the property address.
 */
final class ReiLawAZDemandForPossessionTemplate
{
    public static function getTemplate(): array
    {
        return [

            /*
            ------------------------------------------------------------
            Template metadata
            ------------------------------------------------------------
            */

            'template_key' => 'az_demand_for_possession',

            'document_type' => 'az_demand_for_possession',

            'typography' => [
                'font_family' => 'times',
                'font_size' => 13,
                'line_height' => 16,
                'pleading_layout_profile' => 'superior',
            ],

            /*
            ------------------------------------------------------------
            Layout blocks
            ------------------------------------------------------------
            */

            'blocks' => [

                /*
                --------------------------------------------------------
                Title
                --------------------------------------------------------
                */

                [
                    'type' => 'textbox',
                    'text' => 'DEMAND FOR POSSESSION AND FIVE DAY NOTICE TO VACATE PROPERTY',
                    'align' => 'center',
                    'style' => [
                        'font_style' => 'B',
                        'font_size' => 14,
                    ],
                    'margin_bottom' => 6,
                ],

                [
                    'type' => 'textbox',
                    'text' => 'A.R.S. § 12-1173.01',
                    'align' => 'center',
                    'margin_bottom' => 10,
                ],

                /*
                --------------------------------------------------------
                Header fields
                --------------------------------------------------------
                */

                [
                    'type' => 'paragraph',
                    'field' => 'demand_date',
                ],

                [
                    'type' => 'paragraph',
                    'field' => 'occupant_name',
                ],

                [
                    'type' => 'paragraph',
                    'field' => 'occupant_address',
                ],

                /*
                --------------------------------------------------------
                Certified Mail Section
                --------------------------------------------------------
                */

                /*
                Spacer BEFORE line — only when number is NULL
                */

                [
                    'type' => 'textbox',
                    'text' => ' ',
                    'condition_not_field' => 'certified_mail_tracking_number',
                    'margin_bottom' => 12,
                ],

                /*
                Tracking number present
                */

                [
                    'type' => 'paragraph',
                    'text' =>
                        'Certified Mail No. {{certified_mail_tracking_number}}',
                    'condition_field' => 'certified_mail_tracking_number',
                    'margin_bottom' => 6,
                ],

                /*
                Tracking number not yet assigned
                */

                [
                    'type' => 'paragraph',
                    'text' =>
                        'Certified Mail No.',
                    'condition_not_field' => 'certified_mail_tracking_number',
                    'margin_bottom' => 6,
                ],

                /*
                Spacer AFTER line — only when number is NULL
                */

                [
                    'type' => 'textbox',
                    'text' => ' ',
                    'condition_not_field' => 'certified_mail_tracking_number',
                    'margin_bottom' => 12,
                ],

                /*
                --------------------------------------------------------
                Property possession statement
                --------------------------------------------------------
                */

                [
                    'type' => 'paragraph',
                    'text' =>
                        'and all tenants, subtenants, and others in possession of the property located at {{property_address}}.',
                    'margin_bottom' => 10,
                ],

                /*
                --------------------------------------------------------
                Trustee sale paragraph
                --------------------------------------------------------
                */

                [
                    'type' => 'paragraph',
                    'text' =>
                        'The above-referenced real property ("Property") was sold at a trustee sale on {{trustee_sale_date}} in accordance with the laws of the State of Arizona under a power of sale granted in a deed of trust recorded on {{deed_of_trust_date}} at {{county_name}} County Recorder Docket No. {{trustee_sale_recording_number}}. {{owner_name}} purchased the Property at the sale.',
                    'margin_bottom' => 6,
                ],

                /*
                --------------------------------------------------------
                Ownership paragraph — CONDITIONAL
                --------------------------------------------------------
                */

                /*
                State B — deed recorded
                */

                [
                    'type' => 'paragraph',
                    'text' =>
                        "{{owner_name}} is the new owner of the Property under a trustee's deed recorded at {{county_name}} County Recorder Docket No. {{trustee_deed_recording_number}} on {{trustee_deed_recording_date}}.",
                    'condition_field' => 'trustee_deed_recording_number',
                    'margin_bottom' => 6,
                ],

                /*
                State A — deed not yet recorded
                */

                [
                    'type' => 'paragraph',
                    'text' =>
                        '{{owner_name}} is the new owner of the Property.',
                    'condition_not_field' => 'trustee_deed_recording_number',
                    'margin_bottom' => 6,
                ],

                /*
                --------------------------------------------------------
                Demand body
                --------------------------------------------------------
                */

                [
                    'type' => 'paragraph_flow',
                    'field' => 'demand_body',
                ],

                /*
                --------------------------------------------------------
                SCRA notice
                --------------------------------------------------------
                */

                [
                    'type' => 'paragraph',
                    'text' =>
                        'ATTENTION! Are you an active service member of the armed forces, or the dependent of one? If so, then you MAY be afforded additional rights under the SERVICEMEMBERS CIVIL RELIEF ACT (SCRA). Please contact our office at {{owner_phone_number}} immediately to determine if the protections of SCRA apply to you. ***Be prepared to provide proof of military service. ***',
                    'margin_top' => 6,
                ],

                [
                    'type' => 'paragraph',
                    'text' =>
                        'For information regarding this notice, please call {{owner_phone_number}}.',
                    'margin_bottom' => 20,
                ],

                /*
                --------------------------------------------------------
                Signature
                --------------------------------------------------------
                */

                [
                    'type' => 'textbox',
                    'text' => '/s/',
                    'align' => 'left',
                    'margin_bottom' => 4,
                ],

                [
                    'type' => 'textbox',
                    'field' => 'owner_name',
                    'align' => 'left',
                    'style' => [
                        'font_style' => 'B'
                    ],
                ],

            ],
        ];
    }
}