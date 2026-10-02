<?php

return [
    /*
     * Antivirus check of uploaded files (ФО §6.6.4, ADR-012): "clamav" — through the clamd daemon; "none" — no
     * check (local development, tests). With "clamav" a file is not accepted while the daemon does not answer.
     */
    'scanner' => env('ATTACHMENT_SCANNER', 'none'),

    'clamav' => [
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (float) env('CLAMAV_TIMEOUT', 10),
    ],
];
