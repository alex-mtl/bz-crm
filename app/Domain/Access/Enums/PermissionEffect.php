<?php

declare(strict_types=1);

namespace App\Domain\Access\Enums;

enum PermissionEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}
