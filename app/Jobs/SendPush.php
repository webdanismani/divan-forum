<?php

namespace App\Jobs;

use App\Forum\Integrations\Push;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $userId) {}

    public function handle(Push $push): void
    {
        $push->notify($this->userId);
    }
}
