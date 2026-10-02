<?php

return [
    'errors' => [
        'dialog_with_self' => 'You cannot start a dialog with yourself.',
        'no_account' => 'This person has no active account.',
        'direct_not_allowed' => 'You may not write to this person first.',
        'title_required' => 'Enter the title of the chat.',
        'cannot_remove' => 'This member cannot be removed.',
        'cannot_leave' => 'This chat cannot be left.',
        'owner_cannot_leave' => 'The owner cannot leave the chat — hand the ownership over first.',
        'invalid_role' => 'Unknown role in the chat.',
        'role_not_allowed' => 'You cannot assign this role.',
        'link_unusable' => 'The link is not valid.',
        'invalid_notify' => 'Notifications are "all", "mentions only" or "mute".',
        'not_a_member' => 'This person is not in the chat.',
        'empty_message' => 'The message is empty.',
        'poll_needs_question' => 'A poll needs a question and at least two options.',
        'too_many_files' => 'Too many files — at most :limit.',
        'mention_all_not_allowed' => 'You may not mention all members (@all).',
        'send_in_past' => 'The sending time must be in the future.',
        'invalid_reaction' => 'This reaction cannot be chosen.',
        'invalid_poll_option' => 'The poll has no such option.',
        'message_of_other_chat' => 'The message belongs to another chat or is not available.',
        'reason_required' => 'Give a reason.',
        'invalid_retention' => 'The retention term is from 1 to 600 months.',
    ],
    'notices' => [
        'message' => ':name: new message — ":chat"',
        'mention' => ':name mentioned you — ":chat"',
        'open' => 'Open',
    ],
    'system' => [
        'task_created' => 'A task was created from the discussion: ":title"',
    ],
    'ui' => [
        'dialog' => 'Dialog',
        'discussion' => 'Discussion',
        'chat' => 'Chat',
    ],
];
