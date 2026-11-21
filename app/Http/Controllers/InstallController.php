<?php

namespace App\Http\Controllers;

use App\Forum\Setup\WebInstaller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Web installer. Runs without sessions, cookies or a database, because none
 * of them exist yet; it closes itself as soon as the board has a member.
 * It does not go on without a working licence code of the forum software.
 */
class InstallController extends Controller
{
    public function __construct(protected WebInstaller $installer) {}

    public function show(Request $request)
    {
        abort_if(WebInstaller::installed(), 404);
        $this->locale($request);

        return $this->form($request, [], []);
    }

    public function store(Request $request)
    {
        abort_if(WebInstaller::installed(), 404);
        $this->locale($request);

        $plain = 'regex:/^[^\r\n\x00]*$/';
        $validator = Validator::make($request->all(), [
            // Checked with the vendor's licence server before anything is installed.
            'license_key' => ['required', 'string', 'max:64', $plain],
            'board_name' => ['required', 'string', 'max:80', $plain],
            'url' => ['required', 'url:http,https', 'max:255'],
            'locale' => ['required', Rule::in(['tr', 'en'])],
            'db_driver' => ['required', Rule::in(['mysql', 'sqlite'])],
            'db_host' => ['required_if:db_driver,mysql', 'nullable', 'string', 'max:255', $plain],
            'db_port' => ['required_if:db_driver,mysql', 'nullable', 'integer', 'min:1', 'max:65535'],
            'db_name' => ['required_if:db_driver,mysql', 'nullable', 'string', 'max:64', $plain],
            'db_user' => ['required_if:db_driver,mysql', 'nullable', 'string', 'max:64', $plain],
            'db_password' => ['nullable', 'string', 'max:255', $plain],
            'admin_username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[\p{L}\p{N}_-]+$/u'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if ($validator->fails()) {
            return $this->form($request, $validator->errors()->all(), $request->except(['admin_password', 'admin_password_confirmation', 'db_password']));
        }
        if (! $this->installer->requirementsPass()) {
            return $this->form($request, [__('Please fix the requirements marked in red first.')], $request->except(['admin_password', 'admin_password_confirmation']));
        }

        $error = $this->installer->install($validator->validated());
        if ($error !== null) {
            return $this->form($request, [$error], $request->except(['admin_password', 'admin_password_confirmation']));
        }

        return response()->view('install.done', [
            'url' => rtrim($request->input('url'), '/'),
            'username' => $request->input('admin_username'),
            'storageLinked' => file_exists(public_path('storage')),
        ]);
    }

    protected function form(Request $request, array $errors, array $input)
    {
        return response()->view('install.index', [
            'checks' => $this->installer->requirements(),
            'passes' => $this->installer->requirementsPass(),
            'messages' => $errors,
            'licenseReady' => app(\App\Forum\License\Licenses::class)->configured(),
            'input' => $input + [
                'url' => $request->getSchemeAndHttpHost().$request->getBasePath(),
                'locale' => app()->getLocale(),
                'db_driver' => extension_loaded('pdo_mysql') ? 'mysql' : 'sqlite',
                'db_host' => '127.0.0.1',
                'db_port' => 3306,
                'board_name' => 'Forum',
            ],
        ], $errors ? 422 : 200);
    }

    protected function locale(Request $request): void
    {
        $locale = $request->input('locale', $request->query('lang', 'tr'));
        app()->setLocale(in_array($locale, ['tr', 'en'], true) ? $locale : 'tr');
    }
}
