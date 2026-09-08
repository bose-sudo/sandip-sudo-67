<?php
// config/easebuzz.php

return [
    'merchant_key'      => env('EASEBUZZ_MERCHANT_KEY'),
    'merchant_salt'     => env('EASEBUZZ_MERCHANT_SALT'),
    'base_url'          => env('EASEBUZZ_BASE_URL', 'https://wire.easebuzz.in/'),
    'merchant_key_uat'  => env('EASEBUZZ_UAT_MERCHANT_KEY'),
    'merchant_salt_uat' => env('EASEBUZZ_UAT_MERCHANT_SALT'),
    'sub_merchant_akme' => env('EASEBUZZ_SUB_MERCHANT_AKME'),
    'base_url_uat'      => env('EASEBUZZ_UAT_BASE_URL', 'https://sandboxapi.easebuzz.in/'),
    'timeout'           => (int) env('EASEBUZZ_TIMEOUT', 30),
];