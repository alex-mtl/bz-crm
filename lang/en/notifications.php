<?php

return [
    'errors' => [
        'unknown_setting' => 'There is no such setting.',
        'mandatory_category' => 'These notifications are mandatory — they cannot be turned off.',
        'announcement_empty' => 'Enter the title and the text of the announcement.',
        'announcement_no_recipients' => 'There are no recipients for this announcement in your area.',
    ],
    'retracted' => 'The content of this notification is no longer available',
    'open' => 'Open',
    'acknowledge' => 'Confirm reading',
    'critical_prefix' => 'Important:',
    'digest' => [
        'title_daily' => 'Digest for :from',
        'title_weekly' => 'Digest of the week: :from — :to',
    ],
    'categories' => [
        'security' => 'Account security',
        'critical' => 'Critical notices',
        'announcements' => 'Announcements',
        'digest_daily' => 'Daily digest',
        'digest_weekly' => 'Weekly digest',
        'tasks' => 'Tasks',
        'crm' => 'CRM: leads, appeals, export',
        'groups' => 'Groups: invitations and requests',
        'social' => 'Feed: comments and new posts',
        'moderation' => 'Decisions of moderators',
        'events' => 'Events: invitations and changes',
        'event_reminders' => 'Event reminders',
    ],
    'channels' => [
        'in_app' => 'In the system',
        'email' => 'E-mail',
        'push' => 'Push',
    ],
];
