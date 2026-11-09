<?php

namespace App\Notifications;

use App\Models\Alert;
use App\Models\AlertExecution;
use App\Notifications\Channels\TenantDatabaseChannel;
use App\Notifications\Contracts\OrganizationNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AlertTriggered extends Notification implements OrganizationNotification
{
    public function __construct(
        private readonly Alert $alert,
        private readonly AlertExecution $execution,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [TenantDatabaseChannel::class, 'mail'];
    }

    public function organizationId(): ?int
    {
        return $this->alert->organization_id;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Alert: {$this->alert->name}")
            ->line((string) $this->execution->message)
            ->action('View alert', $this->url())
            ->line('You will not be notified again until the condition clears and recurs.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'alert_triggered',
            'title' => "Alert: {$this->alert->name}",
            'body' => (string) $this->execution->message,
            'url' => $this->url(),
            'alert_id' => $this->alert->id,
        ];
    }

    private function url(): string
    {
        return route('alerts.show', ['organization' => $this->alert->organization()->value('slug'), 'alert' => $this->alert->id]);
    }
}
