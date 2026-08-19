<?php

namespace App\Notifications;

use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Shared via()/queue for the booking-lifecycle notification types (§39):
 * booking_confirmed, booking_cancelled, booking_rescheduled,
 * booking_reminder, payment_failed, waitlist_available all send over
 * mail, plus Telegram when the customer has connected a chat id.
 */
abstract class BookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(protected readonly array $payload)
    {
        $this->onQueue('notifications');
    }

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return array_values(array_filter([
            'mail',
            $notifiable->telegram_chat_id ? TelegramChannel::class : null,
        ]));
    }
}
