<?php

/*
 * Details shown on the privacy notice. The school fills these in with its
 * own Data Protection Officer before real use; see docs/data-privacy.md.
 */
return [
    'school_name' => env('SCHOOL_NAME', 'Don Jose Integrated High School'),
    'school_address' => env('SCHOOL_ADDRESS'),

    'dpo_name' => env('PRIVACY_DPO_NAME'),
    'dpo_email' => env('PRIVACY_DPO_EMAIL'),
    'dpo_phone' => env('PRIVACY_DPO_PHONE'),

    // How long records are kept, in the words the notice should use.
    'retention' => env('PRIVACY_RETENTION', 'for as long as DepEd rules on school records require'),

    // A record viewed again by the same person within this many minutes is
    // logged once, so the audit log isn't flooded by page refreshes.
    'view_log_minutes' => (int) env('PRIVACY_VIEW_LOG_MINUTES', 30),
];
