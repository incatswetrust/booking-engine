<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class BookingRescheduledNotification extends BookingNotification
{
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your booking was rescheduled')
            ->greeting("Hi {$notifiable->name},")
            ->line("Your booking ({$this->payload['booking_id']}) moved from {$this->payload['old_start_at']} to {$this->payload['start_at']}.");
    }

    public function toTelegram(mixed $notifiable): string
    {
        return "🔄 Your booking ({$this->payload['booking_id']}) moved from {$this->payload['old_start_at']} to {$this->payload['start_at']}.";
    }
}
