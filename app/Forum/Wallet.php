<?php

namespace App\Forum;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Member balance. Every change goes through here so the ledger and users.balance always agree. */
class Wallet
{
    public function credit(User $user, float $amount, string $type, string $description, ?User $actor = null): WalletTransaction
    {
        return $this->move($user, abs(round($amount, 2)), $type, $description, $actor);
    }

    /** @throws ValidationException when the balance is too low */
    public function debit(User $user, float $amount, string $type, string $description, ?User $actor = null): WalletTransaction
    {
        return $this->move($user, -abs(round($amount, 2)), $type, $description, $actor);
    }

    public function canAfford(User $user, float $amount): bool
    {
        return round((float) $user->fresh()->balance, 2) + 0.001 >= round($amount, 2);
    }

    protected function move(User $user, float $amount, string $type, string $description, ?User $actor): WalletTransaction
    {
        return DB::transaction(function () use ($user, $amount, $type, $description, $actor) {
            $balance = round((float) User::whereKey($user->id)->lockForUpdate()->value('balance'), 2);
            $after = round($balance + $amount, 2);

            if ($after < 0) {
                throw ValidationException::withMessages(['balance' => __('Your balance is not enough for this. Top it up first.')]);
            }

            User::whereKey($user->id)->update(['balance' => $after]);
            $user->balance = $after;
            $user->syncOriginalAttribute('balance');

            return WalletTransaction::create([
                'user_id' => $user->id,
                'amount' => $amount,
                'balance_after' => $after,
                'type' => $type,
                'description' => mb_substr($description, 0, 250),
                'actor_id' => $actor?->id,
            ]);
        });
    }
}
