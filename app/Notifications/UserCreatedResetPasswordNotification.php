<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class UserCreatedResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly ?User $createdBy = null
    ) {
    }

    /**
     * Get the notification delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the mail representation.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $resetUrl = $this->resetUrl($notifiable);

        return (new MailMessage)
            ->subject('Your MIGECO DMS account is ready')
            ->view('emails.users.created-reset-password', [
                'user' => $notifiable,
                'createdBy' => $this->createdBy,
                'resetUrl' => $resetUrl,
            ]);
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'email' => $notifiable->email,
            'reset_url' => $this->resetUrl($notifiable),
            'created_by' => $this->createdBy?->id,
        ];
    }

    private function resetUrl(object $notifiable): string
    {
        $frontendUrl = rtrim(
            config('app.frontend_url')
                ?: env('FRONTEND_URL')
                ?: config('app.url'),
            '/'
        );

        $query = http_build_query([
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);

        return URL::to($frontendUrl . '/resetpassword?' . $query);
    }
}