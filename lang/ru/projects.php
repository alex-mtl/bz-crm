<?php

return [
    'errors' => [
        'invalid_status' => 'Неизвестный статус.',
        'previous_phase_open' => 'Предыдущий этап ещё не завершён.',
        'dependency_invalid' => 'Некорректная зависимость этапов.',
        'invalid_resource_kind' => 'Неизвестный вид ресурса.',
        'member_not_active_user' => 'Участником может быть только человек с активной учётной записью.',
    ],
    'statuses' => [
        'draft' => 'Черновик',
        'not_started' => 'Не начат',
        'in_progress' => 'Активен',
        'on_hold' => 'Приостановлен',
        'completed' => 'Завершён',
        'canceled' => 'Отменён',
    ],
    'phase_statuses' => [
        'not_started' => 'Не начат',
        'in_progress' => 'В работе',
        'completed' => 'Завершён',
        'canceled' => 'Отменён',
    ],
    'health' => [
        'on_track' => 'В графике',
        'at_risk' => 'Под риском',
        'off_track' => 'Вне графика',
    ],
    'visibility' => [
        'members' => 'Только участники и руководство',
        'organization' => 'Вся организация',
    ],
    'structure' => [
        'phases' => 'С этапами',
        'flat' => 'Без этапов',
    ],
    'member_roles' => [
        'manager' => 'Руководитель проекта',
        'member' => 'Участник',
        'observer' => 'Наблюдатель',
    ],
];
