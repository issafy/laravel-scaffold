<?php

return [
    'default_stack' => env('FRONTEND_STACK', 'blade'),
    'supports_typescript' => env('SUPPORT_TYPESCRIPT', false),

    'directories' => [
        'model' => app_path('Models'),
        'controller' => app_path('Http/Controllers'),
    ],

    'namespaces' => [
        'model' => 'App\\Models',
        'controller' => 'App\\Http\\Controllers',
    ]
];