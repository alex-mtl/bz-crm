<?php

return [
    'errors' => [
        'too_deep' => 'Nivelul maxim de subsarcini a fost atins.',
        'assignee_not_active_user' => 'Executor sau observator poate fi doar o persoană cu cont activ (Д-15).',
        'assignee_out_of_reach' => 'Nu puteți numi această persoană.',
        'needs_assignee' => 'Numiți cel puțin un executor.',
        'transition_not_allowed' => 'Această schimbare de status nu este permisă.',
        'review_required' => 'Acest tip de sarcină se închide doar prin verificare.',
        'return_to_previous' => 'Sarcina revine doar în statusul anterior.',
        'dependencies_open' => 'Mai întâi trebuie finalizate sarcinile de care depinde.',
        'previous_phase_open' => 'Etapa anterioară a proiectului nu este finalizată.',
        'requires_reason' => 'Indicați motivul.',
        'requires_comment' => 'Adăugați un comentariu.',
        'requires_date' => 'Indicați data reluării.',
        'requires_blocked_by' => 'Indicați de cine sau de ce depinde.',
        'inactive_type' => 'Acest tip de sarcină nu poate fi ales.',
        'inactive_priority' => 'Această prioritate nu poate fi aleasă.',
        'dependency_cycle' => 'Dependența ar crea un ciclu.',
        'invalid_time' => 'Timp incorect.',
        'empty_message' => 'Mesajul este gol.',
    ],
    'notices' => [
        'assigned' => 'Sunteți numit(ă) executor: :title',
        'escalated' => 'Sarcina necesită atenție (blocată sau întârziată): :title',
        'reassign' => 'Executorul a fost dezactivat — reatribuiți sarcina: :title',
        'open' => 'Deschide',
    ],
];
