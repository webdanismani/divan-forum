<?php

namespace App\Http\Controllers\Admin;

use App\Forum\Import\Importer;
use App\Forum\Integrations\Webhooks;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\ApiToken;
use App\Models\ImportRun;
use App\Models\PushSubscription;
use App\Models\Webhook;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/** API keys and webhooks, and imports from other forum software. */
class IntegrationController extends Controller
{
    // ---- API and webhooks --------------------------------------------------------------------

    public function index()
    {
        return view('admin.integrations', [
            'webhooks' => Webhook::orderBy('id')->get(),
            'events' => Webhooks::EVENTS,
            'tokens' => ApiToken::join('users', 'users.id', '=', 'api_tokens.user_id')->orderByDesc('api_tokens.id')->limit(50)
                ->get(['api_tokens.*', 'users.username']),
            'pushCount' => PushSubscription::count(),
        ]);
    }

    public function saveWebhook(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', 'exists:webhooks,id'],
            'url' => ['required', 'url:http,https', 'max:255'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(Webhooks::EVENTS))],
        ]);
        $values = ['url' => $data['url'], 'events' => array_values($data['events']), 'is_active' => $request->boolean('is_active')];

        if ($data['id'] ?? null) {
            Webhook::findOrFail($data['id'])->update($values);
        } else {
            Webhook::create($values + ['secret' => Str::random(40)]);
        }

        return back()->with('success', __('The webhook has been saved.'));
    }

    public function deleteWebhook(Webhook $webhook)
    {
        $webhook->delete();

        return back()->with('success', __('The webhook has been deleted.'));
    }

    /** Sends a "ping" right now, so the address and the signature check can be tried out. */
    public function testWebhook(Webhook $webhook, Webhooks $webhooks)
    {
        (new DeliverWebhook($webhook->id, $webhooks->payload('ping', ['message' => 'It works.']), true))->handle();
        $webhook->refresh();

        return $webhook->last_status >= 200 && $webhook->last_status < 300
            ? back()->with('success', __('The test request was accepted (HTTP :status).', ['status' => $webhook->last_status]))
            : back()->with('error', __('The test request failed: :error', ['error' => $webhook->last_status ? 'HTTP '.$webhook->last_status : (string) $webhook->last_error]));
    }

    public function deleteToken(ApiToken $token)
    {
        $token->delete();

        return back()->with('success', __('The API key has been deleted.'));
    }

    // ---- Import -------------------------------------------------------------------------------

    public function import(Importer $importer)
    {
        return view('admin.import', [
            'sources' => collect(Importer::SOURCES)->map(fn ($class) => ['label' => $class::label(), 'prefix' => $class::defaultPrefix()]),
            'runs' => ImportRun::orderByDesc('id')->limit(10)->get()->each(fn (ImportRun $run) => $run->percent = $importer->percent($run)),
        ]);
    }

    public function startImport(Request $request, Importer $importer)
    {
        $data = $request->validate([
            'source' => ['required', Rule::in(array_keys(Importer::SOURCES))],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:255'],
            'prefix' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_]*$/'],
            'charset' => ['required', Rule::in(Importer::CHARSETS)],
        ]);

        abort_if(ImportRun::where('status', 'running')->exists(), 422, __('Another import is still running.'));

        try {
            $importer->start($data['source'], ['driver' => 'mysql'] + collect($data)->except('source')->all());
        } catch (Throwable $e) {
            return back()->withInput($request->except('password'))
                ->with('error', __('The database could not be read: :error', ['error' => Importer::text($e->getMessage(), 300)]));
        }

        return redirect()->route('admin.import')->with('success', __('The import has started. Keep this page open until it has finished.'));
    }

    /** Called again and again by the page: every call does one batch. */
    public function advanceImport(ImportRun $run, Importer $importer)
    {
        @set_time_limit(120);
        $run = $importer->advance($run);

        return response()->json([
            'status' => $run->status,
            'step' => __(['users' => 'Members', 'forums' => 'Forums', 'threads' => 'Threads', 'posts' => 'Posts', 'rebuild' => 'Counting replies', 'members' => 'Counting member posts', 'done' => 'Finished'][$run->step] ?? 'Running'),
            'percent' => $importer->percent($run),
            'done' => $run->stats['done'] ?? [],
            'error' => $run->error,
        ]);
    }

    public function updateImport(Request $request, ImportRun $run)
    {
        $action = $request->validate(['action' => ['required', Rule::in(['retry', 'stop'])]])['action'];

        if ($action === 'retry' && $run->status === 'failed' && $run->config !== '') {
            $run->update(['status' => 'running', 'error' => null]);
        } elseif ($action === 'stop' && $run->status !== 'done') {
            // What has been imported stays; only the connection details are thrown away.
            $run->update(['status' => 'failed', 'error' => __('Stopped by an administrator.'), 'config' => '']);
        }

        return back();
    }
}
