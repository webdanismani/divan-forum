<?php

namespace App\Http\Controllers\Admin;

use App\Forum\Setup\Checklist;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/** The search box of the admin panel: settings by their label, members by name or e-mail address. */
class SearchController extends Controller
{
    public function __invoke(Request $request)
    {
        $term = mb_substr(trim((string) $request->query('q')), 0, 60);
        if (mb_strlen($term) < 2) {
            return response()->json(['settings' => [], 'members' => []]);
        }
        $needle = mb_strtolower($term);

        $settings = [];
        foreach (config('forum.settings') as $group => $definitions) {
            foreach ($definitions as $key => $definition) {
                $label = __($definition['label']);
                if (str_contains(mb_strtolower($label), $needle) || str_contains(mb_strtolower(__($group)), $needle) || str_contains($key, $needle)) {
                    $settings[] = ['title' => $label, 'hint' => __($group), 'url' => preg_replace('/#.*$/', '', Checklist::settingsUrl($key)).'#'.str_replace('.', '__', $key)];
                }
                if (count($settings) >= 8) {
                    break 2;
                }
            }
        }

        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $members = User::with('group')->where(fn ($q) => $q->where('username', 'like', $like)->orWhere('email', 'like', $like))->orderBy('username')->limit(6)->get()
            ->map(fn (User $member) => ['title' => $member->username, 'hint' => $member->email.' · '.($member->group?->name ?? ''), 'url' => route('admin.users.edit', $member)])->all();

        return response()->json(['settings' => $settings, 'members' => $members])->header('Cache-Control', 'no-store');
    }
}
