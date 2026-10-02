<?php

return [
    /*
     * Antivirus check of uploaded files (ФО §6.6.4, ADR-012). Whether files are checked is a system setting the
     * super admin switches in the panel ("Состояние системы", Д-28) — off until turned on. Here is only where
     * the clamd daemon lives.
     */
    'clamav' => [
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (float) env('CLAMAV_TIMEOUT', 10),
    ],
];
