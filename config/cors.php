<?php
return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */
    
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    
    'allowed_methods' => ['*'],
    
    'allowed_origins' => [
        'http://localhost',
        // Local development
        'http://localhost:3000',
        'http://localhost:3001',
        'http://localhost:5173',  // Vite default
        'http://127.0.0.1',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:3001',
        'http://127.0.0.1:5173',
        
        // UAT/Production - Add both http AND https versions
        'http://uat.finayo.tech',
        'https://uat.finayo.tech',
        'http://13.205.48.55',
        'https://13.205.48.55',
    ],
    
    'allowed_origins_patterns' => [
        '/^http:\/\/localhost:\d+$/',  // Matches any localhost port
        '/^http:\/\/127\.0\.0\.1:\d+$/',  // Matches any 127.0.0.1 port
    ],
    
    'allowed_headers' => ['*'],
    
    'exposed_headers' => ['Authorization'],
    
    'max_age' => 86400,  // 24 hours
    
    'supports_credentials' => true,
];