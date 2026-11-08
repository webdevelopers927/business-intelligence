<?php

namespace App\Notifications;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Channels\TenantDatabaseChannel;
use App\Notifications\Contracts\OrganizationNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitation extends Notification implements OrganizationNotification
{
    use Queueable;

    public function __construct(
        public readonly Organization $organization,
        public readonly User $inviter,
        public readonly Role $role,
        private readonly string $token,
        private readonly int $organizationIdForInbox,
    ) {}

    /**
     * Existing users get an in-app notification as well as the email. The
     * in-app entry belongs to no organization: the invitee isn't a member yet.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['mail', TenantDatabaseChannel::class];
    }

    public function organizationId(): ?int
    {
        return null;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You've been invited to {$this->organization->name} on ORBIT")
            ->greeting('Hello,')
            ->line("{$this->inviter->name} invited you to join {$this->organization->name} as {$this->role->label()}.")
            ->action('Review invitation', $this->url())
            ->line('This invitation expires in 7 days. If you were not expecting it, you can ignore this email.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'invitation',
            'title' => "Invitation to {$this->organization->name}",
            'body' => "{$this->inviter->name} invited you as {$this->role->label()}.",
            'url' => $this->url(),
            'source_organization_id' => $this->organizationIdForInbox,
        ];
    }

    private function url(): string
    {
        return route('invitations.show', $this->token);
    }
}
