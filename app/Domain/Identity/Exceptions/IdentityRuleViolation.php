<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use DomainException;

final class IdentityRuleViolation extends DomainException
{
    public static function emailTaken(): self
    {
        return new self(__('identity.errors.email_taken'));
    }

    public static function providerUnavailable(): self
    {
        return new self(__('identity.errors.provider_unavailable'));
    }

    public static function identityLinkedElsewhere(): self
    {
        return new self(__('identity.errors.identity_linked_elsewhere'));
    }

    public static function lastSignInMethod(): self
    {
        return new self(__('identity.errors.last_sign_in_method'));
    }

    public static function notOnYourself(): self
    {
        return new self(__('identity.errors.not_on_yourself'));
    }

    public static function hintNotOpen(): self
    {
        return new self(__('identity.errors.hint_not_open'));
    }

    public static function applicationNotPending(): self
    {
        return new self(__('identity.errors.application_not_pending'));
    }

    public static function reasonRequired(): self
    {
        return new self(__('identity.errors.reason_required'));
    }

    public static function invitationUnusable(): self
    {
        return new self(__('identity.errors.invitation_unusable'));
    }

    public static function cardCannotGetAccess(): self
    {
        return new self(__('identity.errors.card_cannot_get_access'));
    }

    public static function impersonationTargetInactive(): self
    {
        return new self(__('identity.errors.impersonation_target_inactive'));
    }

    public static function impersonationTargetPrivileged(): self
    {
        return new self(__('identity.errors.impersonation_target_privileged'));
    }
}
