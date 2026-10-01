<?php

return [
    'errors' => [
        'cancelled' => 'Evenimentul este anulat.',
        'reason_required' => 'Indicați motivul.',
        'not_started' => 'Evenimentul nu a început încă.',
        'results_empty' => 'Adăugați textul rezultatelor, un fișier sau o fotografie.',
        'title_required' => 'Indicați denumirea evenimentului.',
        'invalid_type' => 'Nu există un astfel de tip de eveniment.',
        'time_required' => 'Indicați ora începerii.',
        'ends_before_start' => 'Sfârșitul trebuie să fie după început.',
        'invalid_point' => 'Punctul geografic este greșit: sunt necesare și latitudinea, și longitudinea.',
        'audience_required' => 'Alegeți pentru cine este evenimentul.',
        'territory_out_of_reach' => 'Nu puteți organiza un eveniment pentru teritoriul „:name”.',
        'invalid_visibility' => 'Nivel de vizibilitate necunoscut.',
        'invalid_recurrence' => 'Repetarea este indicată greșit: sunt necesare frecvența și data de sfârșit, nu mai devreme de început.',
        'too_many_occurrences' => 'Prea multe repetări — cel mult :limit.',
        'bulk_too_large' => 'Filtrul cuprinde peste :limit persoane — restrângeți-l.',
        'already_attended' => 'Această persoană este deja marcată ca prezentă.',
        'already_over' => 'Evenimentul s-a încheiat deja.',
        'invalid_answer' => 'Răspunsul este „vin”, „mă interesează” sau „nu vin”.',
        'invalid_feed' => 'Nu există o astfel de abonare la calendar.',
    ],
    'notices' => [
        'invited' => 'Ați fost invitat(ă): „:title”',
        'changed' => 'S-a schimbat ora sau locul: „:title”',
        'cancelled' => 'Anulat: „:title”',
        'reminder' => 'Memento: „:title” — :when',
        'results' => 'Rezultatele au fost publicate: „:title”',
        'open' => 'Deschide',
    ],
    'digest' => [
        'upcoming' => 'Evenimente apropiate',
    ],
];
