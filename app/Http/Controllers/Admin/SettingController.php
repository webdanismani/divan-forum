<?php

namespace App\Http\Controllers\Admin;

use App\Forum\Mailing;
use App\Forum\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    public function edit(Settings $settings)
    {
        return view('admin.settings', ['groups' => config('forum.settings'), 'values' => $settings->all()]);
    }

    public function update(Request $request, Settings $settings)
    {
        $definitions = $settings->definitions();
        $rules = [];

        // Dots in option keys are sent as double underscores in field names.
        foreach ($definitions as $key => $def) {
            $rules[$this->field($key)] = match ($def['type']) {
                'int' => ['required', 'integer', 'min:'.($def['min'] ?? 0), 'max:'.($def['max'] ?? PHP_INT_MAX)],
                'bool' => ['nullable'],
                'select' => ['required', Rule::in(array_keys($def['options']))],
                'text' => ['nullable', 'string', 'max:2000'],
                'secret' => ['nullable', 'string', 'max:255'],
                default => ['nullable', 'string', 'max:255'],
            };
        }
        $input = $request->validate($rules);

        $values = [];
        foreach ($definitions as $key => $def) {
            $raw = $input[$this->field($key)] ?? null;
            if ($def['type'] === 'secret' && blank($raw)) {
                continue; // left empty: keep what is stored
            }
            $values[$key] = match ($def['type']) {
                'int' => (int) $raw,
                'bool' => $request->boolean($this->field($key)),
                default => (string) $raw,
            };
        }
        $settings->set($values);

        return back()->with('success', __('The settings have been saved.'));
    }

    public function testMail(Request $request)
    {
        if (! Mailing::enabled()) {
            return back()->with('error', __('Outgoing mail is switched off. Choose SMTP and save the settings first.'));
        }

        $error = Mailing::test($request->user()->email);

        return $error === null
            ? back()->with('success', __('A test message has been sent to :email.', ['email' => $request->user()->email]))
            : back()->with('error', __('The test message could not be sent: :error', ['error' => $error]));
    }

    protected function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }
}
