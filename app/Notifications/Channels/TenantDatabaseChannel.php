<?php

namespace App\Notifications\Channels;

use App\Notifications\Contracts\OrganizationNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Database channel that also stores organization_id, so the in-app inbox can
 * show only notifications belonging to the organization being viewed.
 */
class TenantDatabaseChannel extends DatabaseChannel
{
    /**
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification)
    {
        return [
            ...parent::buildPayload($notifiable, $notification),
            'organization_id' => $notification instanceof OrganizationNotification
                ? $notification->organizationId()
                : null,
        ];
    }
}
