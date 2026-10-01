<?php

return [
    'errors' => [
        'invalid_status' => 'Status necunoscut.',
        'previous_phase_open' => 'Etapa anterioară nu este finalizată.',
        'dependency_invalid' => 'Dependență incorectă între etape.',
        'invalid_resource_kind' => 'Tip de resursă necunoscut.',
        'member_not_active_user' => 'Participant poate fi doar o persoană cu cont activ.',
    ],
    'statuses' => [
        'draft' => 'Ciornă',
        'not_started' => 'Neînceput',
        'in_progress' => 'Activ',
        'on_hold' => 'Suspendat',
        'completed' => 'Finalizat',
        'canceled' => 'Anulat',
    ],
    'phase_statuses' => [
        'not_started' => 'Neînceput',
        'in_progress' => 'În lucru',
        'completed' => 'Finalizat',
        'canceled' => 'Anulat',
    ],
    'health' => [
        'on_track' => 'Conform planului',
        'at_risk' => 'Cu risc',
        'off_track' => 'În afara planului',
    ],
    'visibility' => [
        'members' => 'Doar participanții și conducerea',
        'organization' => 'Toată organizația',
    ],
    'structure' => [
        'phases' => 'Cu etape',
        'flat' => 'Fără etape',
    ],
    'member_roles' => [
        'manager' => 'Conducătorul proiectului',
        'member' => 'Participant',
        'observer' => 'Observator',
    ],
];
