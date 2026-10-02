<?php

return [
    /*
     * Threads (ФО §6.6.3, ТЗ §20): replies form a tree of any depth "subject to configurable limits".
     * A reply deeper than this stays on the last level.
     */
    'max_thread_depth' => (int) env('MESSAGING_MAX_THREAD_DEPTH', 12),

    'max_attachments' => 10,
];
