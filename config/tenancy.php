<?php

return [
    // Local dev: map production names (u713846254_acc_rasmi) to imported copies (nid_rasmi)
    // and use local credentials instead of those stored in the accounts table.
    'local_remap' => env('TENANCY_LOCAL_REMAP', false),
    'local_host' => env('TENANCY_LOCAL_HOST', '127.0.0.1'),
    'local_username' => env('TENANCY_LOCAL_USERNAME', 'root'),
    'local_password' => env('TENANCY_LOCAL_PASSWORD', ''),
    'local_prefix' => 'nid_',
    'production_prefix' => 'u713846254_',
];
