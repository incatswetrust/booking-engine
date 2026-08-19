<?php

use App\Domain\Booking\BookingStateMachine;
use App\Domain\Booking\BookingStatus;

it('allows the documented happy path', function () {
    expect(BookingStateMachine::canTransition(BookingStatus::Pending, BookingStatus::Confirmed))->toBeTrue();
    expect(BookingStateMachine::canTransition(BookingStatus::Confirmed, BookingStatus::CheckedIn))->toBeTrue();
    expect(BookingStateMachine::canTransition(BookingStatus::CheckedIn, BookingStatus::Completed))->toBeTrue();
});

it('allows cancelling from any non-terminal state', function () {
    expect(BookingStateMachine::canTransition(BookingStatus::Pending, BookingStatus::Cancelled))->toBeTrue();
    expect(BookingStateMachine::canTransition(BookingStatus::Held, BookingStatus::Cancelled))->toBeTrue();
    expect(BookingStateMachine::canTransition(BookingStatus::AwaitingPayment, BookingStatus::Cancelled))->toBeTrue();
    expect(BookingStateMachine::canTransition(BookingStatus::Confirmed, BookingStatus::Cancelled))->toBeTrue();
});

it('rejects transitions out of terminal states', function () {
    expect(BookingStateMachine::canTransition(BookingStatus::Completed, BookingStatus::Confirmed))->toBeFalse();
    expect(BookingStateMachine::canTransition(BookingStatus::Cancelled, BookingStatus::Confirmed))->toBeFalse();
    expect(BookingStateMachine::canTransition(BookingStatus::NoShow, BookingStatus::Confirmed))->toBeFalse();
    expect(BookingStateMachine::canTransition(BookingStatus::Expired, BookingStatus::Confirmed))->toBeFalse();
});

it('rejects skipping straight from confirmed to completed', function () {
    expect(BookingStateMachine::canTransition(BookingStatus::Confirmed, BookingStatus::Completed))->toBeFalse();
});

it('rejects going backwards from checked_in to confirmed', function () {
    expect(BookingStateMachine::canTransition(BookingStatus::CheckedIn, BookingStatus::Confirmed))->toBeFalse();
});
