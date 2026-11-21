<?php

namespace App\Forum;

use App\Forum\Permissions\PermissionResolver;
use App\Models\User;
use App\Models\UserUpgrade;
use App\Models\UserUpgradePurchase;
use App\Notifications\ForumAlert;

/** Paid membership packages: a purchase puts the member in a user group until it runs out. */
class Upgrades
{
    public function __construct(protected PermissionResolver $permissions) {}

    /** Start the package, or extend it when the member already has it. */
    public function activate(User $user, UserUpgrade $upgrade, float $price): UserUpgradePurchase
    {
        $current = UserUpgradePurchase::where('user_id', $user->id)
            ->where('user_upgrade_id', $upgrade->id)
            ->where('status', 'active')
            ->first();

        if ($current && $upgrade->days > 0 && $current->ends_at) {
            $current->update(['ends_at' => $current->ends_at->copy()->addDays($upgrade->days), 'price' => $current->price + $price]);
            $purchase = $current;
        } else {
            $purchase = $current ?? UserUpgradePurchase::create([
                'user_id' => $user->id,
                'user_upgrade_id' => $upgrade->id,
                'price' => $price,
                'starts_at' => now(),
                'ends_at' => $upgrade->days > 0 ? now()->addDays($upgrade->days) : null,
            ]);
        }

        $user->groups()->syncWithoutDetaching([$upgrade->user_group_id]);
        $user->unsetRelation('groups');
        $this->permissions->flush();

        $user->notify(new ForumAlert('upgrade', null, route('upgrades.index'), ['title' => $upgrade->title]));

        return $purchase;
    }

    /** Scheduled: take members out of the group when their package has run out. */
    public function expireDue(): int
    {
        $due = UserUpgradePurchase::with('upgrade', 'user')->where('status', 'active')->whereNotNull('ends_at')->where('ends_at', '<=', now())->limit(500)->get();

        foreach ($due as $purchase) {
            $purchase->update(['status' => 'expired']);

            $groupId = $purchase->upgrade?->user_group_id;
            if (! $purchase->user || ! $groupId) {
                continue;
            }

            // Another running package may grant the same group.
            $stillGranted = UserUpgradePurchase::where('user_id', $purchase->user_id)
                ->where('status', 'active')
                ->whereHas('upgrade', fn ($q) => $q->where('user_group_id', $groupId))
                ->exists();

            if (! $stillGranted) {
                $purchase->user->groups()->detach($groupId);
            }
        }

        if ($due->isNotEmpty()) {
            $this->permissions->flush();
        }

        return $due->count();
    }
}
