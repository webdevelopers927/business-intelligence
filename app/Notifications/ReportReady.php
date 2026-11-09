<?php

namespace App\Notifications;

use App\Models\ReportRun;
use App\Notifications\Channels\TenantDatabaseChannel;
use App\Notifications\Contracts\OrganizationNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A queued or scheduled report export finished (or failed). */
class ReportReady extends Notification implements OrganizationNotification
{
    public function __construct(private readonly ReportRun $run) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [TenantDatabaseChannel::class, 'mail'];
    }

    public function organizationId(): ?int
    {
        return $this->run->organization_id;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);

        return (new MailMessage)
            ->subject($data['title'])
            ->line($data['body'])
            ->when($this->run->status === 'completed', fn (MailMessage $m) => $m->action('Download report', $data['url']))
            ->line('Downloads require signing in to ORBIT.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $ok = $this->run->status === 'completed';
        $format = strtoupper($this->run->format);

        return [
            'kind' => $ok ? 'report_ready' : 'report_failed',
            'title' => $ok ? "Report ready: {$this->run->name}" : "Report failed: {$this->run->name}",
            'body' => $ok
                ? number_format((int) $this->run->row_count)." rows, {$format}".($this->run->trigger === 'schedule' ? ' (scheduled)' : '').'.'
                : 'The export could not be generated. Try again or contact an administrator.',
            'url' => route('reports.runs.download', ['organization' => $this->run->organization()->value('slug'), 'run' => $this->run->id]),
            'report_run_id' => $this->run->id,
        ];
    }
}
