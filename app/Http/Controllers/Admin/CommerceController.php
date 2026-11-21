<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\PromotionOption;
use App\Models\ThreadPromotion;
use App\Models\UserGroup;
use App\Models\UserUpgrade;
use App\Models\UserUpgradePurchase;
use App\Models\WarningAction;
use App\Models\WarningType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Price lists and small catalogues: thread promotion, membership packages, warnings. */
class CommerceController extends Controller
{
    // ---- Thread promotion --------------------------------------------------

    public function promotions()
    {
        return view('admin.commerce.promotions', [
            'options' => PromotionOption::orderBy('type')->orderBy('days')->get(),
            'recent' => ThreadPromotion::with(['thread', 'user.group'])->latest('id')->limit(15)->get(),
            'income' => (float) ThreadPromotion::sum('price'),
        ]);
    }

    public function savePromotion(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', Rule::exists('promotion_options', 'id')],
            'type' => ['required', Rule::in(array_keys(ThreadPromotion::TYPES))],
            'days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ]);
        $data['days'] = $data['type'] === 'bump' ? 0 : max(1, (int) ($data['days'] ?? 0));
        $data['is_active'] = $request->boolean('is_active');

        PromotionOption::updateOrCreate(['id' => $data['id'] ?? null], collect($data)->except('id')->all());

        return back()->with('success', __('The option has been saved.'));
    }

    public function coupons()
    {
        return view('admin.commerce.coupons', ['coupons' => Coupon::orderByDesc('id')->get()]);
    }

    public function saveCoupon(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', Rule::exists('coupons', 'id')],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('coupons', 'code')->ignore($request->input('id'))],
            'percent' => ['nullable', 'integer', 'min:1', 'max:100', 'required_without:amount'],
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:9999999', 'prohibits:percent'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'expires_at' => ['nullable', 'date'],
        ]);
        $data['code'] = strtoupper($data['code']);
        $data['percent'] ??= null;
        $data['amount'] ??= null;
        $data['max_uses'] ??= null;
        $data['expires_at'] ??= null;
        $data['is_active'] = $request->boolean('is_active');

        Coupon::updateOrCreate(['id' => $data['id'] ?? null], collect($data)->except('id')->all());

        return back()->with('success', __('The coupon has been saved.'));
    }

    public function deleteCoupon(Coupon $coupon)
    {
        $coupon->delete();

        return back()->with('success', __('The coupon has been deleted.'));
    }

    public function deletePromotion(PromotionOption $option)
    {
        $option->delete();

        return back()->with('success', __('The option has been deleted.'));
    }

    // ---- Membership packages ---------------------------------------------------

    public function upgrades()
    {
        return view('admin.commerce.upgrades', [
            'upgrades' => UserUpgrade::with('group')->orderBy('display_order')->get(),
            'groups' => UserGroup::whereNull('system_key')->orderBy('priority')->get(),
            'active' => UserUpgradePurchase::selectRaw('user_upgrade_id, count(*) as total')->where('status', 'active')->groupBy('user_upgrade_id')->pluck('total', 'user_upgrade_id'),
            'purchases' => UserUpgradePurchase::with(['upgrade', 'user.group'])->latest('id')->limit(15)->get(),
        ]);
    }

    public function saveUpgrade(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', Rule::exists('user_upgrades', 'id')],
            'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'days' => ['required', 'integer', 'min:0', 'max:3650'],
            // System groups (admin, moderator...) can never be sold.
            'user_group_id' => ['required', Rule::exists('user_groups', 'id')->whereNull('system_key')],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        $data['display_order'] ??= 0;
        $data['is_active'] = $request->boolean('is_active');

        UserUpgrade::updateOrCreate(['id' => $data['id'] ?? null], collect($data)->except('id')->all());

        return back()->with('success', __('The package has been saved.'));
    }

    public function deleteUpgrade(UserUpgrade $upgrade)
    {
        $upgrade->delete();

        return back()->with('success', __('The package has been deleted.'));
    }

    // ---- Warnings ---------------------------------------------------------------

    public function warnings()
    {
        return view('admin.commerce.warnings', [
            'types' => WarningType::orderBy('display_order')->get(),
            'actions' => WarningAction::orderBy('points')->get(),
        ]);
    }

    public function saveWarningType(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', Rule::exists('warning_types', 'id')],
            'title' => ['required', 'string', 'max:100'],
            'points' => ['required', 'integer', 'min:0', 'max:100'],
            'expiry_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        $data['display_order'] ??= 0;

        WarningType::updateOrCreate(['id' => $data['id'] ?? null], collect($data)->except('id')->all());

        return back()->with('success', __('The warning has been saved.'));
    }

    public function deleteWarningType(WarningType $type)
    {
        $type->delete();

        return back()->with('success', __('The warning has been removed.'));
    }

    public function saveWarningAction(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', Rule::exists('warning_actions', 'id')],
            'points' => ['required', 'integer', 'min:1', 'max:1000'],
            'ban_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);

        WarningAction::updateOrCreate(['id' => $data['id'] ?? null], collect($data)->except('id')->all());

        return back()->with('success', __('The rule has been saved.'));
    }

    public function deleteWarningAction(WarningAction $action)
    {
        $action->delete();

        return back()->with('success', __('The rule has been deleted.'));
    }
}
