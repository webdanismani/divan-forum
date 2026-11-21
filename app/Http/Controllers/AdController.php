<?php

namespace App\Http\Controllers;

use App\Forum\Ads;
use App\Forum\Payments\Payments;
use App\Forum\Payments\PayTr;
use App\Forum\Wallet;
use App\Models\Ad;
use App\Models\AdZone;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Members order banner space here; the admin side is Admin\AdController. */
class AdController extends Controller
{
    protected const MAX_UNPAID = 5;

    public function __construct(protected Ads $ads) {}

    public function index(Request $request, PayTr $paytr)
    {
        abort_unless($this->ads->enabled(), 404);

        $zones = $this->ads->zones()->where('is_active', true)->filter(fn (AdZone $zone) => $zone->priceList())->values();
        $availability = $zones->mapWithKeys(fn (AdZone $zone) => [$zone->id => [
            'free' => $this->ads->freeSlots($zone),
            'next' => $this->ads->nextFreeAt($zone),
        ]]);

        // The zone the visitor came for, unless it is full.
        $wanted = $zones->firstWhere('id', (int) old('zone_id')) ?? $zones->firstWhere('position', $request->query('zone'));
        $selected = $wanted && $availability[$wanted->id]['free'] > 0
            ? $wanted
            : ($zones->first(fn (AdZone $zone) => $availability[$zone->id]['free'] > 0) ?? $wanted ?? $zones->first());

        return view('ads.index', [
            'zones' => $zones,
            'availability' => $availability,
            'selected' => $selected,
            'cardEnabled' => $paytr->configured(),
            'transferEnabled' => filled(setting('ads.transfer_info')),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->ads->enabled(), 404);
        $user = $request->user();

        $data = $request->validate([
            'zone_id' => ['required', Rule::exists('ad_zones', 'id')->where('is_active', true)],
            'days' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:80'],
            'target_url' => ['required', 'url:http,https', 'max:255'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:1024'],
            'coupon' => ['nullable', 'string', 'max:30'],
            'billing_name' => ['required', 'string', 'max:60'],
            'billing_phone' => ['required', 'string', 'max:20'],
            'billing_address' => ['required', 'string', 'max:400'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $zone = AdZone::findOrFail($data['zone_id']);
        $price = $zone->priceFor((int) $data['days']);
        $coupon = filled($data['coupon'] ?? null) ? Coupon::usable($data['coupon']) : null;

        if (filled($data['coupon'] ?? null) && ! $coupon) {
            throw ValidationException::withMessages(['coupon' => __('This coupon code is not valid (any more).')]);
        }
        if (! $zone->isText() && ! $request->hasFile('image')) {
            throw ValidationException::withMessages(['image' => __('Please choose a banner image.')]);
        }

        if ($price === null) {
            throw ValidationException::withMessages(['days' => __('Please choose one of the listed periods.')]);
        }
        if ($this->ads->freeSlots($zone) < 1) {
            throw ValidationException::withMessages(['zone_id' => __('This ad space is full at the moment.')]);
        }
        // Orders waiting for a declared bank transfer count as well: declaring one must not make room for more.
        if (Ad::where('user_id', $user->id)->whereIn('status', ['unpaid', 'review'])->whereNull('paid_at')->count() >= self::MAX_UNPAID) {
            throw ValidationException::withMessages(['zone_id' => __('You have too many unpaid orders. Pay or cancel them first.')]);
        }
        if (! $zone->isText() && ! $this->fitsZone($request->file('image'), $zone)) {
            throw ValidationException::withMessages(['image' => __('The banner must be :size pixels (or the same proportions at a higher resolution).', ['size' => $zone->size()])]);
        }

        // Everything else is in order: now the coupon's use is really taken (it may have gone to another order meanwhile).
        if ($coupon && ! ($coupon = Coupon::claim($coupon->code))) {
            throw ValidationException::withMessages(['coupon' => __('This coupon code is not valid (any more).')]);
        }

        $ad = Ad::create([
            'zone_id' => $zone->id,
            'user_id' => $user->id,
            'title' => $data['title'],
            'target_url' => $data['target_url'],
            'image_path' => $zone->isText() ? null : $request->file('image')->store('ads', 'public'),
            'days' => (int) $data['days'],
            'price' => $total = $coupon ? $coupon->apply($price) : $price,
            'coupon_code' => $coupon?->code,
            'discount' => round($price - $total, 2),
            // Nothing to pay (a free zone, or a coupon for the whole amount): it goes straight to the admin.
            'status' => $total > 0 ? 'unpaid' : 'review',
            'billing_name' => $data['billing_name'],
            'billing_phone' => $data['billing_phone'],
            'billing_address' => $data['billing_address'],
            'note' => $data['note'] ?? null,
        ]);
        $user->update(collect($data)->only(['billing_name', 'billing_phone', 'billing_address'])->all());

        return redirect()->route('ads.show', $ad)->with('success', __('Your order has been created.'));
    }

    public function mine(Request $request)
    {
        abort_unless($this->ads->enabled(), 404);

        return view('ads.mine', [
            'ads' => Ad::with('zone')->where('user_id', $request->user()->id)->latest('id')->paginate(20),
        ]);
    }

    public function show(Request $request, Ad $ad, PayTr $paytr)
    {
        $this->authorizeOwner($request, $ad);
        $ad->load('zone', 'user');

        return view('ads.show', [
            'ad' => $ad,
            'cardEnabled' => $paytr->configured() && $ad->price > 0,
            'transferInfo' => (string) setting('ads.transfer_info'),
            'balance' => setting('wallet.enabled') ? (float) $request->user()->balance : null,
        ]);
    }

    /** Pay an order from the member's balance, or open a card payment for it. */
    public function pay(Request $request, Ad $ad, PayTr $paytr, Payments $payments, Wallet $wallet)
    {
        abort_unless($ad->user_id === $request->user()->id && $ad->status === 'unpaid', 403);
        $ad->load('zone');

        $methods = array_keys(array_filter(['balance' => (bool) setting('wallet.enabled'), 'card' => $paytr->configured()]));
        $data = $request->validate(['method' => ['required', Rule::in($methods)]]);
        $title = __('Ad').' #'.$ad->id.' · '.$ad->zone->title.' · '.__(':days days', ['days' => $ad->days]);

        if ($data['method'] === 'balance') {
            $wallet->debit($request->user(), $ad->price, 'ad', $title);
            $this->ads->markPaid($ad, 'balance', $ad->price);

            return redirect()->route('ads.show', $ad)->with('success', __('Thank you, the payment has been taken from your balance.'));
        }

        return redirect()->route('payments.show', [$payments->open($request->user(), 'ad', $ad->id, $title, $ad->price), 'pay' => 'card']);
    }

    /** Order the same ad again for another period; it starts when the current one ends. */
    public function renew(Request $request, Ad $ad, Wallet $wallet)
    {
        abort_unless($this->ads->enabled() && $ad->user_id === $request->user()->id && $ad->status === 'active', 403);
        $ad->load('zone');

        $data = $request->validate(['days' => ['required', 'integer']]);
        $renewal = $this->ads->renew($ad, (int) $data['days']);
        if (! $renewal) {
            throw ValidationException::withMessages(['days' => __('Please choose one of the listed periods.')]);
        }

        // One click when the balance covers it; otherwise the payment page of the new order.
        if (setting('wallet.enabled') && $renewal->price > 0 && (float) $request->user()->balance + 0.001 >= $renewal->price) {
            $wallet->debit($request->user(), $renewal->price, 'ad', __('Ad').' #'.$renewal->id.' · '.$ad->zone->title.' · '.__(':days days', ['days' => $renewal->days]));
            $this->ads->markPaid($renewal->load('zone'), 'balance', $renewal->price);

            return redirect()->route('ads.show', $renewal)->with('success', __('Your ad has been renewed and paid from your balance.'));
        }

        return redirect()->route('ads.show', $renewal)->with('success', __('The renewal has been created. Complete the payment to keep your ad running.'));
    }

    public function receipt(Request $request, Ad $ad)
    {
        $this->authorizeOwner($request, $ad);
        abort_unless($ad->paid_at, 404);
        $ad->load('zone', 'user');

        return view('payments.receipt', ['receipt' => [
            'number' => 'R-'.$ad->id,
            'date' => $ad->paid_at,
            'title' => __('Ad').' #'.$ad->id.' · '.$ad->zone->title.' · '.__(':days days', ['days' => $ad->days]),
            'amount' => $ad->paid_amount ?? $ad->price,
            'discount' => $ad->discount,
            'method' => __(Ad::METHODS[$ad->payment_method] ?? 'Card'),
            'name' => $ad->billing_name ?: $ad->user?->username,
            'address' => $ad->billing_address,
            'phone' => $ad->billing_phone,
        ]]);
    }

    /** The member says the bank transfer has been made; an administrator confirms it. */
    public function transfer(Request $request, Ad $ad)
    {
        $this->authorizeOwner($request, $ad);
        abort_unless($ad->status === 'unpaid' && filled(setting('ads.transfer_info')), 403);

        $ad->update(['status' => 'review', 'payment_method' => 'transfer']);

        return redirect()->route('ads.show', $ad)->with('success', __('Thank you. Your ad will be published once the transfer has been confirmed.'));
    }

    public function destroy(Request $request, Ad $ad)
    {
        $this->authorizeOwner($request, $ad);
        abort_unless(in_array($ad->status, ['unpaid', 'rejected'], true) && $ad->paid_at === null, 403);

        $this->ads->delete($ad);

        return redirect()->route('ads.mine')->with('success', __('The order has been cancelled.'));
    }

    /** Count the click and send the visitor to the advertiser. */
    public function click(Ad $ad)
    {
        if (! $ad->isLive()) {
            return redirect()->route('forum.index');
        }

        Ad::whereKey($ad->id)->increment('clicks');

        return redirect()->away($ad->target_url);
    }

    protected function authorizeOwner(Request $request, Ad $ad): void
    {
        abort_unless($ad->user_id === $request->user()->id || Gate::allows('admin'), 403);
    }

    /** Same proportions as the zone (3% tolerance) and at least as wide. */
    protected function fitsZone(UploadedFile $image, AdZone $zone): bool
    {
        $size = @getimagesize($image->getRealPath());
        if (! $size || $size[1] < 1) {
            return false;
        }

        $wanted = $zone->width / $zone->height;

        return $size[0] >= $zone->width && abs($size[0] / $size[1] - $wanted) / $wanted <= 0.03;
    }
}
