<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class WaitlistAvailableNotification extends BookingNotification
{
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A slot you were waiting for is available')
            ->greeting("Hi {$notifiable->name},")
            ->line("The slot you were waiting for at {$this->payload['start_at']} just opened up.")
            ->line('Book it soon before someone else does.');
    }

    public function toTelegram(mixed $notifiable): string
    {
        return "🎉 A slot you were waiting for at {$this->payload['start_at']} just opened up!";
    }
}
