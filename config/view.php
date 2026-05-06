<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | Use storage_path() (not realpath()) so the path is valid even if the
    | directory does not exist yet — otherwise view:clear / view:cache can fail
    | with "View path not found" when view.compiled resolves to false.
    |
    */

    'compiled' => env('VIEW_COMPILED_PATH') ?: storage_path('framework/views'),

];
