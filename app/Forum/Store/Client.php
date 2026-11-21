<?php

namespace App\Forum\Store;

use App\Forum\Addons\Manager;
use App\Forum\Settings;
use App\Forum\Themes;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Talks to the vendor's market server: the catalogue of extra modules and
 * themes, orders, and package downloads. A downloaded package is only
 * handed on after its Ed25519 signature has been checked against the public
 * key that ships with the forum, so neither a tampered download nor a
 * compromised mirror can put code on the server.
 */
class Client
{
    protected const CACHE = 'forum.store.catalog';

    protected const UPDATES = 'forum.store.updates';

    /** Largest package that is accepted (bytes). */
    protected const MAX_PACKAGE = 50 * 1024 * 1024;

    public function __construct(protected Settings $settings) {}

    public function url(): string
    {
        return rtrim((string) config('forum.store.url'), '/');
    }

    public function configured(): bool
    {
        return $this->url() !== '' && $this->publicKey() !== null;
    }

    /**
     * @return array{store: array, items: array<int, array>}|null null when the market cannot be reached
     */
    public function catalog(bool $fresh = false): ?array
    {
        if (! $this->configured()) {
            return null;
        }
        if (! $fresh && is_array($cached = Cache::get(self::CACHE))) {
            return $cached;
        }

        try {
            $response = $this->http()->get($this->url().'/api.php', ['action' => 'catalog']);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $data = $response->successful() ? $response->json() : null;
        if (! is_array($data) || ! is_array($data['items'] ?? null)) {
            return null;
        }

        $data['items'] = array_values(array_filter($data['items'], fn ($item) => is_array($item)
            && is_string($item['slug'] ?? null) && in_array($item['type'] ?? '', ['module', 'theme', 'core', 'bundle'], true) && is_string($item['package_id'] ?? null)));
        Cache::put(self::CACHE, $data, now()->addMinutes(5));

        return $data;
    }

    public function item(string $slug, bool $fresh = false): ?array
    {
        return collect($this->catalog($fresh)['items'] ?? [])->firstWhere('slug', $slug);
    }

    /**
     * Installed modules and themes (and the forum itself) for which the market has a newer version.
     *
     * @return array<int, array> catalogue items
     */
    public function updates(bool $fresh = false): array
    {
        $modules = app(Manager::class)->all();
        $themes = app(Themes::class)->all();

        return array_values(array_filter($this->catalog($fresh)['items'] ?? [], function (array $item) use ($modules, $themes) {
            $installed = match ($item['type']) {
                'core' => (string) config('forum.product.version'),
                'module' => $modules[$item['package_id']]['version'] ?? null,
                'theme' => $themes[$item['package_id']]['version'] ?? null,
                default => null,
            };

            return $installed !== null && version_compare((string) ($item['version'] ?? '0'), (string) $installed, '>');
        }));
    }

    /** Scheduled: remember how many updates are waiting, for the badge in the admin menu. */
    public function checkUpdates(): int
    {
        if (! $this->configured()) {
            return 0;
        }

        $count = count($this->updates(true));
        Cache::forever(self::UPDATES, $count);

        return $count;
    }

    /** Same count from the catalogue that is already loaded (the market page keeps the badge honest). */
    public function syncUpdates(): void
    {
        if ($this->configured() && is_array(Cache::get(self::CACHE))) {
            Cache::forever(self::UPDATES, count($this->updates()));
        }
    }

    public function pendingUpdates(): int
    {
        return (int) Cache::get(self::UPDATES, 0);
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    /** @return array{status: string, pay_url?: string}|null */
    public function order(string $slug): ?array
    {
        try {
            $response = $this->http()->asForm()->post($this->url().'/api.php?action=order', ['slug' => $slug]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $this->forget();
        $data = $response->json();
        // The payment page must be on the market itself: the administrator is sent there.
        if (! $response->successful() || ! is_array($data) || ! is_string($data['status'] ?? null)
            || (isset($data['pay_url']) && ! str_starts_with((string) $data['pay_url'], $this->url().'/'))) {
            return null;
        }

        return $data;
    }

    /**
     * Downloads a package and checks it.
     *
     * @return array{path: string, id: string, version: string}|string the verified file, or why it was refused
     */
    public function download(array $item): array|string
    {
        try {
            $response = $this->http()->timeout(120)->get($this->url().'/api.php', ['action' => 'download', 'slug' => $item['slug']]);
        } catch (Throwable $e) {
            report($e);

            return __('The market could not be reached. Please try again later.');
        }

        if ($response->status() === 402) {
            return $response->json('error') === 'expired'
                ? __('The update period of this item has ended. Renew it to download new versions.')
                : __('This item has not been purchased for this forum yet.');
        }
        if (! $response->successful()) {
            return __('The market could not be reached. Please try again later.');
        }

        $body = $response->body();
        $id = (string) $response->header('X-Package-Id');
        $version = (string) $response->header('X-Package-Version');
        $signature = base64_decode((string) $response->header('X-Package-Signature'), true);
        $hash = hash('sha256', $body);

        // The version is part of what is signed: an older, genuinely signed package is not accepted in place of the one on offer.
        if (strlen($body) === 0 || strlen($body) > self::MAX_PACKAGE || $id !== $item['package_id']
            || ! hash_equals((string) ($item['version'] ?? ''), $version)
            || ! is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, 'meydan-package:'.$id.':'.$version.':'.$hash, $this->publicKey())) {
            return __('The package failed its signature check and was not installed.');
        }

        $path = tempnam(sys_get_temp_dir(), 'pkg');
        file_put_contents($path, $body);

        return ['path' => $path, 'id' => $id, 'version' => $version];
    }

    /** The vendor's Ed25519 public key (raw bytes), or null when it is missing or malformed. */
    protected function publicKey(): ?string
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            return null;
        }
        $key = base64_decode((string) config('forum.store.public_key'), true);

        return is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $key : null;
    }

    /** Purchases are tied to this random key; it is created the first time the market is used. */
    protected function siteKey(): string
    {
        try {
            $stored = (string) $this->settings->get('store.site_key');
            if ($stored !== '' && preg_match('/^[A-Za-z0-9]{48}$/', $key = Crypt::decryptString($stored))) {
                return $key;
            }
        } catch (Throwable) {
            // APP_KEY changed: the old key is unreadable.
        }

        $key = Str::random(48);
        $this->settings->set(['store.site_key' => Crypt::encryptString($key)]);

        return $key;
    }

    protected function http(): PendingRequest
    {
        return Http::timeout(15)->acceptJson()->withHeaders([
            'X-Site-Key' => $this->siteKey(),
            'X-Site-Url' => url('/'),
            'X-Site-Name' => rawurlencode((string) $this->settings->get('board.name')),
            'X-Core-Version' => (string) config('forum.product.version'),
        ]);
    }
}
