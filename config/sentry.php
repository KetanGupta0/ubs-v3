<?php

use App\Support\Monitoring\ErrorReports;

/*
| Error tracking through Sentry (Step I). Nothing is sent until
| SENTRY_LARAVEL_DSN is set in the environment, and what is sent is the fault,
| never the person: no request body, no cookies, no query string, no IP
| address, no SQL values, and ErrorReports::beforeSend() takes addresses,
| numbers and tokens out of whatever is left. These are fixed here rather than
| read from the environment, so nobody can widen them with one line in .env.
*/
return [

    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    'release' => env('SENTRY_RELEASE'),

    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    'sample_rate' => 1.0,

    // Performance tracing stays off: the free plan's quota is for errors, and
    // shared hosting has no time to spare on each request.
    'traces_sample_rate' => null,

    'profiles_sample_rate' => null,

    'enable_logs' => false,

    'enable_metrics' => false,

    'send_default_pii' => false,

    'max_request_body_size' => 'never',

    'before_send' => [ErrorReports::class, 'beforeSend'],

    'before_breadcrumb' => [ErrorReports::class, 'beforeBreadcrumb'],

    'ignore_transactions' => ['/up', '/health'],

    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => true,
    ],

    'crons' => [
        'monitor_scheduled_tasks' => false,
        'exclude_scheduled_tasks' => [],
    ],

    'tracing' => [
        'queue_job_transactions' => false,
        'queue_jobs' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'sql_origin' => false,
        'views' => false,
        'livewire' => false,
        'http_client_requests' => false,
        'cache' => false,
        'redis_commands' => false,
        'notifications' => false,
        'missing_routes' => false,
        'continue_after_response' => false,
        'default_integrations' => false,
    ],

];
