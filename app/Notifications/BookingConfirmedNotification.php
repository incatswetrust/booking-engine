<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class BookingConfirmedNotification extends BookingNotification
{
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your booking is confirmed')
            ->greeting("Hi {$notifiable->name},")
            ->line("Your booking ({$this->payload['booking_id']}) is confirmed for {$this->payload['start_at']}.")
            ->line("Price: {$this->payload['price']} {$this->payload['currency']}");
    }

    public function toTelegram(mixed $notifiable): string
    {
        return "✅ Your booking ({$this->payload['booking_id']}) is confirmed for {$this->payload['start_at']}.";
    }
}
