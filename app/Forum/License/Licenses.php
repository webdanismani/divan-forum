<?php

namespace App\Forum\License;

use App\Forum\Settings;
use App\Models\License;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Licence codes of this forum, checked against the vendor's licence server.
 *
 * A code is activated once (the server ties a seat of the licence to this installation and its address) and
 * answers with a signed token that is good for a few days. A scheduled task asks again every hour: a licence
 * that was suspended or cancelled stops working at the next answer, and a server that cannot be reached does
 * no harm until the last token runs out.
 */
class Licenses
{
    /** Answers that mean "no", as opposed to "ask again later". */
    protected const REFUSALS = [
        'lisans_yok' => 'This licence code is not known to the licence server.',
        'iptal' => 'This licence has been cancelled.',
        'askida' => 'This licence has been suspended.',
        'suresi_doldu' => 'This licence has expired.',
        'koltuk_dolu' => 'This licence is already in use on another forum. Release it there first.',
        'domain_kilit' => 'This licence is tied to another address.',
        'kara_liste' => 'The licence server refuses this installation.',
        'aktivasyon_yok' => 'This licence is not active on this forum any more.',
    ];

    /** @var \Illuminate\Support\Collection<string, License>|null */
    protected $rows = null;

    /** @var array<string, bool> */
    protected array $checked = [];

    /** @var array{fingerprint: string, domain: string}|null identity used while the installer runs (no settings to read yet) */
    protected ?array $installing = null;

    public function __construct(protected Settings $settings, protected Verifier $verifier) {}

    public function url(): string
    {
        return rtrim((string) config('forum.license.url'), '/');
    }

    public function configured(): bool
    {
        return $this->url() !== '' && $this->verifier->configured();
    }

    /** Licence product of the forum software itself. */
    public function coreProduct(): string
    {
        return (string) config('forum.license.product', 'wd-forum');
    }

    public static function validProduct(mixed $product): bool
    {
        return is_string($product) && preg_match('/^[A-Za-z0-9_-]{3,64}$/', $product) === 1;
    }

    /** What the licence server knows this installation by. Made once, kept with the settings. */
    public function fingerprint(): string
    {
        if ($this->installing !== null) {
            return $this->installing['fingerprint'];
        }

        $stored = (string) $this->settings->get('license.fingerprint');
        if (preg_match('/^[a-f0-9]{40}$/', $stored)) {
            return $stored;
        }

        $this->settings->set(['license.fingerprint' => $stored = bin2hex(random_bytes(20))]);

        return $stored;
    }

    /** The address a licence is tied to: the host of the forum's own URL. */
    public function domain(): string
    {
        return $this->installing['domain'] ?? self::host((string) config('app.url'));
    }

    public static function host(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return preg_replace('/^www\./', '', $host) ?: 'localhost';
    }

    /** @return \Illuminate\Support\Collection<string, License> */
    public function all()
    {
        if ($this->rows === null) {
            try {
                $this->rows = License::query()->orderBy('product')->get()->keyBy('product');
            } catch (Throwable) {
                return collect(); // the table is not there yet (first request after an update)
            }
        }

        return $this->rows;
    }

    public function get(string $product): ?License
    {
        return $this->all()->get($product);
    }

    /** True when a licence code is stored for the product and its last signed answer still holds. */
    public function valid(string $product): bool
    {
        return $this->checked[$product] ??= $this->payload($product) !== null;
    }

    /**
     * The verified contents of the stored token.
     *
     * @return array<string, mixed>|null
     */
    public function payload(string $product): ?array
    {
        $license = $this->get($product);
        if (! $license || $license->status !== 'active' || blank($license->token)) {
            return null;
        }

        try {
            $key = (string) $license->license_key;
        } catch (Throwable) {
            return null; // APP_KEY changed: the stored code is unreadable
        }

        return $this->accept($this->verifier->payload((string) $license->token, $license->chain), $product, $key);
    }

    /**
     * Ties the code to this forum. The server's signed answer names the product the code is for, so the
     * administrator does not have to: a code entered next to a module must be for that module ($expected),
     * a code entered on the licence page is filed under whatever it belongs to.
     *
     * @return array{product: string}|string the licence that is active now, or why it did not work
     */
    public function activate(string $key, ?string $expected = null): array|string
    {
        $claim = $this->claim($key, $expected);
        if (is_string($claim)) {
            return $claim;
        }

        $previous = $this->get($claim['product']);
        try {
            $old = $previous ? (string) $previous->license_key : '';
        } catch (Throwable) {
            $old = '';
        }
        if ($old !== '' && $old !== $claim['key']) {
            $this->call('iptal', $old);
        }

        $this->store($claim['product'], $claim['key'], $claim['answer'], $claim['payload']);

        return ['product' => $claim['product']];
    }

    /**
     * The installer asks before anything is written: is this a working code for the forum software itself?
     * The board has no settings yet, so the identity the code is tied to is made here and handed on.
     *
     * @return array<string, mixed>|string what keepInstall() needs, or why the installation cannot go on
     */
    public function checkForInstall(string $key, string $url): array|string
    {
        $this->installing = ['fingerprint' => bin2hex(random_bytes(20)), 'domain' => self::host($url)];

        $claim = $this->claim($key, $this->coreProduct());
        if (is_string($claim)) {
            $this->installing = null;
        }

        return $claim;
    }

    /** The board exists now: the identity the code was tied to and the licence itself are written down. */
    public function keepInstall(array $claim): void
    {
        if ($this->installing === null) {
            return;
        }

        $this->settings->set(['license.fingerprint' => $this->installing['fingerprint']]);
        $this->store($claim['product'], $claim['key'], $claim['answer'], $claim['payload']);
    }

