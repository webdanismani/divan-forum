<?php

namespace App\Http\Controllers\Admin;

use App\Forum\Rewards;
use App\Forum\Security\Bans;
use App\Http\Controllers\Controller;
use App\Models\Badge;
use App\Models\Ban;
use App\Models\GroupPromotion;
use App\Models\ProfileField;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\UserTitle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Bans and IP lookup, profile fields, titles, badges and automatic promotions. */
class MemberToolsController extends Controller
{
    // ---- Bans and IP addresses -----------------------------------------------------

    public function bans(Request $request)
    {
        $ip = trim((string) $request->query('ip'));
        $matches = collect();

        if ($ip !== '') {
            $like = str_replace('*', '%', addcslashes($ip, '%_\\'));
            $ids = DB::table('ip_logs')->where('ip', 'like', $like)->distinct()->limit(200)->pluck('user_id');
            $matches = User::with('group')
                ->where(fn ($q) => $q->whereIn('id', $ids)->orWhere('last_ip', 'like', $like)->orWhere('registration_ip', 'like', $like))
                ->orderByDesc('last_seen_at')->limit(100)->get();
        }

        return view('admin.members.bans', ['bans' => Ban::latest('id')->get(), 'ip' => $ip, 'matches' => $matches]);
    }

    public function saveBan(Request $request, Bans $bans)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['ip', 'email'])],
            'value' => ['required', 'string', 'min:3', 'max:190', 'regex:/^[A-Za-z0-9.:*@_+\-]+$/'],
            'reason' => ['nullable', 'string', 'max:255'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        // A rule that would lock the administrator out is refused.
        if ($data['type'] === 'ip' && Str::is(strtolower($data['value']), (string) $request->ip())) {
            return back()->with('error', __('This rule matches your own IP address.'));
        }

        Ban::create([
            'type' => $data['type'],
            'value' => strtolower($data['value']),
            'reason' => $data['reason'] ?? null,
            'expires_at' => filled($data['days'] ?? null) ? now()->addDays((int) $data['days']) : null,
            'created_by' => $request->user()->id,
        ]);
        $bans->flush();

        return back()->with('success', __('The ban has been added.'));
    }

    public function deleteBan(Ban $ban, Bans $bans)
    {
        $ban->delete();
        $bans->flush();

        return back()->with('success', __('The ban has been removed.'));
    }

    // ---- Profile fields, titles, badges, promotions -------------------------------------

    public function profile()
    {
        return view('admin.members.profile', [
            'fields' => ProfileField::orderBy('display_order')->get(),
            'titles' => UserTitle::orderBy('min_posts')->get(),
        ]);
    }

    public function saveField(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', Rule::exists('profile_fields', 'id')],
            'label' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(['text', 'url', 'select'])],
            'options' => ['nullable', 'string', 'max:1000'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        ProfileField::updateOrCreate(['id' => $data['id'] ?? null], [
            'label' => $data['label'],
            'type' => $data['type'],
            'options' => array_values(array_filter(array_map('trim', explode(',', (string) ($data['options'] ?? ''))))),
            'display_order' => $data['display_order'] ?? 0,
            'is_required' => $request->boolean('is_required'),
            'show_on_posts' => $request->boolean('show_on_posts'),
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', __('The field has been saved.'));
    }

    public function deleteField(ProfileField $field)
    {
        $field->delete();

        return back()->with('success', __('The field has been deleted.'));
    }

    public function saveTitle(Request $request, Rewards $rewards)
    {
        $data = $request->validate([
            'id' => ['nullable', Rule::exists('user_titles', 'id')],
            'min_posts' => ['required', 'integer', 'min:0', 'max:10000000', Rule::unique('user_titles', 'min_posts')->ignore($request->input('id'))],
            'title' => ['required', 'string', 'max:60'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['nullable', Rule::in(\App\Models\UserGroup::BANNER_ICONS)],
        ]);

        UserTitle::updateOrCreate(['id' => $data['id'] ?? null], ['min_posts' => $data['min_posts'], 'title' => $data['title'], 'color' => $data['color'] ?? null, 'icon' => $data['icon'] ?? null]);
        $rewards->flush();

        return back()->with('success', __('The title has been saved.'));
    }

    public function deleteTitle(UserTitle $title, Rewards $rewards)
    {
        $title->delete();
        $rewards->flush();

        return back()->with('success', __('The title has been deleted.'));
    }

    public function rewards()
    {
        return view('admin.members.rewards', [
            'badges' => Badge::orderBy('display_order')->get(),
            'holders' => DB::table('user_badges')->selectRaw('badge_id, count(*) as total')->groupBy('badge_id')->pluck('total', 'badge_id'),
            'promotions' => GroupPromotion::with('group')->orderBy('id')->get(),
            'groups' => UserGroup::whereNull('system_key')->orderBy('priority')->get(),
        ]);
    }

    public function saveBadge(Request $request, Rewards $rewards)
    {
        $data = $request->validate([
            'id' => ['nullable', Rule::exists('badges', 'id')],
            'title' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'icon' => ['required', 'string', 'max:16'],
            'criteria' => ['required', Rule::in(['manual', 'posts', 'reactions', 'solutions', 'days'])],
            'threshold' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        $data['threshold'] ??= 0;
        $data['display_order'] ??= 0;
        $data['is_active'] = $request->boolean('is_active');

        Badge::updateOrCreate(['id' => $data['id'] ?? null], collect($data)->except('id')->all());
        $rewards->flush();

        return back()->with('success', __('The badge has been saved.'));
    }

    public function deleteBadge(Badge $badge, Rewards $rewards)
    {
        $badge->delete();
        $rewards->flush();

        return back()->with('success', __('The badge has been deleted.'));
    }

    /** Give a badge to one member by hand, or take it back. */
    public function toggleBadge(Request $request, User $user, Rewards $rewards)
    {
        $data = $request->validate(['badge_id' => ['required', Rule::exists('badges', 'id')]]);
        $key = ['user_id' => $user->id, 'badge_id' => $data['badge_id']];

        if (DB::table('user_badges')->where($key)->delete()) {
            return back()->with('success', __('The badge has been taken back.'));
        }

        $rewards->award($user, Badge::findOrFail($data['badge_id']));

        return back()->with('success', __('The badge has been given.'));
    }

    public function savePromotion(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', Rule::exists('group_promotions', 'id')],
            'title' => ['required', 'string', 'max:80'],
            // Never a system group: nobody is promoted to moderator or administrator automatically.
            'user_group_id' => ['required', Rule::exists('user_groups', 'id')->whereNull('system_key')],
            'min_posts' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'min_days' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'min_reaction_score' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);
        foreach (['min_posts', 'min_days', 'min_reaction_score'] as $field) {
            $data[$field] ??= 0;
        }
        $data['is_active'] = $request->boolean('is_active');

        GroupPromotion::updateOrCreate(['id' => $data['id'] ?? null], collect($data)->except('id')->all());

        return back()->with('success', __('The promotion has been saved.'));
    }

    public function deletePromotion(GroupPromotion $promotion)
    {
        $promotion->delete();

        return back()->with('success', __('The promotion has been deleted.'));
    }
}
