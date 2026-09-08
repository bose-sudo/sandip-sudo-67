<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    | The fully qualified class name of your User model.
    */
    'user_model' => \App\Models\AdminUser::class,

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    | ACL data is cached aggressively for performance.
    | 'ttl' is in seconds. Default: 1 hour.
    |
    | 'use_tags' = true requires a cache driver that supports tagging (Redis, Memcached).
    | When true, a single Cache::tags(['acl'])->flush() clears ALL acl cache.
    | When false, each key is cleared individually.
    */
    'cache' => [
        'prefix'    => env('ACL_CACHE_PREFIX', 'acl'),
        'ttl'       => env('ACL_CACHE_TTL', 3600),
        'use_tags'  => env('ACL_CACHE_TAGS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Authorization Behavior
    |--------------------------------------------------------------------------
    | If a route has no permission registered in the permissions table,
    | should access be allowed by default?
    |
    | false (recommended for production) = deny unregistered routes
    | true = allow unregistered routes (good for during development)
    */
    'allow_unregistered_routes' => env('ACL_ALLOW_UNREGISTERED', false),

    /*
    |--------------------------------------------------------------------------
    | API Middleware
    |--------------------------------------------------------------------------
    | Middleware applied to ACL API routes (/api/acl/*).
    */
    'api_middleware' => ['api', 'auth:admin'],

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    | URI prefix for ACL API routes.
    */
    'api_prefix' => 'api/acl',

];
