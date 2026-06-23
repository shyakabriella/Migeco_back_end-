<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserCreatedResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $temporaryPassword,
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
        return (new MailMessage)
            ->subject('Your MIGECO DMS account login details')
            ->view('emails.users.created-reset-password', [
                'user' => $notifiable,
                'createdBy' => $this->createdBy,
                'temporaryPassword' => $this->temporaryPassword,
                'loginUrl' => $this->loginUrl(),
            ]);
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'email' => $notifiable->email,
            'login_url' => $this->loginUrl(),
            'created_by' => $this->createdBy?->id,
        ];
    }

    private function loginUrl(): string
    {
        $frontendUrl = rtrim(
            config('app.frontend_url')
                ?: env('FRONTEND_URL')
                ?: config('app.url'),
            '/'
        );

        return $frontendUrl . '/login';
    }
}