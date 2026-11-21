<?php

namespace App\Http\Controllers\Admin;

use App\Forum\Customizer;
use App\Forum\Settings;
use App\Forum\Translation\OverridingLoader;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Custom CSS / HTML / logo, the phrase editor and the template editor. */
class CustomizeController extends Controller
{
    public function __construct(protected Customizer $customizer, protected Settings $settings) {}

    // ---- Extra CSS, HTML snippets, logo ------------------------------------------------

    public function saveExtras(Request $request)
    {
        $data = $request->validate([
            'custom_css' => ['nullable', 'string', 'max:50000'],
            'head_html' => ['nullable', 'string', 'max:10000'],
            'footer_html' => ['nullable', 'string', 'max:10000'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:1024'],
        ]);

        // The two HTML fields run as script on every page of every visitor: changing them asks for the password again.
        $newCode = fn (string $field) => filled($data[$field] ?? null) && (string) $data[$field] !== (string) $this->settings->get('theme.'.$field);
        if ($newCode('head_html') || $newCode('footer_html')) {
            $request->validate(['current_password' => ['required', 'current_password']], [
                'current_password.required' => __('Enter your password to change the HTML fields.'),
                'current_password.current_password' => __('The password is not correct.'),
            ]);
        }

        $values = [
            'theme.custom_css' => (string) ($data['custom_css'] ?? ''),
            'theme.head_html' => (string) ($data['head_html'] ?? ''),
            'theme.footer_html' => (string) ($data['footer_html'] ?? ''),
        ];

        if ($request->hasFile('logo') || $request->boolean('remove_logo')) {
            if ($old = (string) $this->settings->get('theme.logo_path')) {
                Storage::disk('public')->delete($old);
            }
            $values['theme.logo_path'] = $request->hasFile('logo') ? $request->file('logo')->store('brand', 'public') : '';
        }

        $this->settings->set($values);

        return back()->with('success', __('The appearance has been saved.'));
    }

    // ---- Phrases ---------------------------------------------------------------------------

    public function phrases(Request $request)
    {
        $locale = in_array($request->query('locale'), ['tr', 'en'], true) ? $request->query('locale') : 'tr';
        $search = trim((string) $request->query('q'));

        $file = lang_path($locale.'.json');
        $defaults = File::exists($file) ? (array) json_decode(File::get($file), true) : [];
        // English has no file: the key is the text.
        foreach (array_keys((array) json_decode(File::get(lang_path('tr.json')), true)) as $key) {
            $defaults[$key] ??= $key;
        }

        $changed = DB::table('phrases')->where('locale', $locale)->pluck('value', 'key')->all();

        $rows = collect($defaults)
            ->map(fn ($value, $key) => ['key' => (string) $key, 'default' => (string) $value, 'value' => $changed[$key] ?? null])
            ->when($request->boolean('changed'), fn ($rows) => $rows->filter(fn ($row) => $row['value'] !== null))
            ->when($search !== '', fn ($rows) => $rows->filter(fn ($row) => mb_stripos($row['key'].' '.$row['default'].' '.$row['value'], $search) !== false))
            ->sortBy('default', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $page = max(1, (int) $request->query('page', 1));

        return view('admin.custom.phrases', [
            'locale' => $locale,
            'search' => $search,
            'onlyChanged' => $request->boolean('changed'),
            'total' => $rows->count(),
            'rows' => $rows->forPage($page, 40),
            'page' => $page,
            'pages' => (int) ceil($rows->count() / 40),
            'changedCount' => count($changed),
        ]);
    }

    public function savePhrase(Request $request)
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(['tr', 'en'])],
            'key' => ['required', 'string', 'max:2000'],
            'value' => ['nullable', 'string', 'max:5000'],
        ]);

        $where = ['locale' => $data['locale'], 'key_hash' => sha1($data['key'])];

        if (blank($data['value'] ?? null) || $request->boolean('reset')) {
            DB::table('phrases')->where($where)->delete();
        } else {
            DB::table('phrases')->updateOrInsert($where, ['key' => $data['key'], 'value' => $data['value'], 'updated_at' => now(), 'created_at' => now()]);
        }
        Cache::forget(OverridingLoader::cacheKey($data['locale']));

        return back()->with('success', __('The text has been saved.'));
    }

    // ---- Templates ---------------------------------------------------------------------------

    public function templates(Request $request)
    {
        $search = trim((string) $request->query('q'));
        $overridden = $this->customizer->overridden();

        return view('admin.custom.templates', [
            'search' => $search,
            'files' => array_values(array_filter($this->customizer->templates(), fn (string $file) => $search === '' || str_contains($file, $search))),
            'overridden' => $overridden,
            'safeMode' => $this->customizer->safeMode(),
        ]);
    }

    public function editTemplate(Request $request)
    {
        $file = (string) $request->query('file');
        abort_unless($this->customizer->isTemplate($file), 404);

        return view('admin.custom.template', [
            'file' => $file,
            'content' => old('content', $this->customizer->source($file)),
            'original' => $this->customizer->source($file, original: true),
            'overridden' => $this->customizer->isOverridden($file),
        ]);
    }

    public function saveTemplate(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:300000'],
            // Templates are code that runs on the server: the password is asked again.
            'current_password' => ['required', 'current_password'],
        ]);
        abort_unless($this->customizer->isTemplate($data['file']), 404);

        if ($request->boolean('revert')) {
            $this->customizer->revert($data['file']);
            Artisan::call('view:clear');

            return redirect()->route('admin.templates')->with('success', __('The original template is in use again.'));
        }

        if ($error = $this->customizer->check($data['content'])) {
            return back()->withInput()->with('error', __('The template was not saved because it has an error: :error', ['error' => $error]));
        }

        $this->customizer->save($data['file'], $data['content']);
        Artisan::call('view:clear');

        return redirect()->route('admin.templates.edit', ['file' => $data['file']])->with('success', __('The template has been saved.'));
    }
}
