<?php

namespace Tests\Concerns;

use App\Forum\License\Licenses;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

/**
 * Stands in for the vendor's licence server. Everything is signed for real (root key → intermediate key →
 * server certificate → token), only with keys made for the test, so the forum's checks run exactly as they
 * do against the live server.
 */
trait FakesLicenseServer
{
    /** @var array<string, mixed> keys, licences and switches of the pretend server */
    protected array $licenseServer = [];

    protected function fakeLicenseServer(): void
    {
        $root = sodium_crypto_sign_keypair();
        $intermediate = sodium_crypto_sign_keypair();
        $server = sodium_crypto_sign_keypair();

        $this->licenseServer = [
            'root' => sodium_crypto_sign_secretkey($root),
            'intermediate' => $intermediate,
            'server' => $server,
            'signer' => sodium_crypto_sign_secretkey($server), // what tokens are signed with (a test may swap it)
            'licenses' => [],
            'down' => false,
            'calls' => [],
        ];

        config([
            'forum.license.url' => 'https://license.test/api',
            'forum.license.root_key' => bin2hex(sodium_crypto_sign_publickey($root)),
            'forum.license.public_key' => bin2hex(sodium_crypto_sign_publickey($server)),
        ]);
        app(Licenses::class)->forget();

        $this->answerLicenseCalls();
    }

    /** Registers the stand-in again, with its state, after a test replaced the HTTP fakes. */
    protected function answerLicenseCalls(): void
    {
        Http::fake(['license.test/*' => fn (ClientRequest $request) => $this->licenseServerAnswer($request)]);
    }

    /** Puts a licence into the pretend server and returns its code. */
    protected function issueLicense(string $product, int $seats = 1, ?int $endsAt = null): string
    {
        $key = 'TST-'.strtoupper(bin2hex(random_bytes(4))).'-'.strtoupper(bin2hex(random_bytes(4)));
        $this->licenseServer['licenses'][$key] = ['product' => $product, 'seats' => $seats, 'status' => 'aktif', 'ends' => $endsAt, 'installs' => []];

        return $key;
    }

    /** A licence that is already active on this forum (for tests that are about the module, not the licence). */
    protected function licenseFor(string $product): string
    {
        $key = $this->issueLicense($product);
        $this->assertIsArray(app(Licenses::class)->activate($key, $product), "The licence for $product could not be activated.");

        return $key;
    }

    protected function licenseServerAnswer(ClientRequest $request)
    {
        $action = basename((string) parse_url($request->url(), PHP_URL_PATH));
        $data = $request->data();
        $this->licenseServer['calls'][] = [$action, $data['anahtar'] ?? null];

        if ($this->licenseServer['down']) {
            return Http::response('<html>502 Bad Gateway</html>', 502);
        }

        $key = (string) ($data['anahtar'] ?? '');
        $print = (string) ($data['parmak'] ?? '');
        $domain = (string) ($data['domain'] ?? '');
        $license = &$this->licenseServer['licenses'][$key];
        $refuse = fn (string $code) => Http::response(['basarili' => false, 'kod' => $code, 'mesaj' => $code], 422);

        if ($license === null) {
            unset($this->licenseServer['licenses'][$key]);

            return $refuse('lisans_yok');
        }
        if ($action === 'iptal') {
            unset($license['installs'][$print]);

            return Http::response(['basarili' => true, 'kod' => 'iptal_ok']);
        }
        if (in_array($license['status'], ['askida', 'iptal'], true)) {
            return $refuse($license['status']);
        }
        if ($license['ends'] !== null && $license['ends'] < now()->getTimestamp()) {
            return $refuse('suresi_doldu');
        }

        if ($action === 'nabiz' && ! isset($license['installs'][$print])) {
            return $refuse('aktivasyon_yok');
        }
        if (! isset($license['installs'][$print])) {
            if (count($license['installs']) >= $license['seats']) {
                return $refuse('koltuk_dolu');
            }
            $license['installs'][$print] = $domain;
        } elseif ($license['installs'][$print] !== $domain) {
            return $refuse('domain_kilit');
        }

        return Http::response([
            'basarili' => true,
            'kod' => 'ok',
            'jeton' => $this->licenseToken([
                'v' => 1, 'anahtar' => $key, 'uye' => 'test-vendor', 'urun' => $license['product'], 'surum' => '1.0', 'akt' => 1,
                'parmak' => $print, 'durum' => 'aktif', 'koltuk' => $license['seats'], 'domain_kilit' => true, 'domain' => $license['installs'][$print],
                'bitis' => $license['ends'], 'iat' => now()->getTimestamp(), 'yenile' => now()->getTimestamp() + 300, 'exp' => now()->getTimestamp() + 7 * 86400,
            ]),
            'zincir' => $this->licenseChain(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    protected function licenseToken(array $payload): string
    {
        $encode = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $body = $encode(json_encode($payload));

        return $body.'.'.$encode(sodium_crypto_sign_detached($body, $this->licenseServer['signer']));
    }

    /** @return array<string, string> */
    protected function licenseChain(?string $rootSecret = null): array
    {
        $now = now()->getTimestamp();
        $intermediate = json_encode(['t' => 'ara', 'p' => bin2hex(sodium_crypto_sign_publickey($this->licenseServer['intermediate'])), 's' => 'TESTARA', 'nb' => $now - 60, 'na' => $now + 90 * 86400]);
        $certificate = json_encode(['t' => 'kurulum', 'as' => 'TESTARA', 'p' => bin2hex(sodium_crypto_sign_publickey($this->licenseServer['server'])), 'h' => 'test-vendor', 'o' => ['api', 'domain_kilit'], 'nb' => $now - 60, 'na' => $now + 7 * 86400]);

        return [
            'ara' => base64_encode($intermediate),
            'ara_imza' => bin2hex(sodium_crypto_sign_detached($intermediate, $rootSecret ?? $this->licenseServer['root'])),
            'sert' => base64_encode($certificate),
            'sert_imza' => bin2hex(sodium_crypto_sign_detached($certificate, sodium_crypto_sign_secretkey($this->licenseServer['intermediate']))),
        ];
    }
}
