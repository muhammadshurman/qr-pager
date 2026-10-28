<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // Turned off at Task 2. The skeleton's `true` makes
            // FilesystemServiceProvider register TWO public routes per served disk --
            // GET storage/{path} and PUT storage/{path} -- outside /v1, which the gate
            // forbids. The PUT writes request content straight to this disk; it is
            // gated by a signed relative URL, so it is not an open upload, but the
            // signature is the app key and nothing in this project uses the route at
            // all. S3 is the locked object storage (ADR-002) and uploads are Task 7's.
            // Found with `php artisan route:list`, which is the only place it shows.
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            // filter_var and not the skeleton's bare env(), because the AWS SDK's
            // ClientResolver type-checks this one and nothing upstream guarantees a bool.
            // Illuminate\Support\Env converts the STRINGS 'true' and 'false' and leaves
            // everything else alone, and two of the three places that set this value do not
            // go through a .env file at all: PHPUnit normalises <env value="true"/> to the
            // string "1" (measured 2026-10-28 -- `Invalid configuration value provided for
            // "use_path_style_endpoint". Expected bool, but got string(1) "1"`, thrown before
            // a single object moved), and a container env var is always a string. ECS task
            // definitions are the production form of exactly that.
            'use_path_style_endpoint' => filter_var(
                env('AWS_USE_PATH_STYLE_ENDPOINT', false),
                FILTER_VALIDATE_BOOLEAN
            ),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
