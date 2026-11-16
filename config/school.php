<?php

return [
    // Shown on reports and school forms.
    'name' => 'Don Jose Integrated High School',
    'school_id' => '301239',
    'region' => 'REGION IV-A',
    'division' => 'SANTA ROSA CITY',

    // Demo accounts (password "password") and fake learners for trying the
    // system out. Always on locally; on a live site only with DEMO_DATA=true.
    // Turn it off before real learner data goes in: the demo accounts are
    // then switched off on the next deploy.
    'demo' => (bool) env('DEMO_DATA', false),

    // First administrator, created only when there are no users yet.
    'admin' => [
        'username' => env('ADMIN_USERNAME', 'admin'),
        'password' => env('ADMIN_PASSWORD'),
    ],
];
