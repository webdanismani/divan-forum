<?php

namespace App\Http\Controllers;

use App\Forum\NodeTree;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The steps after registering, one page each: forums to follow, the profile, and a closing page
 * that says what is set and what is still open. Every step can be skipped and visited again.
 */
class WelcomeController extends Controller
{
    /** The steps in order: route name => label. */
    public const STEPS = ['welcome' => 'Forums', 'welcome.profile' => 'Profile', 'welcome.done' => 'Ready'];

    /** Step 1: forums to follow. */
    public function show(Request $request, NodeTree $tree)
    {
        $user = $request->user();

        // Forums grouped under their category; forums outside a category come first, without a heading.
        $groups = [];
        $current = null;
        foreach ($tree->flat($tree->viewable($user)) as $item) {
            $node = $item['node'];
            if ($node->isCategory()) {
                $groups[] = ['title' => $node->title, 'forums' => []];
                $current = array_key_last($groups);
            } elseif ($node->isForum()) {
                if ($current === null) {
                    $groups[] = ['title' => null, 'forums' => []];
                    $current = array_key_last($groups);
                }
                $groups[$current]['forums'][] = $node;
            }
        }

        return view('auth.welcome', [
            'step' => 'welcome',
            'groups' => array_values(array_filter($groups, fn ($group) => $group['forums'] !== [])),
            'watching' => DB::table('node_watches')->where('user_id', $user->id)->pluck('node_id')->map(fn ($id) => (int) $id)->all(),
        ]);
    }

    public function save(Request $request, NodeTree $tree)
    {
        $user = $request->user();

        if (! $request->boolean('skip')) {
            $allowed = $tree->viewable($user)->filter(fn ($node) => $node->isForum())->keys()->all();
            $chosen = array_values(array_intersect(array_map('intval', (array) $request->input('forums', [])), $allowed));

            DB::table('node_watches')->where('user_id', $user->id)->whereIn('node_id', $allowed)->whereNotIn('node_id', $chosen)->delete();
            foreach ($chosen as $nodeId) {
                DB::table('node_watches')->updateOrInsert(['user_id' => $user->id, 'node_id' => $nodeId], ['created_at' => now()]);
            }
        }
        $user->forceFill(['onboarded_at' => $user->onboarded_at ?? now()])->save();

        return redirect()->route('welcome.profile');
    }

    /** Step 2: picture, a few words, where from. */
    public function profile(Request $request)
    {
        return view('auth.welcome', ['step' => 'welcome.profile', 'user' => $request->user()]);
    }

    public function saveProfile(Request $request)
    {
        $user = $request->user();
        if ($request->boolean('skip')) {
            return redirect()->route('welcome.done');
        }

        $data = $request->validate([
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:2048', 'dimensions:min_width=48,min_height=48,max_width=4000,max_height=4000'],
            'about' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:80'],
        ]);

        AccountController::refuseLinks($data);

        $fill = ['about' => $data['about'] ?? null, 'location' => $data['location'] ?? null];
        $old = null;
        if ($request->hasFile('avatar')) {
            $old = $user->avatar_path;
            $fill['avatar_path'] = $request->file('avatar')->store('avatars', 'public');
        }
        $user->update($fill);
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        return redirect()->route('welcome.done');
    }

    /** Step 3: what is set, what is left, and the way into the forum. */
    public function done(Request $request)
    {
        $user = $request->user();

        return view('auth.welcome', [
            'step' => 'welcome.done',
            'user' => $user,
            'following' => DB::table('node_watches')->where('user_id', $user->id)->count(),
            'steps' => self::steps($user),
            'percent' => self::percent($user),
        ]);
    }

    /** @return array<int, array{0: string, 1: bool, 2: string}> what makes a profile complete: label, done?, where to do it */
    public static function steps(User $user): array
    {
        return [
            ['Confirm your e-mail address', $user->isVerified(), route('account.edit')],
            ['Add a profile picture', (bool) $user->avatar_path, route('welcome.profile')],
            ['Write a few words about yourself', filled($user->about), route('welcome.profile')],
            ['Say where you are from', filled($user->location), route('welcome.profile')],
            ['Write your first post', $user->post_count > 0, route('forum.index')],
        ];
    }

    public static function percent(User $user): int
    {
        $steps = self::steps($user);

        return (int) round(count(array_filter($steps, fn ($step) => $step[1])) / count($steps) * 100);
    }
}
