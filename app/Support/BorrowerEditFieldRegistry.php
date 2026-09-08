<?php

namespace App\Support;

use App\Models\BorrowerAddress;
use App\Models\BorrowerKyc;
use App\Models\BorrowerOccupation;
use App\Models\CoapplicantKyc;
use App\Models\CoapplicantOccupation;
use App\Models\Guarantor;
use App\Models\GuarantorOccupation;

final class BorrowerEditFieldRegistry
{
    // How the value is stored / compared
    public const CAST_STRING  = 'string';
    public const CAST_INT     = 'int';
    public const CAST_DECIMAL = 'decimal';
    public const CAST_DATE    = 'date';

    // How a stored value is rendered back to reviewers
    public const DISPLAY_OCCUPATION = 'occupation';
    public const DISPLAY_AMOUNT     = 'amount';
    public const DISPLAY_DATE       = 'date';

    // What control the front end should render
    public const INPUT_TEXT     = 'text';
    public const INPUT_NUMBER   = 'number';
    public const INPUT_DATE     = 'date';
    public const INPUT_SELECT   = 'select';
    public const INPUT_TEXTAREA = 'textarea';

    // Option list sources for select inputs
    public const OPTIONS_OCCUPATION = 'occupation_types';
    public const OPTIONS_RESIDENT   = 'resident_types';
    public const OPTIONS_STATE      = 'states';

