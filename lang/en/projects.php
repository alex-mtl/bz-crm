<?php

return [
    'errors' => [
        'invalid_status' => 'Unknown status.',
        'previous_phase_open' => 'The previous phase is not finished.',
        'dependency_invalid' => 'Invalid phase dependency.',
        'invalid_resource_kind' => 'Unknown resource kind.',
        'member_not_active_user' => 'Only a person with an active account can be a member.',
    ],
    'statuses' => [
        'draft' => 'Draft',
        'not_started' => 'Not started',
        'in_progress' => 'Active',
        'on_hold' => 'On hold',
        'completed' => 'Completed',
        'canceled' => 'Canceled',
    ],
    'phase_statuses' => [
        'not_started' => 'Not started',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'canceled' => 'Canceled',
    ],
    'health' => [
        'on_track' => 'On track',
        'at_risk' => 'At risk',
        'off_track' => 'Off track',
    ],
    'visibility' => [
        'members' => 'Members and management only',
        'organization' => 'Whole organization',
    ],
    'structure' => [
        'phases' => 'With phases',
        'flat' => 'Without phases',
    ],
    'member_roles' => [
        'manager' => 'Project manager',
        'member' => 'Member',
        'observer' => 'Observer',
    ],
];
