<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

/**
 * Permission codes of the messenger (docs/security/permission-catalog.md §8). Reading a chat is never a matter of
 * a role's scope: a chat is read by its members, a discussion — by those who may read its object. Running a chat
 * (members, roles, links, deleting other people's messages) comes from the role inside the chat.
 */
final class MessagingPermissions
{
    /**
     * @return list<array{code: string, roles?: list<string>, reserved?: bool}>
     */
    public static function definitions(): array
    {
        $allButCandidate = [
            'super_admin', 'org_head', 'unit_head', 'employee', 'volunteer', 'hr', 'security', 'psychologist',
            'catalog_admin', 'inbox_operator', 'moderator',
        ];

        return [
            ['code' => 'chats.read', 'roles' => ['*']],
            ['code' => 'chats.write', 'roles' => ['*']],
            // Whom a dialog may be started with is the table of DirectMessagePolicy (Д-26), not this code.
            ['code' => 'chats.direct.create', 'roles' => ['*']],
            ['code' => 'chats.group.create', 'roles' => $allButCandidate],
            ['code' => 'chats.manage', 'roles' => ['super_admin']],
            ['code' => 'messages.delete.any', 'roles' => ['super_admin']],
            ['code' => 'messages.forward', 'roles' => ['*']],
            ['code' => 'messages.mention_all', 'roles' => ['super_admin', 'org_head', 'unit_head']],
            ['code' => 'messaging.policies.manage', 'roles' => ['super_admin']],
            // 🔒 Reading a chat without being in it — for an investigation; held by nobody until granted explicitly.
            ['code' => 'chats.read.investigation', 'roles' => [], 'reserved' => true],
        ];
    }
}
