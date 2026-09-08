<?php

declare(strict_types=1);

return [
    'otp_ttl_minutes' => 10,
    'token_ttl_hours' => 24,
    'min_distance_km' => 0.05,

    'inspector_app_link' => 'https://play.google.com/store/apps/details?id=com.finayo_inspector',

    'test_mobile' => '9999000000',
    'test_otp'    => '100000',

    // Per-party ceilings — tune independently later
    'max_distance_km' => [
        'borrower'     => 26,
        'co_applicant' => 26,
        'guarantor'    => 26,
    ],

    'eka_override' => [
        'applies_to'        => ['borrower', 'co_applicant', 'guarantor'],
        'states'            => ['madhya pradesh', 'bihar', 'uttar pradesh'],
        'product_id'        => 2,
        'sub_product_types' => ['EKA L5-6S', 'EKA L5-3S'],
        'max_distance_km'   => 30,
    ],

    'multi_party_sub_products' => ['EKA L5-3S', 'EKA L5-6S'],
];