    /**
     * section => [
     *   label, flag, model (primary),
     *   fields => field_key => [label, column, rule, cast, input, options?, model?, display?]
     * ]
     *
     * Adding an editable field later = one line here. Nothing else changes.
     */
    private static function map(): array
    {
        $occupationFields = [
            'occupation_type'          => ['label' => 'Occupation Type',         'column' => 'occupation_type',          'rule' => 'nullable|integer|exists:occupations,id', 'cast' => self::CAST_INT,     'input' => self::INPUT_SELECT, 'options' => self::OPTIONS_OCCUPATION, 'display' => self::DISPLAY_OCCUPATION],
            'current_occupation'       => ['label' => 'Current Occupation',      'column' => 'current_occupation',       'rule' => 'nullable|string|max:150',                'cast' => self::CAST_STRING,  'input' => self::INPUT_TEXT],
            'monthly_earning'          => ['label' => 'Monthly Income',          'column' => 'monthly_earning',          'rule' => 'nullable|numeric|min:0',                 'cast' => self::CAST_DECIMAL, 'input' => self::INPUT_NUMBER, 'display' => self::DISPLAY_AMOUNT],
            'expected_monthly_earning' => ['label' => 'Expected Monthly Income', 'column' => 'expected_monthly_earning', 'rule' => 'nullable|numeric|min:0',                 'cast' => self::CAST_DECIMAL, 'input' => self::INPUT_NUMBER, 'display' => self::DISPLAY_AMOUNT],
        ];

        $addressFields = [
            'resident_type'     => ['label' => 'Resident Type',                   'column' => 'resident_type',     'rule' => 'nullable|string|max:60',  'cast' => self::CAST_STRING, 'input' => self::INPUT_SELECT, 'options' => self::OPTIONS_RESIDENT],
            'years_living'      => ['label' => 'Years Living in Current Address', 'column' => 'years_living',      'rule' => 'nullable|string|max:30',  'cast' => self::CAST_STRING, 'input' => self::INPUT_TEXT],
            'current_address'   => ['label' => 'Present / Current Address',       'column' => 'current_address',   'rule' => 'nullable|string|max:500', 'cast' => self::CAST_STRING, 'input' => self::INPUT_TEXTAREA],
            'permanent_address' => ['label' => 'Permanent Address',               'column' => 'permanent_address', 'rule' => 'nullable|string|max:500', 'cast' => self::CAST_STRING, 'input' => self::INPUT_TEXTAREA],
        ];

        // state / postal live on the KYC table, not the address/occupation table
        $stateFields = fn (string $model) => [
            'state'  => ['label' => 'State',   'column' => 'state',  'rule' => 'nullable|string|max:80', 'cast' => self::CAST_STRING, 'input' => self::INPUT_SELECT, 'options' => self::OPTIONS_STATE, 'model' => $model],
            'postal' => ['label' => 'Pincode', 'column' => 'postal', 'rule' => 'nullable|digits:6',      'cast' => self::CAST_STRING, 'input' => self::INPUT_NUMBER, 'model' => $model],
        ];

        return [
            'borrower_details' => [
                'label'  => 'Borrower Details',
                'flag'   => 'is_borrower_details_editable',
                'model'  => BorrowerKyc::class,
                'fields' => [
                    'name'                 => ['label' => 'Borrower Name',        'column' => 'name',                 'rule' => 'nullable|string|max:150', 'cast' => self::CAST_STRING, 'input' => self::INPUT_TEXT],
                    'father_name'          => ['label' => "Father's Name",        'column' => 'father_name',          'rule' => 'nullable|string|max:150', 'cast' => self::CAST_STRING, 'input' => self::INPUT_TEXT],
                    'spouse_name'          => ['label' => 'Spouse Name',          'column' => 'husband_name',         'rule' => 'nullable|string|max:150', 'cast' => self::CAST_STRING, 'input' => self::INPUT_TEXT],
                    'udyam_aadhaar_number' => ['label' => 'Udyam Aadhaar Number', 'column' => 'udyam_aadhaar_number', 'rule' => 'nullable|string|max:30',  'cast' => self::CAST_STRING, 'input' => self::INPUT_TEXT],
                ],
            ],

            'vehicle_info' => [
                'label'  => 'Vehicle / Policy Info',
                'flag'   => 'is_vehicle_info_editable',
                'model'  => BorrowerKyc::class,
                'fields' => [
                    'policy_number'     => ['label' => 'Policy Number',            'column' => 'policy_number',     'rule' => 'nullable|string|max:80', 'cast' => self::CAST_STRING,  'input' => self::INPUT_TEXT],
                    'policy_premium'    => ['label' => 'Policy Premium',           'column' => 'policy_premium',    'rule' => 'nullable|numeric|min:0', 'cast' => self::CAST_DECIMAL, 'input' => self::INPUT_NUMBER, 'display' => self::DISPLAY_AMOUNT],
                    'policy_start_date' => ['label' => 'Policy Start Date',        'column' => 'policy_start_date', 'rule' => 'nullable|date',          'cast' => self::CAST_DATE,    'input' => self::INPUT_DATE,   'display' => self::DISPLAY_DATE],
                    'policy_end_date'   => ['label' => 'Policy End Date',          'column' => 'policy_end_date',   'rule' => 'nullable|date',          'cast' => self::CAST_DATE,    'input' => self::INPUT_DATE,   'display' => self::DISPLAY_DATE],
                    'vehicle_reg_no'    => ['label' => 'Vehicle Registration No.', 'column' => 'vehicle_reg_no',    'rule' => 'nullable|string|max:30', 'cast' => self::CAST_STRING,  'input' => self::INPUT_TEXT],
                ],
            ],

            'borrower_occupation' => [
                'label'  => 'Borrower Occupation',
                'flag'   => 'is_borrower_occupation_editable',
                'model'  => BorrowerOccupation::class,
                'fields' => $occupationFields,
            ],

            'borrower_address' => [
                'label'  => 'Borrower Address',
                'flag'   => 'is_borrower_address_editable',
                'model'  => BorrowerAddress::class,
                'fields' => $addressFields + $stateFields(BorrowerKyc::class),
            ],

            'coapplicant_details' => [
                'label'  => 'Co-applicant Details',
                'flag'   => 'is_coapplicant_details_editable',
                'model'  => CoapplicantKyc::class,
                'fields' => [
                    'name'        => ['label' => 'Co-applicant Name', 'column' => 'name',        'rule' => 'nullable|string|max:150', 'cast' => self::CAST_STRING, 'input' => self::INPUT_TEXT],
                    'father_name' => ['label' => "Father's Name",     'column' => 'father_name', 'rule' => 'nullable|string|max:150', 'cast' => self::CAST_STRING, 'input' => self::INPUT_TEXT],
                ],
            ],

            'coapplicant_occupation' => [
                'label'  => 'Co-applicant Occupation',
                'flag'   => 'is_coapplicant_occupation_editable',
                'model'  => CoapplicantOccupation::class,
                'fields' => $occupationFields,
            ],

            'coapplicant_address' => [
                'label'  => 'Co-applicant Address',
                'flag'   => 'is_coapplicant_address_editable',
                'model'  => CoapplicantOccupation::class,
                'fields' => $addressFields + $stateFields(CoapplicantKyc::class),
            ],

            'guarantor_details' => [
                'label'  => 'Guarantor Details',
                'flag'   => 'is_guarantor_details_editable',
                'model'  => Guarantor::class,
                'fields' => [
                    'name'        => ['label' => 'Guarantor Name', 'column' => 'name',        'rule' => 'nullable|string|max:150', 'cast' => self::CAST_STRING, 'input' => self::INPUT_TEXT],
                    'father_name' => ['label' => "Father's Name",  'column' => 'father_name', 'rule' => 'nullable|string|max:150', 'cast' => self::CAST_STRING, 'input' => self::INPUT_TEXT],
                ],
            ],

            'guarantor_occupation' => [
                'label'  => 'Guarantor Occupation',
                'flag'   => 'is_guarantor_occupation_editable',
                'model'  => GuarantorOccupation::class,
                'fields' => $occupationFields,
            ],

            'guarantor_address' => [
                'label'  => 'Guarantor Address',
                'flag'   => 'is_guarantor_address_editable',
                'model'  => GuarantorOccupation::class,
                'fields' => $addressFields + $stateFields(Guarantor::class),
            ],
        ];
    }

