<?php

namespace App\Http\Controllers\Admin;

use App\Forum\Addons\Manager;
use App\Forum\Settings;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use ZipArchive;

class AddonController extends Controller
{
    public function __construct(protected Manager $addons) {}

    public function index()
    {
        return view('admin.addons', [
            'addons' => $this->addons->all(),
            'enabled' => $this->addons->enabledIds(),
            'locked' => $this->addons->lockedIds(),
            'manager' => $this->addons,
            'safeMode' => (bool) config('forum.safe_mode'),
            'writable' => is_dir($this->addons->root()) ? is_writable($this->addons->root()) : is_writable(dirname($this->addons->root())),
        ]);
    }

    public function toggle(Request $request)
    {
        $data = $request->validate(['id' => ['required', Rule::in(array_keys($this->addons->all()))], 'action' => ['required', Rule::in(['enable', 'disable', 'delete'])]]);

        if ($data['action'] === 'enable') {
            $error = $this->addons->enable($data['id']);

            return $error === null
                ? back()->with('success', __('The add-on has been switched on.'))
                : back()->with('error', __('The add-on could not be switched on: :error', ['error' => $error]));
        }

        if ($data['action'] === 'delete') {
            return $this->addons->delete($data['id'])
                ? back()->with('success', __('The add-on has been deleted.'))
                : back()->with('error', __('Switch the add-on off before deleting it.'));
        }

        $this->addons->disable($data['id']);

        return back()->with('success', __('The add-on has been switched off.'));
    }

    public function settings(Request $request)
    {
        $id = (string) $request->query('id');
        abort_unless($this->addons->isEnabled($id) && $this->addons->settingsOf($id), 404);

        return view('admin.addon-settings', [
            'id' => $id,
            'addon' => $this->addons->all()[$id],
            'definitions' => $this->addons->settingsOf($id),
            'values' => collect($this->addons->settingsOf($id))->map(fn (array $def, string $name) => setting(Manager::settingKey($id, $name), $def['default']))->all(),
        ]);
    }

    public function saveSettings(Request $request, Settings $settings)
    {
        $id = (string) $request->input('id');
        abort_unless($this->addons->isEnabled($id), 404);
        $definitions = $this->addons->settingsOf($id);

        $rules = [];
        foreach ($definitions as $name => $def) {
            $rules['s.'.$name] = match ($def['type']) {
                'int' => ['required', 'integer', 'min:'.($def['min'] ?? 0), 'max:'.($def['max'] ?? PHP_INT_MAX)],
                'bool' => ['nullable'],
                'select' => ['required', Rule::in(array_keys($def['options'] ?? []))],
                'text' => ['nullable', 'string', 'max:20000'],
                default => ['nullable', 'string', 'max:500'],
            };
        }
        $input = $request->validate($rules)['s'] ?? [];

        $values = [];
        foreach ($definitions as $name => $def) {
            $raw = $input[$name] ?? null;
            if ($def['type'] === 'secret' && blank($raw)) {
                continue; // left empty: keep what is stored
            }
            $values[Manager::settingKey($id, $name)] = match ($def['type']) {
                'int' => (int) $raw,
                'bool' => $request->boolean('s.'.$name),
                default => (string) $raw,
            };
        }
        $settings->set($values);

        return back()->with('success', __('The settings have been saved.'));
    }

    /** Install an add-on from a .zip (addon.json at the top, or inside one folder). */
    public function upload(Request $request)
    {
        $request->validate([
            'package' => ['required', 'file', 'mimes:zip', 'max:51200'],
            // Add-ons are code that runs on the server: the password is asked again.
            'current_password' => ['required', 'current_password'],
        ]);
        abort_unless(class_exists(ZipArchive::class), 500, 'ZipArchive is not available.');

        $result = $this->addons->installZip($request->file('package')->getRealPath());

        return is_string($result)
            ? back()->with('error', $result)
            : back()->with('success', __('The add-on ":name" has been uploaded. Switch it on to use it.', ['name' => $result['title']]));
    }
}
