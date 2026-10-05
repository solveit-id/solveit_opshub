<?php

namespace App\Infrastructure\Backup;

use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;

/** One rate/deadline budget for the entire bundle, including metadata. */
class TransferBudget
{
    private float $started;

    private int $bytes = 0;

    public function __construct(private TransferClock $clock, private int $rate, private int $maximumBytes, private int $maximumSeconds)
    {
        if ($rate < 65536 || $rate > 104857600 || $maximumBytes < 1 || $maximumSeconds < 1 || $maximumSeconds > 3600) {
            throw new ConnectorFailure(ConnectorReason::LimitExceeded);
        }
        $this->started = $clock->seconds();
    }

    public function remainingSeconds(): int
    {
        $remaining = $this->maximumSeconds - ($this->clock->seconds() - $this->started);
        if ($remaining <= 0) {
            throw new ConnectorFailure(ConnectorReason::Timeout);
        }

        return max(1, (int) ceil($remaining));
    }

    public function consume(string $chunk, \Closure $consumer): void
    {
        $this->bytes += strlen($chunk);
        if (strlen($chunk) > 65536 || $this->bytes > $this->maximumBytes) {
            throw new ConnectorFailure(ConnectorReason::LimitExceeded);
        }
        // At most one initial chunk of burst. Subsequent bytes are paced cumulatively.
        $due = $this->started + max(0, $this->bytes - 65536) / $this->rate;
        while ($due - $this->clock->seconds() > 0.000001) {
            $this->remainingSeconds();
            $this->clock->wait(min(0.05, $due - $this->clock->seconds()));
        }
        $this->remainingSeconds();
        $consumer($chunk);
    }
}
