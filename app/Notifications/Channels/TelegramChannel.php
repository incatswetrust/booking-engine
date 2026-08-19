<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

/**
 * §39: Telegram alongside Email, with room to add SMS/Push/WhatsApp the
 * same way — a channel class plus a to<Channel>() method on each
 * Notification. Uses Laravel's own HTTP client (unlike Stripe, which
 * ships its own), so it's fully testable via Http::fake().
 */
class TelegramChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        $chatId = $notifiable->routeNotificationFor('telegram', $notification);

        if (! $chatId || ! method_exists($notification, 'toTelegram')) {
            return;
        }

        $botToken = config('services.telegram.bot_token');

        Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $notification->toTelegram($notifiable),
        ])->throw();
    }
}
