<?php

/*
 * Data retention (Д-18). Until the customer sets real periods (ФО §12 question 2, open question 13), everything
 * is kept indefinitely: records carry a declarative "retain until" date and nothing deletes them by it yet.
 */
return [
    // Written into new records and assumed for records where the date is empty.
    'default_until' => env('RETENTION_DEFAULT_UNTIL', '2030-01-01'),
];
