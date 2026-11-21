<?php

namespace App\Http\Controllers\Admin;

use App\Forum\Payments\Payments;
use App\Forum\Wallet;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Payments (card and bank transfer) and member balances. */
class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $filter = in_array($request->query('status'), ['review', 'pending', 'paid', 'cancelled', 'failed'], true) ? $request->query('status') : 'all';

        return view('admin.payments.index', [
            'payments' => Payment::with('user.group')
                ->when($filter !== 'all', fn ($q) => $q->where('status', $filter))
                ->latest('id')->paginate(30)->withQueryString(),
            'filter' => $filter,
            'counts' => Payment::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'income' => (float) Payment::where('status', 'paid')->sum('paid_amount'),
            'balances' => (float) User::sum('balance'),
        ]);
    }

    public function action(Request $request, Payment $payment, Payments $payments)
    {
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'reject'])]]);

        if ($data['action'] === 'approve') {
            $payments->approve($payment->load('user'));
        } else {
            $payment->update(['status' => $payment->paid_at ? $payment->status : 'cancelled']);
        }

        return back()->with('success', __('The payment has been updated.'));
    }

    public function wallet(Request $request)
    {
        return view('admin.payments.wallet', [
            'transactions' => WalletTransaction::with(['user.group', 'actor'])->latest('id')->paginate(40),
            'richest' => User::with('group')->where('balance', '>', 0)->orderByDesc('balance')->limit(10)->get(),
        ]);
    }

    /** Add to or take from a member's balance by hand. */
    public function adjust(Request $request, User $user, Wallet $wallet)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'note' => ['required', 'string', 'max:200'],
        ]);

        $amount = (float) $data['amount'];
        $amount > 0
            ? $wallet->credit($user, $amount, 'adjustment', $data['note'], $request->user())
            : $wallet->debit($user, $amount, 'adjustment', $data['note'], $request->user());

        return back()->with('success', __('The balance has been updated.'));
    }
}
