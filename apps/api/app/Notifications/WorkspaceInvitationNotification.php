<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Invitation $invitation
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $acceptUrl = config('app.frontend_url').'/invitations/'.$this->invitation->token.'/accept';

        return (new MailMessage)
            ->subject('You\'ve been invited to join '.$this->invitation->workspace->name)
            ->greeting('Hello!')
            ->line('You have been invited to join the workspace "'.$this->invitation->workspace->name.'" as a '.$this->invitation->role.'.')
            ->line('This invitation was sent by '.$this->invitation->inviter->first_name.' '.$this->invitation->inviter->last_name.'.')
            ->action('Accept Invitation', $acceptUrl)
            ->line('This invitation will expire on '.$this->invitation->expires_at->format('F j, Y').'.')
            ->line('If you did not expect this invitation, you can ignore this email.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'workspace_id' => $this->invitation->workspace_id,
            'workspace_name' => $this->invitation->workspace->name,
        ];
    }
}
