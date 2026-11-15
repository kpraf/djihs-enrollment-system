<?php

return [
    // Shown on reports and school forms.
    'name' => 'Don Jose Integrated High School',
    'school_id' => '301239',
    'region' => 'REGION IV-A',
    'division' => 'SANTA ROSA CITY',

    // First administrator, created only when there are no users yet.
    'admin' => [
        'username' => env('ADMIN_USERNAME', 'admin'),
        'password' => env('ADMIN_PASSWORD'),
    ],
];
