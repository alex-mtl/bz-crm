<?php

return [
    'errors' => [
        'cancelled' => 'The event is cancelled.',
        'reason_required' => 'Give a reason.',
        'not_started' => 'The event has not started yet.',
        'results_empty' => 'Add the text of the results, a file or a photo.',
        'title_required' => 'Enter the title of the event.',
        'invalid_type' => 'There is no such event type.',
        'time_required' => 'Enter the start time.',
        'ends_before_start' => 'The end must be after the start.',
        'invalid_point' => 'The geo point is wrong: both the latitude and the longitude are needed.',
        'audience_required' => 'Choose who the event is for.',
        'territory_out_of_reach' => 'You may not hold an event for the territory ":name".',
        'invalid_visibility' => 'Unknown visibility level.',
        'invalid_recurrence' => 'The recurrence is wrong: a frequency and an end date not before the start are needed.',
        'too_many_occurrences' => 'Too many occurrences — at most :limit.',
        'bulk_too_large' => 'The filter covers more than :limit people — narrow it.',
        'already_attended' => 'This person is already marked as present.',
        'already_over' => 'The event is already over.',
        'invalid_answer' => 'The answer is "going", "interested" or "not going".',
        'invalid_feed' => 'There is no such calendar subscription.',
    ],
    'notices' => [
        'invited' => 'You are invited: ":title"',
        'changed' => 'The time or the place changed: ":title"',
        'cancelled' => 'Cancelled: ":title"',
        'reminder' => 'Reminder: ":title" — :when',
        'results' => 'Results published: ":title"',
        'open' => 'Open',
    ],
    'digest' => [
        'upcoming' => 'Upcoming events',
    ],
];
