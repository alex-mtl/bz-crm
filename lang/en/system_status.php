<?php

return [
    'title' => 'System status',
    'checks' => 'Checks',
    'refresh' => 'Refresh',
    'ok' => 'OK',
    'fail' => 'Failed',
    'failed_jobs' => 'Failed queue jobs',
    'antivirus' => 'Antivirus protection of files',
    'antivirus_hint' => 'Checks the files uploaded to posts, events and messages. While it is off, files are accepted unchecked.',
    'antivirus_on' => 'On',
    'antivirus_off' => 'Off',
    'antivirus_service' => 'Antivirus service (ClamAV)',
    'antivirus_service_down' => 'Not answering',
    'antivirus_enable' => 'Turn on',
    'antivirus_disable' => 'Turn off',
    'antivirus_confirm_disable' => 'Files will be accepted without an antivirus check. Turn the protection off?',
    'antivirus_enabled' => 'Antivirus protection is on.',
    'antivirus_disabled' => 'Antivirus protection is off.',
    'check' => [
        'database' => 'Database',
        'redis' => 'Redis',
        'cache' => 'Cache',
        'storage' => 'File storage',
    ],
];
