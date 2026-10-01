<?php

return [
    // ТЗ §68: an export above this many rows is built in the queue; smaller ones at once.
    'export_sync_limit' => (int) env('CRM_EXPORT_SYNC_LIMIT', 1000),
];
