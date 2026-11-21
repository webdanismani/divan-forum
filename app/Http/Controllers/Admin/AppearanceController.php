<?php

namespace App\Http\Controllers\Admin;

use App\Forum\Theme;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AppearanceController extends Controller
{
    public function edit(Theme $theme)
    {
        return view('admin.appearance', [
            'fields' => $theme->fields(),
            'colors' => $theme->colors(),
            'presets' => $theme->presets(),
            'isCustom' => $theme->isCustom(),
        ]);
    }

    public function update(Request $request, Theme $theme)
    {
        $rules = [];
        foreach (array_keys($theme->fields()) as $key) {
            $rules["colors.$key"] = ['required', 'regex:/^#[0-9a-fA-F]{6}$/'];
        }

        $theme->save($request->validate($rules)['colors']);

        return back()->with('success', __('The colours have been saved.'));
    }

    public function reset(Theme $theme)
    {
        $theme->reset();

        return back()->with('success', __('The default colours have been restored.'));
    }
}
