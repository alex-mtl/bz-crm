<?php

return [
    'errors' => [
        'move_into_own_subtree' => 'A unit cannot be moved into its own branch.',
        'archive_not_empty' => 'The unit still has members or active child units.',
        'unknown_territory' => 'Unknown territory.',
        'already_member' => 'The person already belongs to a unit — use a transfer.',
        'not_member' => 'The person does not belong to any unit.',
        'reason_required' => 'Please give a reason.',
        'transfer_head' => 'The head cannot be transferred — appoint another head first.',
        'manager_outside_unit' => 'Only someone from the same unit or its child units can be the manager.',
        'manager_not_active' => 'The person has no active account.',
        'manager_cycle' => 'A subordinate cannot become their manager\'s manager.',
        'head_not_member' => 'The head must be a member of this unit.',
    ],
];
