<?php

namespace App\Http\Controllers;

use App\Forum\Edition;
use App\Forum\Payments\Payments;
use App\Forum\Payments\PayTr;
use App\Forum\Upgrades;
use App\Forum\Wallet;
use App\Models\UserUpgrade;
use App\Models\UserUpgradePurchase;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Paid membership packages. */
class UpgradeController extends Controller
{
    public function index(Request $request, PayTr $paytr, Payments $payments)
    {
        $this->available();
        $user = $request->user();

        return view('upgrades.index', [
            'upgrades' => UserUpgrade::with('group')->where('is_active', true)->orderBy('display_order')->get(),
            'purchases' => $user
                ? UserUpgradePurchase::with('upgrade')->where('user_id', $user->id)->where('status', 'active')->get()->keyBy('user_upgrade_id')
                : collect(),
            'cardEnabled' => $paytr->configured(),
            'transferEnabled' => $payments->transferInfo() !== '',
        ]);
    }

    public function buy(Request $request, UserUpgrade $upgrade, Wallet $wallet, Upgrades $upgrades, Payments $payments, PayTr $paytr)
    {
        $this->available();
        abort_unless($upgrade->is_active, 404);
        $user = $request->user();

        $methods = array_keys(array_filter(['balance' => true, 'card' => $paytr->configured(), 'transfer' => $payments->transferInfo() !== '']));
        $data = $request->validate(['method' => ['required', Rule::in($methods)]]);

        if ($data['method'] === 'balance') {
            $wallet->debit($user, $upgrade->price, 'upgrade', __('Membership package').': '.$upgrade->title);
            $upgrades->activate($user, $upgrade, $upgrade->price);

            return redirect()->route('upgrades.index')->with('success', __('Your package is active. Thank you!'));
        }

        $payment = $payments->open($user, 'upgrade', $upgrade->id, __('Membership package').': '.$upgrade->title, $upgrade->price);

        return redirect()->route('payments.show', $data['method'] === 'card' ? [$payment, 'pay' => 'card'] : [$payment]);
    }

    protected function available(): void
    {
        abort_unless(setting('wallet.enabled') && app(Edition::class)->has('paid_memberships'), 404);
    }
}