    /** The installation failed after the code was accepted: its seat is given back, so the code works on the next try. */
    public function dropInstall(array $claim): void
    {
        if ($this->installing !== null) {
            $this->call('iptal', (string) $claim['key']);
            $this->installing = null;
        }
    }

    /**
     * Asks the server for a seat and checks its signed answer.
     *
     * @return array{product: string, key: string, answer: array, payload: array}|string
     */
    protected function claim(string $key, ?string $expected): array|string
    {
        $key = trim($key);
        if (! preg_match('/^[A-Za-z0-9-]{8,64}$/', $key)) {
            return __('This does not look like a licence code.');
        }
        if (! $this->configured()) {
            return __('The licence server is not set up on this forum.');
        }

        $answer = $this->call('aktivasyon', $key);
        if ($answer === null) {
            return __('The licence server could not be reached. Please try again later.');
        }
        if (empty($answer['basarili'])) {
            return $this->refusal($answer) ?? __('The licence server could not be reached. Please try again later.');
        }

        $payload = $this->verifier->payload((string) ($answer['jeton'] ?? ''), $answer['zincir'] ?? null);
        $product = $payload['urun'] ?? null;
        if ($payload === null || ! self::validProduct($product) || $this->accept($payload, $product, $key) === null) {
            return __('The answer of the licence server could not be verified.');
        }
        if ($expected !== null && $product !== $expected) {
            // The seat it just took for the wrong product is given back.
            $this->call('iptal', $key);

            return __('This licence code belongs to another product.');
        }

        return ['product' => $product, 'key' => $key, 'answer' => $answer, 'payload' => $payload];
    }

    /**
     * Asks the server again about every stored licence (scheduled, and "Check now" on the licence page).
     *
     * @return int how many licences the server refused
     */
    public function refresh(): int
    {
        if (! $this->configured()) {
            return 0;
        }

        $refused = 0;
        foreach ($this->all() as $product => $license) {
            try {
                $key = (string) $license->license_key;
            } catch (Throwable) {
                continue;
            }

            $answer = $this->call('nabiz', $key);
            if (is_array($answer) && empty($answer['basarili']) && ($answer['kod'] ?? '') === 'aktivasyon_yok') {
                // The seat was released on the server: take it again if it is still free.
                $answer = $this->call('aktivasyon', $key);
            }
            if ($answer === null) {
                continue; // no answer is not a refusal: the last token decides
            }

            if (empty($answer['basarili'])) {
                if (($message = $this->refusal($answer)) !== null) {
                    $license->update(['status' => 'refused', 'token' => null, 'chain' => null, 'message' => $message, 'valid_until' => null, 'checked_at' => now()]);
                    $refused++;
                }

                continue;
            }

            $payload = $this->accept($this->verifier->payload((string) ($answer['jeton'] ?? ''), $answer['zincir'] ?? null), (string) $product, $key);
            if ($payload !== null) {
                $this->store((string) $product, $key, $answer, $payload);
            }
        }
        $this->forget();

        return $refused;
    }

    /** Gives the seat back to the licence and forgets the code (before moving the forum, for instance). */
    public function release(string $product): void
    {
        $license = $this->get($product);
        if (! $license) {
            return;
        }

        try {
            if ($this->configured()) {
                $this->call('iptal', (string) $license->license_key);
            }
        } catch (Throwable) {
            // an unreadable code cannot be released; the row goes all the same
        }
        $license->delete();
        $this->forget();
    }

    public function forget(): void
    {
        $this->rows = null;
        $this->checked = [];
    }

    /** A token counts only for the code, the installation, the product and the address it was made for. */
    protected function accept(?array $payload, string $product, string $key): ?array
    {
        if ($payload === null || ($payload['anahtar'] ?? null) !== $key || ($payload['urun'] ?? null) !== $product
            || ($payload['parmak'] ?? null) !== $this->fingerprint()
            || (filled($payload['domain'] ?? null) && $payload['domain'] !== $this->domain())) {
            return null;
        }

        return $payload;
    }

    protected function store(string $product, string $key, array $answer, array $payload): void
    {
        $zone = config('app.timezone');

        License::query()->updateOrCreate(['product' => $product], [
            'license_key' => $key,
            'token' => (string) $answer['jeton'],
            'chain' => $answer['zincir'],
            'status' => 'active',
            'message' => null,
            'valid_until' => Carbon::createFromTimestamp((int) $payload['exp'])->setTimezone($zone),
            'license_ends_at' => filled($payload['bitis'] ?? null) ? Carbon::createFromTimestamp((int) $payload['bitis'])->setTimezone($zone) : null,
            'checked_at' => now(),
        ]);
        $this->forget();
    }

    /** The sentence for a refusal, or null when the answer is no verdict on the licence (busy, being repaired…). */
    protected function refusal(array $answer): ?string
    {
        $code = (string) ($answer['kod'] ?? '');

        return isset(self::REFUSALS[$code]) ? __(self::REFUSALS[$code]) : null;
    }

    /** @return array<string, mixed>|null the server's answer, null when there was none */
    protected function call(string $action, string $key): ?array
    {
        try {
            $response = Http::timeout(10)->connectTimeout(5)->acceptJson()->asJson()->post($this->url().'/'.$action, [
                'anahtar' => $key,
                'parmak' => $this->fingerprint(),
                'domain' => $this->domain(),
                'surum' => (string) config('forum.product.version'),
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $data = $response->json();

        return is_array($data) && array_key_exists('basarili', $data) ? $data : null;
    }
}
