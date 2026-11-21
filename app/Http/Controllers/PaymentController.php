<?php

namespace App\Http\Controllers;

use App\Forum\Payments\Payments;
use App\Forum\Payments\PayTr;
use App\Models\Ad;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    public function __construct(protected Payments $payments) {}

    /** Everything the member has paid for, with a printable receipt for each payment. */
    public function index(Request $request)
    {
        return view('payments.index', [
            'payments' => Payment::where('user_id', $request->user()->id)->latest('id')->paginate(25),
        ]);
    }

    public function receipt(Request $request, Payment $payment)
    {
        $this->authorizeOwner($request, $payment);
        abort_unless($payment->status === 'paid', 404);
        $user = $payment->user;

        return view('payments.receipt', ['receipt' => [
            'number' => 'P-'.$payment->id,
            'date' => $payment->paid_at,
            'title' => $payment->title,
            'amount' => $payment->paid_amount ?? $payment->amount,
            'discount' => 0,
            'method' => __(Ad::METHODS[$payment->method] ?? 'Card'),
            'name' => $user?->billing_name ?: $user?->username,
            'address' => $user?->billing_address,
            'phone' => $user?->billing_phone,
        ]]);
    }

    public function show(Request $request, Payment $payment, PayTr $paytr)
    {
        $this->authorizeOwner($request, $payment);
        $mine = $payment->user_id === $request->user()->id;
        if ($mine && ! $this->payments->stillOffered($payment)) {
            return redirect()->to($this->target($payment)[1])->with('error', __('This offer is no longer available.'));
        }

        $card = null;
        if ($payment->isOpen() && $mine && $paytr->configured() && $request->query('pay') === 'card') {
            $card = $paytr->token($payment->load('user'), $this->clientIp($request));
        }

        return view('payments.show', [
            'payment' => $payment,
            'mine' => $mine,
            'card' => $card,
            'cardEnabled' => $paytr->configured(),
            'transferInfo' => $this->payments->transferInfo(),
            'target' => $this->target($payment),
        ]);
    }

    public function transfer(Request $request, Payment $payment)
    {
        $this->authorizeOwner($request, $payment);
        abort_unless($payment->isOpen() && $this->payments->transferInfo() !== '', 403);
        if (! $this->payments->stillOffered($payment)) {
            return redirect()->to($this->target($payment)[1])->with('error', __('This offer is no longer available.'));
        }

        $this->payments->declareTransfer($payment);

        return redirect()->route('payments.show', $payment)->with('success', __('Thank you. Your payment will be processed once the transfer has been confirmed.'));
    }

    public function destroy(Request $request, Payment $payment)
    {
        $this->authorizeOwner($request, $payment);
        abort_unless($payment->isOpen(), 403);

        $this->payments->cancel($payment);

        return redirect()->to($this->target($payment)[1])->with('success', __('The payment has been cancelled.'));
    }

    /** PayTR notification URL (server to server, no session, no CSRF token). */
    public function paytr(Request $request, PayTr $paytr)
    {
        $post = $request->post();

        if (! $paytr->verify($post)) {
            return response('PAYTR notification failed: bad hash', 400)->header('Content-Type', 'text/plain');
        }

        $payment = Payment::with('user')->find(PayTr::paymentId((string) $post['merchant_oid']));

        if ($payment && $post['status'] === 'success') {
            $this->payments->confirm($payment, 'card', ((int) $post['total_amount']) / 100, (string) $post['merchant_oid']);
        }

        // PayTR repeats the notification until it receives exactly this body.
        return response('OK')->header('Content-Type', 'text/plain');
    }

    /** @return array{0: string, 1: string} label and address of the page the payment belongs to */
    protected function target(Payment $payment): array
    {
        return match ($payment->purpose) {
            'ad' => [__('Back to the ad'), route('ads.show', $payment->subject_id)],
            'upgrade' => [__('Membership packages'), route('upgrades.index')],
            default => [__('My balance'), route('wallet.index')],
        };
    }

    protected function authorizeOwner(Request $request, Payment $payment): void
    {
        abort_unless($payment->user_id === $request->user()->id || Gate::allows('admin'), 403);
    }

    protected function clientIp(Request $request): string
    {
        $forwarded = $request->header('CF-Connecting-IP');

        return filter_var($forwarded, FILTER_VALIDATE_IP) ? $forwarded : (string) $request->ip();
    }
}
