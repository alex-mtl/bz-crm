<?php

declare(strict_types=1);

namespace App\Domain\CRM\Notifications;

use App\Domain\Notifications\Concerns\RoutesByPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * In-app notices of the CRM: a lead or an appeal assigned to you, a frozen lead that came back.
 * Stored in the format the panel reads; the text names only the object the recipient is responsible for.
 */
final class CrmNotice extends Notification implements ShouldQueue
{
    use Queueable;
    use RoutesByPreference;

    public const string LEAD_ASSIGNED = 'lead_assigned';

    public const string LEAD_UNFROZEN = 'lead_unfrozen';

    public const string APPEAL_ASSIGNED = 'appeal_assigned';

    public const string EXPORT_READY = 'export_ready';

    public function __construct(public readonly string $kind, public readonly string $title, public readonly string $url) {}

    public function category(): string
    {
        return 'crm';
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'format' => 'filament',
            'title' => __('crm.notices.'.$this->kind, ['title' => $this->title]),
            'body' => null,
            'icon' => 'heroicon-o-funnel',
            'iconColor' => 'info',
            'duration' => 'persistent',
            'actions' => [[
                'name' => 'open',
                'label' => __('crm.notices.open'),
                'url' => $this->url,
                'shouldMarkAsRead' => true,
            ]],
            'kind' => $this->kind,
        ];
    }
}
