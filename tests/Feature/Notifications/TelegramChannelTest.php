<?php

use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use Illuminate\Support\Facades\Http;

it('sends a mail-only notification when the customer has no telegram_chat_id', function () {
    Http::fake();

    $customer = User::factory()->create(['telegram_chat_id' => null]);

    $customer->notify(new BookingConfirmedNotification([
        'booking_id' => 'bkg_test', 'start_at' => '2026-01-01T10:00:00Z', 'price' => 10, 'currency' => 'USD',
    ]));

    Http::assertNothingSent();
});

it('also posts to the Telegram Bot API when the customer has connected a chat id', function () {
    Http::fake();
    config(['services.telegram.bot_token' => 'test-bot-token']);

    $customer = User::factory()->create(['telegram_chat_id' => '123456']);

    $customer->notify(new BookingConfirmedNotification([
        'booking_id' => 'bkg_test', 'start_at' => '2026-01-01T10:00:00Z', 'price' => 10, 'currency' => 'USD',
    ]));

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.telegram.org/bottest-bot-token/sendMessage'
            && $request['chat_id'] === '123456'
            && str_contains($request['text'], 'bkg_test');
    });
});
