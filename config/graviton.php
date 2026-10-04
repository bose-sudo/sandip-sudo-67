<?php

return [
    'base_url'  => env('GRAVITON_BASE_URL', 'https://uat.kugelblitz.xyz:8443/fineract-provider/api/v1'),
    'tenant_id'     => env('GRAVITON_TENANT_ID', 'akme'),
    'auth_endpoint' => env('GRAVITON_AUTH_ENDPOINT', '/authentication/generate-access-token'),
    'username'      => env('GRAVITON_USERNAME'),
    'password'      => env('GRAVITON_PASSWORD'),

    'file_base_url' => env('GRAVITON_FILE_BASE_URL', 'http://13.205.48.55'),
    // 'file_secret'   => env('GRAVITON_FILE_SECRET', ''),

    'defaults' => [
        // .env theke
        'office_id'         => env('GRAVITON_OFFICE_ID', 1),
        'internal_sales_id' => env('GRAVITON_INTERNAL_SALES_ID', 158),
        'product_id'        => env('GRAVITON_DEFAULT_PRODUCT_ID', 36),
        'credit_bureau_type_id' => env('GRAVITON_CREDIT_BUREAU_TYPE_ID', 1),

        // ═══════════════════════════════════════════════════
        // CONSTANTS (all time same)
        // ═══════════════════════════════════════════════════
        'legal_form_id'                     => 1,
        'loan_type'                         => 'individual',
        'active'                            => true,
        'date_format'                       => 'dd MMMM yyyy',
        'locale'                            => 'en',
        'amortization_type'                 => 1,
        'interest_calc_period_type'         => 1,
        'repayment_frequency_type'          => 2,
        'term_frequency_type'               => 2,
        'interest_type'                     => 0,
        'repayment_every'                   => 1,
        'interest_upfront'                  => false,
        'is_broken_period_interest_upfront' => false,
    ],
];