<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class BookingCancelledNotification extends BookingNotification
{
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your booking was cancelled')
            ->greeting("Hi {$notifiable->name},")
            ->line("Your booking ({$this->payload['booking_id']}) for {$this->payload['start_at']} has been cancelled.");
    }

    public function toTelegram(mixed $notifiable): string
    {
        return "❌ Your booking ({$this->payload['booking_id']}) for {$this->payload['start_at']} has been cancelled.";
    }
}
