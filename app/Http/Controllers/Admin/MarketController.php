<?php

namespace App\Http\Controllers\Admin;

use App\Forum\Addons\Manager;
use App\Forum\Setup\Updater;
use App\Forum\Store\Client;
use App\Forum\Themes;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Extra modules and themes from the vendor's market: browse, buy, install, update. */
class MarketController extends Controller
{
    public function __construct(protected Client $store, protected Manager $addons, protected Themes $themes) {}

    public function index(Request $request)
    {
        $catalog = $this->store->catalog($request->boolean('refresh'));
        $modules = $this->addons->all();
        $themes = $this->themes->all();
        $this->store->syncUpdates();

        $items = collect($catalog['items'] ?? [])->map(function (array $item) use ($modules, $themes) {
            $installed = match ($item['type']) {
                'core' => ['version' => (string) config('forum.product.version')],
                'theme' => $themes[$item['package_id']] ?? null,
                'module' => $modules[$item['package_id']] ?? null,
                default => null,
            };

            return $item + [
                'installed_version' => $installed['version'] ?? null,
                'update' => $installed && version_compare((string) $item['version'], (string) $installed['version'], '>'),
                'compatible' => blank($item['min_core'] ?? '') || version_compare((string) config('forum.product.version'), (string) $item['min_core'], '>='),
            ];
        });

        return view('admin.market', [
            'configured' => $this->store->configured(),
            'reachable' => $catalog !== null,
            'store' => $catalog['store'] ?? [],
            // The forum's own update is shown apart from what is for sale.
            'core' => $items->firstWhere('type', 'core'),
            'items' => $items->where('type', '!=', 'core')->values(),
            'themes' => $themes,
            'activeTheme' => $this->themes->activeId(),
            'themeManager' => $this->themes,
            'enabledAddons' => $this->addons->enabledIds(),
            'safeMode' => (bool) config('forum.safe_mode'),
        ]);
    }

    /** Opens an order and sends the administrator to the market's payment page. */
    public function buy(Request $request)
    {
        $data = $request->validate(['slug' => ['required', 'string', 'max:80']]);
        abort_unless($this->store->item($data['slug']), 404);

        $order = $this->store->order($data['slug']);
        if (! $order) {
            return back()->with('error', __('The market could not be reached. Please try again later.'));
        }

        return $order['status'] === 'paid' || ! isset($order['pay_url'])
            ? back()->with('success', __('This item already belongs to your forum. You can install it now.'))
            : redirect()->away($order['pay_url']);
    }

    public function install(Request $request)
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:80'],
            // A module is code that runs on the server: the password is asked again.
            'current_password' => ['required', 'current_password'],
        ]);

        $item = $this->store->item($data['slug'], true);
        abort_unless($item && in_array($item['type'], ['module', 'theme'], true), 404);
        if (filled($item['min_core'] ?? '') && version_compare((string) config('forum.product.version'), (string) $item['min_core'], '<')) {
            return back()->with('error', __('This item needs forum version :version or newer.', ['version' => $item['min_core']]));
        }

        $package = $this->store->download($item);
        if (is_string($package)) {
            return back()->with('error', $package);
        }

        try {
            if ($item['type'] === 'theme') {
                $result = $this->themes->installZip($package['path'], $package['id']);
            } else {
                // A running module is stopped, replaced and started again.
                $wasOn = in_array($package['id'], $this->addons->storedIds(), true);
                if ($wasOn) {
                    $this->addons->disable($package['id']);
                }
                $result = $this->addons->installZip($package['path'], $package['id']);
                // A new copy that asks for a licence the forum does not hold yet stays off until the code is entered.
                if (is_array($result) && $wasOn && $this->addons->licensed($package['id']) && ($error = $this->addons->enable($package['id'])) !== null) {
                    return back()->with('error', __('The add-on could not be switched on: :error', ['error' => $error]));
                }
            }
        } finally {
            @unlink($package['path']);
        }

        if (is_string($result)) {
            return back()->with('error', $result);
        }

        // A sold item waits for its licence code before it can be switched on.
        if ($item['type'] === 'theme' ? ! $this->themes->licensed($package['id']) : ! $this->addons->licensed($package['id'])) {
            return back()->with('success', $item['type'] === 'theme'
                ? __('The theme ":name" has been installed. Enter its licence code below to use it.', ['name' => $result['title']])
                : __('The module ":name" has been installed. Enter its licence code under Add-ons to switch it on.', ['name' => $result['title']]));
        }

        return back()->with('success', $item['type'] === 'theme'
            ? __('The theme ":name" has been installed. Switch it on below.', ['name' => $result['title']])
            : __('The module ":name" has been installed. Switch it on under Add-ons.', ['name' => $result['title']]));
    }

    /** One-click update of the forum itself: a signed package from the market, unpacked over the application. */
    public function updateCore(Request $request, Updater $updater)
    {
        $request->validate(['current_password' => ['required', 'current_password']]);

        $item = collect($this->store->catalog(true)['items'] ?? [])->firstWhere('type', 'core');
        abort_unless($item && $item['package_id'] === 'Meydan/Core', 404);
        if (! version_compare((string) $item['version'], (string) config('forum.product.version'), '>')) {
            return back()->with('success', __('The forum is already up to date.'));
        }

        $package = $this->store->download($item);
        if (is_string($package)) {
            return back()->with('error', $package);
        }

        try {
            @set_time_limit(300);
            $result = $updater->applyZip($package['path']);
        } finally {
            @unlink($package['path']);
        }

        $this->store->checkUpdates();

        return is_string($result)
            ? back()->with('error', $result)
            : redirect()->route('admin.market')->with('update_log', $result['log'])->with('success', __('The forum has been updated to version :version.', ['version' => $item['version']]));
    }

    public function theme(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', Rule::in(array_keys($this->themes->all()))],
            'action' => ['required', Rule::in(['activate', 'deactivate', 'delete'])],
        ]);

        if ($data['action'] === 'activate') {
            return $this->themes->activate($data['id'])
                ? back()->with('success', __('The theme is now in use.'))
                : back()->with('error', __('Enter the licence code of this theme first.'));
        }
        if ($data['action'] === 'deactivate') {
            $this->themes->deactivate();

            return back()->with('success', __('The theme has been switched off; the default colours are back.'));
        }

        return $this->themes->delete($data['id'])
            ? back()->with('success', __('The theme has been deleted.'))
            : back()->with('error', __('Switch the theme off before deleting it.'));
    }

    /** A theme that did not come from the market (your own, or one sent to you as a file). */
    public function uploadTheme(Request $request)
    {
        $request->validate([
            'package' => ['required', 'file', 'mimes:zip', 'max:5120'],
            'current_password' => ['required', 'current_password'],
        ]);
        $result = $this->themes->installZip($request->file('package')->getRealPath());

        return is_string($result)
            ? back()->with('error', $result)
            : back()->with('success', __('The theme ":name" has been installed. Switch it on below.', ['name' => $result['title']]));
    }
}
