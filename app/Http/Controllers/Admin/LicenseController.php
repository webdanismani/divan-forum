<?php

namespace App\Http\Controllers\Admin;

use App\Forum\Addons\Manager;
use App\Forum\Edition;
use App\Forum\License\Licenses;
use App\Forum\Themes;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Licence codes of the forum and of the sold modules and themes installed on it. */
class LicenseController extends Controller
{
    public function __construct(protected Licenses $licenses, protected Manager $addons, protected Themes $themes) {}

    public function index(Edition $edition)
    {
        $products = $this->products();

        return view('admin.license', [
            'edition' => $edition,
            'configured' => $this->licenses->configured(),
            'domain' => $this->licenses->domain(),
            'licenses' => $this->licenses->all(),
            'valid' => $this->licenses->all()->keys()->filter(fn (string $product) => $this->licenses->valid($product))->all(),
            'products' => $products,
            // Installed and sold, but no licence in order: these do not run.
            'missing' => array_filter($products, fn (array $item, string $product) => $item['kind'] !== 'core' && ! $this->licenses->valid($product), ARRAY_FILTER_USE_BOTH),
            'coreProduct' => $this->licenses->coreProduct(),
        ]);
    }

    public function activate(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:64'],
            // Given when the code is entered next to a module or theme: it must be a code for that one.
            'product' => ['nullable', Rule::in(array_keys($this->products()))],
        ]);

        $result = $this->licenses->activate($data['license_key'], $data['product'] ?? null);
        if (is_string($result)) {
            return back()->with('error', $result);
        }

        $item = $this->products()[$result['product']] ?? null;

        return back()->with('success', match ($item['kind'] ?? null) {
            'module' => __('The licence of ":name" is active. You can switch the module on now.', ['name' => $item['title']]),
            'theme' => __('The licence of ":name" is active. You can use the theme now.', ['name' => $item['title']]),
            'core' => __('The licence of the forum is active.'),
            default => __('The licence is active. Its module or theme is not installed on this forum yet.'),
        });
    }

    /** Ask the licence server about every code now instead of waiting for the hourly check. */
    public function check()
    {
        if (! $this->licenses->configured()) {
            return back()->with('error', __('The licence server is not set up on this forum.'));
        }

        $refused = $this->licenses->refresh();
        $this->themes->dropUnlicensed();

        return $refused > 0
            ? back()->with('error', trans_choice('{1} The licence server refused one licence.|[2,*] The licence server refused :count licences.', $refused, ['count' => $refused]))
            : back()->with('success', __('The licences have been checked.'));
    }

    /** Give the seat back to the licence, for example before the forum moves to another address. */
    public function release(Request $request)
    {
        $data = $request->validate(['product' => ['required', Rule::in($this->licenses->all()->keys()->all())]]);

        $this->licenses->release($data['product']);
        $this->themes->dropUnlicensed();

        return back()->with('success', __('The licence has been released and can be used on another forum.'));
    }

    /**
     * Everything on this forum that can hold a licence.
     *
     * @return array<string, array{title: string, kind: string}> licence product => what it is
     */
    protected function products(): array
    {
        $products = [$this->licenses->coreProduct() => ['title' => (string) config('forum.product.name'), 'kind' => 'core']];

        foreach (array_keys($this->addons->all()) as $id) {
            if ($product = $this->addons->licenseProduct($id)) {
                $products[$product] = ['title' => $this->addons->text($id), 'kind' => 'module'];
            }
        }
        foreach ($this->themes->all() as $id => $theme) {
            if ($product = $this->themes->licenseProduct($id)) {
                $products[$product] = ['title' => (string) $theme['title'], 'kind' => 'theme'];
            }
        }

        return $products;
    }
}
