<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Requirements
    |--------------------------------------------------------------------------
    |
    | This is the default Laravel server requirements, you can add as many
    | as your application require, we check if the extension is enabled
    | by looping through the array and run "extension_loaded" on it.
    |
    */
    'core' => [
        'minPhpVersion' => '8.2'
    ],

    'requirements' => [
        'openssl',
        'pdo',
        'mbstring',
        'tokenizer',
        'fileinfo',
        'curl'
    ],

    /*
    |--------------------------------------------------------------------------
    | Required PHP Functions
    |--------------------------------------------------------------------------
    |
    | These functions must not be listed in php.ini `disable_functions`.
    | The installer runs `function_exists()` on each entry below. Remove an
    | entry if your deployment genuinely does not need it.
    |
    */
    'functions' => [
        'symlink',
        'shell_exec',
        'proc_open',
        'proc_close',
        'exec',
    ],

    /*
    |--------------------------------------------------------------------------
    | Required php.ini Settings
    |--------------------------------------------------------------------------
    |
    | Key = ini directive, value = expected state.
    |   - 'On' / 'Off' (or 1/0/yes/no/true/false) → boolean equality check.
    |   - Size strings ('64M', '1G') and plain integers → MINIMUM check
    |     (current value must be >= the expected value).
    |   - Anything else → case-insensitive exact match.
    |
    */
    'ini_settings' => [
        'file_uploads'        => 'On',
        'allow_url_fopen'     => 'On',
        'post_max_size'       => '64M',
        'upload_max_filesize' => '40M',
        'max_file_uploads'    => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Folders Permissions
    |--------------------------------------------------------------------------
    |
    | This is the default Laravel folders permissions, if your application
    | requires more permissions just add them to the array list bellow.
    |
    */
    'permissions' => [
        'storage/app/'           => '775',
        'storage/framework/'     => '775',
        'storage/logs/'          => '775',
        'bootstrap/cache/'       => '775'
    ]
];
