<?php

namespace App\Http\Controllers\Admin;

use App\Forum\Ads;
use App\Forum\Payments\PayTr;
use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\AdZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Advertising manager: orders / banners and the zones they are sold in. */
class AdController extends Controller
{
    public const FILTERS = ['all', 'review', 'unpaid', 'active', 'paused', 'expired', 'rejected'];

    public function __construct(protected Ads $ads) {}

    public function index(Request $request, PayTr $paytr)
    {
        $filter = in_array($request->query('status'), self::FILTERS, true) ? $request->query('status') : 'all';

        $counts = [];
        foreach (self::FILTERS as $name) {
            $counts[$name] = $this->filtered($name)->count();
        }

        return view('admin.ads.index', [
            'ads' => $this->filtered($filter)->with(['zone', 'user.group'])->latest('id')->paginate(30)->withQueryString(),
            'filter' => $filter,
            'counts' => $counts,
            'cardEnabled' => $paytr->configured(),
            'testMode' => $paytr->testMode(),
            'transferEnabled' => filled(setting('ads.transfer_info')),
            'income' => (float) Ad::whereNotNull('paid_at')->sum('paid_amount'),
        ]);
    }

    public function create()
    {
        return view('admin.ads.form', ['ad' => new Ad(['days' => 30]), 'zones' => AdZone::orderBy('display_order')->get()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, creating: true);

        $ad = Ad::create([
            'zone_id' => $data['zone_id'],
            'title' => $data['title'],
            'target_url' => $data['target_url'],
            'image_path' => $request->file('image')->store('ads', 'public'),
            'days' => $data['days'],
            'price' => $data['price'] ?? 0,
            'status' => 'review',
            'payment_method' => 'manual',
            'starts_at' => $data['starts_at'] ?? null,
            'admin_note' => $data['admin_note'] ?? null,
        ]);
        $this->ads->publish($ad);

        return redirect()->route('admin.ads.index')->with('success', __('The ad has been saved.'));
    }

    public function edit(Ad $ad)
    {
        return view('admin.ads.form', ['ad' => $ad->load('user'), 'zones' => AdZone::orderBy('display_order')->get()]);
    }

    public function update(Request $request, Ad $ad)
    {
        $data = $this->validated($request, creating: false);

        $changes = collect($data)->only(['zone_id', 'title', 'target_url', 'days', 'price', 'admin_note'])->all();
        $changes['starts_at'] = $data['starts_at'] ?? $ad->starts_at;
        $changes['ends_at'] = $data['ends_at'] ?? $ad->ends_at;

        if ($request->hasFile('image')) {
            $ad->deleteImage();
            $changes['image_path'] = $request->file('image')->store('ads', 'public');
        }
        $ad->update($changes);

        return redirect()->route('admin.ads.index')->with('success', __('The ad has been saved.'));
    }

    /** approve | reject | pause | resume */
    public function action(Request $request, Ad $ad)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'pause', 'resume'])],
            'admin_note' => ['nullable', 'string', 'max:500'],
        ]);

        match ($data['action']) {
            'approve' => $this->ads->publish($ad),
            'reject' => $this->ads->reject($ad, $data['admin_note'] ?? null),
            'pause' => $ad->status === 'active' ? $ad->update(['status' => 'paused']) : null,
            'resume' => $ad->status === 'paused' ? $ad->update(['status' => 'active']) : null,
        };

        return back()->with('success', __('The ad has been updated.'));
    }

    public function destroy(Ad $ad)
    {
        $this->ads->delete($ad);

        return back()->with('success', __('The ad has been deleted.'));
    }

    // ---- Zones ---------------------------------------------------------------

    public function zones()
    {
        $zones = AdZone::orderBy('display_order')->get();

        return view('admin.ads.zones', [
            'zones' => $zones,
            'used' => $zones->mapWithKeys(fn (AdZone $zone) => [$zone->id => $this->ads->holding($zone)->count()]),
        ]);
    }

    public function editZone(AdZone $zone)
    {
        return view('admin.ads.zone', ['zone' => $zone]);
    }

    public function updateZone(Request $request, AdZone $zone)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            // A text zone has no banner size.
            'width' => [$zone->isText() ? 'nullable' : 'required', 'integer', 'min:'.($zone->isText() ? 0 : 50), 'max:2000'],
            'height' => [$zone->isText() ? 'nullable' : 'required', 'integer', 'min:'.($zone->isText() ? 0 : 20), 'max:2000'],
            'slots' => ['required', 'integer', 'min:1', 'max:10'],
            'days' => ['array', 'max:8'],
            'days.*' => ['nullable', 'integer', 'min:1', 'max:730'],
            'price' => ['array', 'max:8'],
            'price.*' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        $prices = [];
        foreach ($data['days'] ?? [] as $i => $days) {
            if ($days && isset($data['price'][$i])) {
                $prices[(int) $days] = ['days' => (int) $days, 'price' => round((float) $data['price'][$i], 2)];
            }
        }
        ksort($prices);

        $zone->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'width' => $data['width'],
            'height' => $data['height'],
            'slots' => $data['slots'],
            'prices' => array_values($prices),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.ad-zones.index')->with('success', __('The ad space has been saved.'));
    }

    // ---------------------------------------------------------------------

    protected function filtered(string $filter): Builder
    {
        return Ad::query()->when($filter !== 'all', fn (Builder $q) => match ($filter) {
            'active' => $q->where('status', 'active')->where('ends_at', '>', now()),
            'expired' => $q->where('status', 'active')->where('ends_at', '<=', now()),
            default => $q->where('status', $filter),
        });
    }

    protected function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'zone_id' => ['required', Rule::exists('ad_zones', 'id')],
            'title' => ['required', 'string', 'max:80'],
            'target_url' => ['required', 'url:http,https', 'max:255'],
            'image' => [$creating ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:2048'],
            'days' => ['required', 'integer', 'min:1', 'max:730'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'admin_note' => ['nullable', 'string', 'max:500'],
        ]);

        foreach (['starts_at', 'ends_at'] as $field) {
            $data[$field] = filled($data[$field] ?? null) ? Carbon::parse($data[$field]) : null;
        }

        return $data;
    }
}
