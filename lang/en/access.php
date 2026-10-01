<?php

return [
    'denied' => 'You are not allowed to perform this action.',
    'escalation' => 'You cannot grant rights you do not have: :codes.',
    'scopes' => [
        'organization' => 'Whole organization',
        'org_unit' => 'Unit (with children)',
        'territory' => 'Territory (with subtree)',
        'own_unit' => 'Own unit',
        'own_territories' => 'Own territories',
    ],
    'errors' => [
        'reason_required' => 'Please give a reason.',
        'end_date_in_past' => 'The end date is in the past.',
        'scope_invalid' => 'The scope is invalid.',
        'territory_outside_own_access' => 'You can grant only territories within your own access.',
    ],
];
