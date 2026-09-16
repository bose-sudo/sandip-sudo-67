<?php

return [
    'base_url'      => env('GRAVITON_BASE_URL'),
    'tenant_id'     => env('GRAVITON_TENANT_ID', 'akme'),
    'auth_endpoint' => env('GRAVITON_AUTH_ENDPOINT', '/authentication/generate-access-token'),
    'username'      => env('GRAVITON_USERNAME'),
    'password'      => env('GRAVITON_PASSWORD'),

    'defaults' => [
        // .env theke
        'office_id'         => env('GRAVITON_OFFICE_ID', 102),
        'internal_sales_id' => env('GRAVITON_INTERNAL_SALES_ID', 158),

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