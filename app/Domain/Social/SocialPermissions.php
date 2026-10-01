<?php

declare(strict_types=1);

namespace App\Domain\Social;

/**
 * Permission codes of the social network (docs/security/permission-catalog.md §6).
 * Reading follows the visibility of each post (PostVisibility), not the scope of the role; a candidate reads
 * only what is explicitly opened to them ("related").
 */
final class SocialPermissions
{
    private const array STAFF = [
        'super_admin', 'org_head', 'unit_head', 'employee', 'volunteer', 'hr', 'security',
        'psychologist', 'catalog_admin', 'inbox_operator', 'moderator',
    ];

    /**
     * @return list<array{code: string, roles?: list<string>, reserved?: bool}>
     */
    public static function definitions(): array
    {
        $everyone = [...self::STAFF, 'candidate'];

        return [
            ['code' => 'posts.read', 'roles' => [...self::STAFF, 'candidate:related']],
            ['code' => 'posts.create', 'roles' => self::STAFF],
            // "Т — only one's own territories": an employee and HR publish inside their territorial access.
            ['code' => 'posts.publish.region', 'roles' => ['super_admin', 'org_head', 'unit_head', 'employee:related', 'hr:related']],
            ['code' => 'posts.publish.global', 'roles' => ['super_admin', 'org_head', 'hr', 'moderator']],
            ['code' => 'posts.schedule', 'roles' => self::STAFF],
            ['code' => 'posts.update', 'roles' => self::STAFF],
            ['code' => 'posts.pin', 'roles' => ['super_admin', 'org_head', 'unit_head', 'moderator']],
            ['code' => 'comments.create', 'roles' => $everyone],
            ['code' => 'reactions.add', 'roles' => $everyone],

            ['code' => 'moderation.reports.create', 'roles' => $everyone],
            ['code' => 'moderation.queue.read', 'roles' => ['super_admin', 'moderator', 'unit_head']],
            ['code' => 'moderation.hide', 'roles' => ['super_admin', 'moderator', 'unit_head']],
            ['code' => 'moderation.revisions.read', 'roles' => ['super_admin', 'moderator', 'unit_head']],
            ['code' => 'moderation.warn', 'roles' => ['super_admin', 'moderator']],
            ['code' => 'moderation.mute', 'roles' => ['super_admin', 'moderator']],
        ];
    }
}
