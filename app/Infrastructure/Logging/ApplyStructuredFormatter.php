<?php

namespace App\Infrastructure\Logging;

use Illuminate\Log\Logger;

class ApplyStructuredFormatter
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->setFormatter(new StructuredLogFormatter);
        }
    }
}
