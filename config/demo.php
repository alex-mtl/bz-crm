<?php

return [
    // Environments where the demo world may be seeded and the quick sign-in block is shown (Д-2, Д-5).
    // Production is never in this list; the seeder and the quick sign-in both check it again.
    'environments' => ['local', 'demo', 'testing'],

    // Shared password of all demo personas. Seeding fails if it is empty.
    'password' => env('DEMO_USER_PASSWORD'),

    // Base32 TOTP secret of the demo persona with two-factor authentication.
    'totp_secret' => env('DEMO_TOTP_SECRET'),

    'email_domain' => 'demo.bz-crm.test',
];
