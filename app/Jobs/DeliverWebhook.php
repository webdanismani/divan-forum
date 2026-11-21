<?php

namespace App\Jobs;

use App\Forum\Integrations\Webhooks;
use App\Models\Webhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    /** @param bool $direct sent by hand from the admin panel: no retries, and switched-off webhooks are tried too */
    public function __construct(public int $webhookId, public array $payload, public bool $direct = false) {}

    public function handle(): void
    {
        $hook = Webhook::find($this->webhookId);
        if (! $hook || (! $hook->is_active && ! $this->direct)) {
            return;
        }

        $body = json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $retry = false;

        try {
            $response = Http::timeout(10)->withHeaders([
                'X-Forum-Event' => (string) $this->payload['event'],
                // sha256 HMAC of the exact body with the webhook's secret
                'X-Forum-Signature' => Webhooks::sign($body, $hook->secret),
                'User-Agent' => config('forum.product.name').'-Webhook',
            ])->withBody($body, 'application/json')->post($hook->url);

            $hook->update(['last_status' => $response->status(), 'last_error' => $response->successful() ? null : mb_substr($response->body(), 0, 250), 'last_called_at' => now()]);
            $retry = $response->serverError();
        } catch (Throwable $e) {
            $hook->update(['last_status' => 0, 'last_error' => mb_substr($e->getMessage(), 0, 250), 'last_called_at' => now()]);
            $retry = true;
        }

        if ($retry && ! $this->direct && $this->job) {
            $this->release($this->backoff);
        }
    }
}
