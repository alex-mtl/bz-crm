<?php

return [
    'title' => 'Event journal',
    'categories' => [
        'security' => 'Security',
        'access' => 'Access',
        'data' => 'Data change',
        'business' => 'Business event',
        'admin' => 'Administration',
        'integration' => 'Integrations',
    ],
    'severities' => [
        'info' => 'Info',
        'notice' => 'Notice',
        'warning' => 'Warning',
        'critical' => 'Critical',
    ],
    'actor_types' => [
        'user' => 'User',
        'guest' => 'Guest',
        'system' => 'System',
        'job' => 'Background job',
        'automation' => 'Automation',
        'ai' => 'AI',
    ],
    'acting_as' => [
        'own' => 'Own rights',
        'delegation' => 'By delegation',
        'impersonation' => 'Impersonation',
    ],
    'events' => [
        'audit' => [
            'viewed' => 'Event journal viewed',
            'exported' => 'Event journal exported',
            'settings' => [
                'updated' => 'Journal settings changed',
            ],
            'retention' => [
                'purged' => 'Expired entries purged',
            ],
        ],
        'access' => [
            'role' => [
                'created' => 'Role created',
                'updated' => 'Role changed',
                'permissions_changed' => 'Role permissions changed',
                'assigned' => 'Role assigned',
                'revoked' => 'Role revoked',
                'delegated' => 'Role delegated temporarily',
                'expired' => 'Role revoked automatically on expiry',
                'field_rules_changed' => 'Role rights on field groups changed',
            ],
            'reserved_permission' => [
                'granted' => 'Reserved permission granted',
            ],
            'escalation' => [
                'denied' => 'Denied: attempt to grant rights beyond own',
            ],
            'system_roles' => [
                'synced' => 'System roles synchronized',
            ],
            'territory' => [
                'expired' => 'Territory revoked automatically on expiry',
                'grant_denied' => 'Refused: territory grant',
                'granted' => 'Additional territory granted',
                'revoked' => 'Additional territory revoked',
            ],
            'simulated' => 'Permission simulator opened for a user',
        ],
        'catalogs' => [
            'item' => [
                'created' => 'Catalog item created',
                'updated' => 'Catalog item changed',
                'deactivated' => 'Catalog item deactivated',
                'reactivated' => 'Catalog item reactivated',
                'merged' => 'Catalog items merged',
            ],
            'proposal' => [
                'submitted' => 'Catalog proposal submitted',
                'approved' => 'Catalog proposal approved',
                'rejected' => 'Catalog proposal rejected',
            ],
            'reference_data' => [
                'imported' => 'Reference data imported',
            ],
        ],
        'identity' => [
            'registration' => [
                'submitted' => 'Registration application submitted',
            ],
            'email' => [
                'verified' => 'E-mail address confirmed',
            ],
            'profile' => [
                'updated' => 'Own profile updated',
            ],
            'password' => [
                'reset_completed' => 'Password reset via link',
                'changed' => 'Password changed',
                'reset_forced' => 'Password reset forced',
            ],
            'provider' => [
                'linked' => 'Sign-in method connected',
                'unlinked' => 'Sign-in method disconnected',
            ],
            'user' => [
                'deactivated' => 'User deactivated',
                'reactivated' => 'User reactivated',
            ],
            'two_factor' => [
                'enabled' => 'Two-factor authentication enabled',
                'disabled' => 'Two-factor authentication disabled',
                'reset' => 'Two-factor authentication reset',
            ],
            'accounts' => [
                'linked' => 'Accounts linked to one person card',
            ],
            'auth_provider' => [
                'saved' => 'Sign-in provider settings changed',
            ],
            'impersonation' => [
                'started' => 'Impersonation started',
                'ended' => 'Impersonation ended',
            ],
        ],
        'auth' => [
            'login' => [
                'succeeded' => 'Successful sign-in',
                'failed' => 'Failed sign-in attempt',
                'failed_series' => 'Series of failed sign-in attempts',
                'new_device' => 'Sign-in from a new device',
            ],
            'demo_login' => 'Demo sign-in as a persona',
            'logout' => 'Sign-out',
            'sessions' => [
                'terminated' => 'All user sessions terminated',
            ],
        ],
        'admission' => [
            'invitation' => [
                'sent' => 'Invitation sent',
                'accepted' => 'Invitation accepted',
                'revoked' => 'Invitation revoked',
            ],
            'application' => [
                'approved' => 'Application approved',
                'rejected' => 'Application rejected',
            ],
        ],
        'people' => [
            'candidate' => [
                'registered' => 'Candidate registered',
            ],
            'link_hint' => [
                'created' => 'Hint: the person may have two accounts',
                'dismissed' => 'Two-accounts hint dismissed',
            ],
            'exported' => 'People exported',
            'person' => [
                'created' => 'Person card created',
                'updated' => 'Person card updated',
                'archived' => 'Person card archived',
                'restored' => 'Person card restored',
            ],
        ],
        'geo' => [
            'territories' => [
                'imported' => 'Territory reference loaded',
            ],
            'territory' => [
                'created' => 'Territory added',
                'responsible_assigned' => 'Territory responsible assigned',
                'responsible_removed' => 'Territory responsible removed',
                'updated' => 'Territory changed',
            ],
        ],
        'org' => [
            'manager' => [
                'changed' => 'Direct manager changed',
            ],
            'membership' => [
                'created' => 'Person placed in a unit',
                'position_changed' => 'Position changed',
                'transferred' => 'Transfer to another unit',
            ],
            'unit' => [
                'archived' => 'Unit archived',
                'created' => 'Unit created',
                'head_changed' => 'Unit head changed',
                'moved' => 'Unit moved',
                'territories_changed' => 'Unit territories changed',
                'updated' => 'Unit changed',
            ],
        ],
        'profile' => [
            'open' => [
                'updated' => 'Open profile changed',
            ],
            'layer' => [
                'viewed' => 'Confidential layer viewed',
                'updated' => 'Confidential layer changed',
            ],
            'settings' => [
                'updated' => 'Profile settings changed',
            ],
        ],
        'notes360' => [
            'created' => '"360" note created',
            'updated' => '"360" note changed',
            'deleted' => '"360" note deleted',
        ],
        'tasks' => [
            'task' => [
                'created' => 'Task created',
                'updated' => 'Task changed',
                'people_changed' => 'Assignees or watchers changed',
                'deleted' => 'Task deleted (marked)',
                'recurred' => 'Next occurrence of a task created',
                'escalated' => 'Task escalated to management',
            ],
            'status' => [
                'changed' => 'Task status changed',
            ],
            'checklist' => [
                'changed' => 'Task checklist changed',
            ],
            'dependency' => [
                'added' => 'Task dependency added',
            ],
            'time' => [
                'logged' => 'Time logged',
            ],
            'reassignment_suggested' => 'Task reassignment suggested',
            'workflow' => [
                'changed' => 'Status transitions changed',
            ],
        ],
        'projects' => [
            'project' => [
                'created' => 'Project created',
                'updated' => 'Project changed',
                'status_changed' => 'Project status changed',
                'archived' => 'Project archived',
            ],
            'members' => [
                'changed' => 'Project members changed',
            ],
            'phase' => [
                'changed' => 'Project phase changed',
            ],
            'budget' => [
                'changed' => 'Budget or resources changed',
            ],
            'template' => [
                'saved' => 'Project template saved',
            ],
        ],
        'custom_fields' => [
            'definition' => [
                'saved' => 'Custom field saved',
            ],
        ],
        'crm' => [
            'interaction' => [
                'recorded' => 'Interaction recorded',
            ],
            'relation' => [
                'added' => 'Relation between people added',
                'removed' => 'Relation between people removed',
            ],
            'duplicate' => [
                'dismissed' => 'Possible duplicate dismissed',
            ],
            'people' => [
                'merged' => 'Person cards merged',
            ],
            'pipeline' => [
                'created' => 'Pipeline created',
                'updated' => 'Pipeline updated',
            ],
            'lead' => [
                'created' => 'Lead created',
                'stage_changed' => 'Lead moved',
                'assigned' => 'Lead responsible assigned',
                'unfrozen' => 'Lead returned to work automatically',
            ],
            'leads' => [
                'exported' => 'Leads exported',
            ],
            'appeal' => [
                'registered' => 'Appeal registered',
                'assigned' => 'Appeal responsible assigned',
                'status_changed' => 'Appeal status changed',
                'task_linked' => 'Task linked to an appeal',
                'prioritized' => 'Appeal priority changed',
            ],
            'appeals' => [
                'exported' => 'Appeals exported',
            ],
            'segment' => [
                'created' => 'Segment created',
                'updated' => 'Segment updated',
                'deleted' => 'Segment deleted',
                'tasks_created' => 'Tasks created for a segment',
            ],
            'import' => [
                'uploaded' => 'Import file uploaded',
                'completed' => 'Import completed',
                'failed' => 'Import failed',
                'rolled_back' => 'Import rolled back',
            ],
        ],
        'groups' => [
            'group' => [
                'created' => 'Group created',
                'updated' => 'Group updated',
                'archived' => 'Group archived',
            ],
            'member' => [
                'joined' => 'Member joined a group',
                'left' => 'Member left a group',
                'role_changed' => 'Role of a group member changed',
            ],
            'request' => [
                'submitted' => 'Request to join a group submitted',
                'rejected' => 'Request to join a group rejected',
            ],
            'invitation' => [
                'sent' => 'Invitation to a group sent',
                'bulk_sent' => 'Bulk invitation to a group',
                'link_created' => 'Invitation link to a group created',
                'declined' => 'Invitation to a group declined',
                'revoked' => 'Invitation to a group revoked',
            ],
        ],
        'social' => [
            'post' => [
                'created' => 'Post created',
                'published' => 'Post published',
                'updated' => 'Post updated',
                'audience_changed' => 'Audience of a post changed',
                'deleted' => 'Post deleted by its author',
                'pinned' => 'Post pinned',
                'unpinned' => 'Post unpinned',
            ],
            'comment' => [
                'created' => 'Comment added',
                'deleted' => 'Comment deleted by its author',
            ],
            'report' => [
                'created' => 'Content reported',
                'dismissed' => 'Report dismissed',
            ],
            'content' => [
                'hidden' => 'Content hidden by a moderator',
                'restored' => 'Content restored',
            ],
            'user' => [
                'warned' => 'User warned',
                'muted' => 'User muted',
                'unmuted' => 'User unmuted',
                'mute_expired' => 'Mute expired',
            ],
            'revisions' => [
                'viewed' => 'Edit history of a post viewed',
            ],
        ],
        'events' => [
            'event' => [
                'created' => 'Event created',
                'updated' => 'Event updated',
                'cancelled' => 'Event cancelled',
            ],
            'invitation' => [
                'sent' => 'Invitation to an event sent',
                'bulk_sent' => 'Bulk invitation to an event',
                'withdrawn' => 'Invitation to an event withdrawn',
            ],
            'rsvp' => [
                'set' => 'Answer to an invitation',
            ],
            'attendance' => [
                'marked' => 'Attendance marked',
            ],
            'results' => [
                'published' => 'Results of an event published',
            ],
            'reminder' => [
                'sent' => 'Reminder of an event sent',
            ],
            'feed' => [
                'created' => 'Calendar subscription created',
                'revoked' => 'Calendar subscription revoked',
            ],
        ],
        'notifications' => [
            'announcement' => [
                'sent' => 'Announcement sent',
            ],
            'critical' => [
                'sent' => 'Critical notice sent',
                'acknowledged' => 'Reading of a critical notice confirmed',
            ],
            'defaults' => [
                'changed' => 'Notification defaults of a role changed',
            ],
        ],
        'messaging' => [
            'chat' => [
                'created' => 'Chat created',
                'archived' => 'Chat archived',
                'investigated' => 'Chat read for an investigation',
            ],
            'member' => [
                'added' => 'Member added to a chat',
                'removed' => 'Member left a chat',
                'role_changed' => 'Role of a chat member changed',
            ],
            'link' => [
                'created' => 'Invitation link to a chat created',
                'revoked' => 'Invitation link to a chat revoked',
            ],
            'message' => [
                'deleted' => 'Message deleted by a moderator of the chat',
            ],
            'attachment' => [
                'infected' => 'Attachment removed by the antivirus',
            ],
            'policy' => [
                'changed' => 'Rule of direct messages changed',
            ],
            'retention' => [
                'changed' => 'Retention term of messages changed',
                'purged' => 'Messages older than the retention term removed',
            ],
        ],
    ],
    'on_behalf_of' => 'on behalf of :name',
];
