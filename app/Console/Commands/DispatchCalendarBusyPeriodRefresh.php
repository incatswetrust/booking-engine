<?php

namespace App\Console\Commands;

use App\Domain\Calendar\CalendarConnection;
use App\Domain\Calendar\CalendarConnectionStatus;
use App\Jobs\RefreshCalendarBusyPeriods;
use Illuminate\Console\Command;

class DispatchCalendarBusyPeriodRefresh extends Command
{
    protected $signature = 'calendar:refresh-busy-periods';

    protected $description = 'Dispatch a busy-period refresh job for every active calendar connection (§37, §62)';

    public function handle(): int
    {
        $count = 0;

        CalendarConnection::where('status', CalendarConnectionStatus::Active)
            ->cursor()
            ->each(function (CalendarConnection $connection) use (&$count): void {
                RefreshCalendarBusyPeriods::dispatch($connection->id);
                $count++;
            });

        $this->info("Dispatched busy-period refresh for {$count} calendar connection(s).");

        return self::SUCCESS;
    }
}
