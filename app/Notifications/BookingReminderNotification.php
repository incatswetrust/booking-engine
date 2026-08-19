<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class BookingReminderNotification extends BookingNotification
{
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Upcoming booking reminder')
            ->greeting("Hi {$notifiable->name},")
            ->line("Reminder: your booking ({$this->payload['booking_id']}) is coming up at {$this->payload['start_at']}.");
    }

    public function toTelegram(mixed $notifiable): string
    {
        return "⏰ Reminder: your booking ({$this->payload['booking_id']}) is coming up at {$this->payload['start_at']}.";
    }
}