    // =====================================================================
    // Option lists that don't come from a table
    // =====================================================================

    /** @return array<int,array{value:string,label:string}>|null */
    public static function staticOptions(string $key): ?array
    {
        $toOptions = fn (array $values) => array_map(
            fn ($v) => ['value' => $v, 'label' => $v],
            $values
        );

        return match ($key) {
            self::OPTIONS_RESIDENT => $toOptions(['Owned', 'Rented']),
            self::OPTIONS_STATE    => $toOptions(self::INDIAN_STATES),
            default                => null,
        };
    }

    /** Stored as text, not id — keep the labels stable. */
    private const INDIAN_STATES = [
        'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh',
        'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand',
        'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur',
        'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab',
        'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura',
        'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
        'Andaman and Nicobar Islands', 'Chandigarh',
        'Dadra and Nagar Haveli and Daman and Diu', 'Delhi',
        'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry',
    ];

    // =====================================================================
    // Accessors
    // =====================================================================

    public static function sections(): array
    {
        return self::map();
    }

    public static function sectionKeys(): array
    {
        return array_keys(self::map());
    }

    public static function section(string $key): ?array
    {
        return self::map()[$key] ?? null;
    }

    public static function sectionLabel(string $section): string
    {
        return self::map()[$section]['label'] ?? ucwords(str_replace('_', ' ', $section));
    }

    /**
     * @return array{label:string,column:string,rule:string,cast:string,model:class-string,input:string,options:?string,display:?string,max_length:?int}|null
     */
    public static function field(string $section, string $fieldKey): ?array
    {
        $map = self::map();
        $def = $map[$section]['fields'][$fieldKey] ?? null;

        if (!$def) {
            return null;
        }

        $def['model']      ??= $map[$section]['model'];
        $def['label']      ??= ucwords(str_replace('_', ' ', $fieldKey));
        $def['display']    ??= null;
        $def['input']      ??= self::INPUT_TEXT;
        $def['options']    ??= null;
        $def['max_length']   = self::maxLengthFromRule($def['rule']);

        return $def;
    }

    /** Pulled from the validation rule so the two can never drift. */
    private static function maxLengthFromRule(string $rule): ?int
    {
        if (preg_match('/\bmax:(\d+)/', $rule, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/\bdigits:(\d+)/', $rule, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** Distinct model classes a section writes to. @return class-string[] */
    public static function modelsFor(string $section): array
    {
        $s = self::map()[$section] ?? null;
        if (!$s) {
            return [];
        }

        $models = [$s['model']];
        foreach ($s['fields'] as $f) {
            if (isset($f['model'])) {
                $models[] = $f['model'];
            }
        }

        return array_values(array_unique($models));
    }

    /** Laravel rules for the whole create payload. */
    public static function validationRules(): array
    {
        $rules = [];

        foreach (self::map() as $sectionKey => $section) {
            $rules["sections.$sectionKey"] = 'sometimes|array';

            foreach ($section['fields'] as $fieldKey => $def) {
                $rules["sections.$sectionKey.$fieldKey"] = $def['rule'];
            }
        }

        return $rules;
    }
}