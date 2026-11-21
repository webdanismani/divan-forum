<?php

namespace App\Forum\Payments;

use App\Forum\Ads;
use App\Forum\Settings;
use App\Forum\Upgrades;
use App\Forum\Wallet;
use App\Models\Ad;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserUpgrade;
use Illuminate\Support\Facades\DB;

/**
 * Card and bank-transfer payments for everything that is sold: ads, balance
 * top-ups and membership packages. confirm() is the only place where a
 * payment becomes "paid" and the thing that was bought is delivered.
 */
class Payments
{
    public function __construct(
        protected Wallet $wallet,
        protected Ads $ads,
        protected Upgrades $upgrades,
        protected Settings $settings,
    ) {}

    public function transferInfo(): string
    {
        return trim((string) $this->settings->get('ads.transfer_info'));
    }

    /** The open payment for this purchase, created when there is none yet. */
    public function open(User $user, string $purpose, ?int $subjectId, string $title, float $amount): Payment
    {
        $payment = Payment::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('subject_id', $subjectId)
            ->where('status', 'pending')
            ->when($subjectId === null, fn ($q) => $q->where('amount', $amount))
            ->first();

        if ($payment) {
            $payment->update(['amount' => round($amount, 2), 'title' => mb_substr($title, 0, 250)]);

            return $payment;
        }

        return Payment::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'subject_id' => $subjectId,
            'title' => mb_substr($title, 0, 250),
            'amount' => round($amount, 2),
        ]);
    }

    /** The member says the bank transfer was made; an administrator confirms it. */
    public function declareTransfer(Payment $payment): void
    {
        if ($payment->status === 'pending') {
            $payment->update(['status' => 'review', 'method' => 'transfer']);
        }
    }

    /**
     * The money has arrived (provider notification or an administrator).
     * Safe to call more than once: providers repeat their notifications.
     */
    public function confirm(Payment $payment, string $method, ?float $paid = null, ?string $reference = null): void
    {
        // The row is locked while it is decided: of two notifications that arrive together only one delivers.
        // Delivery is part of the same transaction, so a payment is never left "paid" with nothing handed over.
        DB::transaction(function () use ($payment, $method, $paid, $reference) {
            $row = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if (! $row || $row->status === 'paid') {
                return;
            }

            $paid ??= $row->amount;
            $enough = $paid + 0.001 >= $row->amount;

            $row->update([
                // Less than the total was received: recorded, but an administrator has to decide.
                'status' => $enough ? 'paid' : 'review',
                'method' => $method,
                'paid_amount' => $paid,
                'paid_at' => now(),
                'reference' => $reference ?? $row->reference,
            ]);

            if ($enough) {
                $this->deliver($row);
            }
        });
        $payment->refresh();
    }

    /**
     * Before money is asked for: is what this payment buys still sold, and at today's price? An open payment
     * made long ago does not keep a withdrawn package or an old price alive.
     */
    public function stillOffered(Payment $payment): bool
    {
        if ($payment->purpose !== 'upgrade' || $payment->status !== 'pending') {
            return true;
        }

        $upgrade = UserUpgrade::find($payment->subject_id);
        if (! $upgrade || ! $upgrade->is_active) {
            $this->cancel($payment);

            return false;
        }
        if (abs((float) $upgrade->price - (float) $payment->amount) > 0.001) {
            $payment->update(['amount' => round((float) $upgrade->price, 2)]);
        }

        return true;
    }

    /** An administrator accepts a payment as it is (transfer seen, or a short payment waived). */
    public function approve(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $row = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if (! $row || ! in_array($row->status, ['pending', 'review'], true)) {
                return;
            }
            $row->update([
                'status' => 'paid',
                'method' => $row->method ?? 'transfer',
                'paid_amount' => $row->paid_amount ?? $row->amount,
                'paid_at' => $row->paid_at ?? now(),
            ]);
            $this->deliver($row);
        });
        $payment->refresh();
    }

    public function cancel(Payment $payment, string $status = 'cancelled'): void
    {
        if (in_array($payment->status, ['pending', 'review'], true) && $payment->paid_at === null) {
            $payment->update(['status' => $status]);
        }
    }

    protected function deliver(Payment $payment): void
    {
        $user = $payment->user;

        match ($payment->purpose) {
            'wallet' => $this->wallet->credit($user, $payment->amount, 'topup', __('Balance top-up').' #'.$payment->id),
            'ad' => ($ad = Ad::with('zone')->find($payment->subject_id))
                ? $this->ads->markPaid($ad, $payment->method ?? 'card', $payment->paid_amount, $payment->reference)
                : null,
            'upgrade' => ($upgrade = UserUpgrade::find($payment->subject_id))
                ? $this->upgrades->activate($user, $upgrade, $payment->amount)
                : null,
            default => null,
        };
    }
}
