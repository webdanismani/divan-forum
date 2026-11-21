<?php

namespace App\Http\Controllers;

use App\Forum\Payments\Payments;
use App\Forum\Payments\PayTr;
use App\Models\Payment;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WalletController extends Controller
{
    public function index(Request $request, PayTr $paytr, Payments $payments)
    {
        abort_unless(setting('wallet.enabled'), 404);
        $user = $request->user();

        return view('wallet.index', [
            'user' => $user,
            'transactions' => WalletTransaction::where('user_id', $user->id)->latest('id')->paginate(25),
            'open' => Payment::where('user_id', $user->id)->whereIn('status', ['pending', 'review'])->latest('id')->get(),
            'cardEnabled' => $paytr->configured(),
            'transferEnabled' => $payments->transferInfo() !== '',
            'min' => (int) setting('wallet.min_topup'),
            'max' => (int) setting('wallet.max_topup'),
        ]);
    }

    public function topup(Request $request, PayTr $paytr, Payments $payments)
    {
        abort_unless(setting('wallet.enabled'), 404);
        $user = $request->user();

        $methods = array_keys(array_filter(['card' => $paytr->configured(), 'transfer' => $payments->transferInfo() !== '']));
        abort_if($methods === [], 403, __('No payment method has been set up yet. Please contact the administrators.'));

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:'.(int) setting('wallet.min_topup'), 'max:'.(int) setting('wallet.max_topup')],
            'method' => ['required', Rule::in($methods)],
            'billing_name' => ['required', 'string', 'max:60'],
            'billing_phone' => ['required', 'string', 'max:20'],
            'billing_address' => ['required', 'string', 'max:400'],
        ]);

        $user->update(collect($data)->only(['billing_name', 'billing_phone', 'billing_address'])->all());

        $payment = $payments->open($user, 'wallet', null, __('Balance top-up').' · '.forum_money($data['amount']), (float) $data['amount']);

        if ($data['method'] === 'transfer') {
            return redirect()->route('payments.show', $payment);
        }

        return redirect()->route('payments.show', [$payment, 'pay' => 'card']);
    }
}
