<?php

namespace App\Notifications;

use App\Models\OrganizationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public OrganizationInvitation $invitation,
        public string $token,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = $this->invitation->organization;

        return (new MailMessage)
            ->subject('Invitation to join '.$organization->name.' on Nexora')
            ->greeting('Hello,')
            ->line('You have been invited to join '.$organization->name.' as '.$this->invitation->role.'.')
            ->line('This invitation expires on '.$this->invitation->expires_at->toDayDateTimeString().'.')
            ->line('Invitation token: '.$this->token)
            ->action('Review invitation', url('/auth/invitations/preview/'.$this->token))
            ->line('Sign in or create an account with '.$this->invitation->email.' before accepting.')
            ->line('If you were not expecting this invitation, you can ignore this message.');
    }
}
