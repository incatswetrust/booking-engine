<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class PaymentFailedNotification extends BookingNotification
{
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your payment failed')
            ->greeting("Hi {$notifiable->name},")
            ->line("We couldn't process your payment for booking ({$this->payload['booking_id']}).")
            ->line("Reason: {$this->payload['failure_reason']}")
            ->line('Please try again to keep your booking.');
    }

    public function toTelegram(mixed $notifiable): string
    {
        return "⚠️ Payment failed for booking ({$this->payload['booking_id']}): {$this->payload['failure_reason']}";
    }
}
