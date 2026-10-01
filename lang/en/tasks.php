<?php

return [
    'errors' => [
        'too_deep' => 'Maximum subtask depth reached.',
        'assignee_not_active_user' => 'Only a person with an active account can be an assignee or watcher.',
        'assignee_out_of_reach' => 'You cannot assign this person.',
        'needs_assignee' => 'Assign at least one person.',
        'transition_not_allowed' => 'This status change is not allowed.',
        'review_required' => 'This task type can be closed only through review.',
        'return_to_previous' => 'The task returns only to its previous status.',
        'dependencies_open' => 'The tasks it depends on must be finished first.',
        'previous_phase_open' => 'The previous project phase is not finished.',
        'requires_reason' => 'Please give a reason.',
        'requires_comment' => 'Please add a comment.',
        'requires_date' => 'Please give the resume date.',
        'requires_blocked_by' => 'Please say whom or what it depends on.',
        'inactive_type' => 'This task type cannot be chosen.',
        'inactive_priority' => 'This priority cannot be chosen.',
        'dependency_cycle' => 'The dependency would create a cycle.',
        'invalid_time' => 'Invalid time.',
        'empty_message' => 'The message is empty.',
    ],
    'notices' => [
        'assigned' => 'You were assigned: :title',
        'escalated' => 'Task needs attention (blocked or overdue): :title',
        'reassign' => 'The assignee was deactivated — reassign the task: :title',
        'open' => 'Open',
    ],
];
