<?php

namespace App\Forum\Setup;

use App\Forum\Addons\Manager;
use App\Forum\Store\Client;
use App\Models\Ad;
use App\Models\ContactMessage;
use App\Models\Payment;
use App\Models\Post;
use App\Models\Report;
use App\Models\Thread;
use App\Models\User;

/**
 * What is waiting for the administrator right now: reports, content and payments to
 * approve, messages to read. The admin panel shows the numbers in its menu, in the
 * top bar and on its start page, so they are counted once per request.
 */
class Pending
{
    /** @var array<string, array{label: string, count: int, url: string, icon: string, nav: ?string}>|null */
    protected ?array $items = null;

    /** @return array<string, array{label: string, count: int, url: string, icon: string, nav: ?string}> */
    public function items(): array
    {
        return $this->items ??= [
            'reports' => $this->item('Open reports', Report::where('status', 'open')->count(), route('mod.reports'), 'alert'),
            'approval' => $this->item(
                'Awaiting approval',
                Thread::where('state', 'moderated')->count() + Post::where('state', 'moderated')->count(),
                route('mod.queue'),
                'check',
            ),
            'ads' => $this->item('Ads to review', Ad::where('status', 'review')->count(), route('admin.ads.index'), 'megaphone', 'admin.ads.index'),
            'payments' => $this->item('Payments to confirm', Payment::where('status', 'review')->count(), route('admin.payments.index'), 'card', 'admin.payments.index'),
            'messages' => $this->item('Unread contact messages', ContactMessage::where('is_read', false)->count(), route('admin.messages'), 'mail', 'admin.messages'),
            'deletions' => $this->item('Account deletion requests', User::whereNotNull('deletion_requested_at')->count(), route('admin.deletions'), 'trash', 'admin.deletions'),
            'updates' => $this->item('Updates in the market', (int) app(Client::class)->pendingUpdates(), route('admin.market'), 'refresh', 'admin.market'),
            // Switched on by the administrator, held back because the licence is not in order.
            'licences' => $this->item('Licences that need attention', count(app(Manager::class)->lockedIds()), route('admin.license'), 'key', 'admin.license'),
        ];
    }

    /** Only what really waits, most pressing first as listed above. */
    public function waiting(): array
    {
        return array_filter($this->items(), fn (array $item) => $item['count'] > 0);
    }

    public function total(): int
    {
        return array_sum(array_column($this->items(), 'count'));
    }

    /** The number to print next to a link of the admin menu (its route name), or 0. */
    public function forRoute(string $route): int
    {
        foreach ($this->items() as $item) {
            if ($item['nav'] === $route) {
                return $item['count'];
            }
        }

        return 0;
    }

    protected function item(string $label, int $count, string $url, string $icon, ?string $nav = null): array
    {
        return ['label' => $label, 'count' => $count, 'url' => $url, 'icon' => $icon, 'nav' => $nav];
    }
}
