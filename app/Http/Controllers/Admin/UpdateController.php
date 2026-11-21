<?php

namespace App\Http\Controllers\Admin;

use App\Forum\Setup\Updater;
use App\Forum\Tasks;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;
use ZipArchive;

/** Maintenance: database updates, caches, background tasks and installing an update package. */
class UpdateController extends Controller
{
    public function index(Tasks $tasks)
    {
        return view('admin.update', [
            'version' => config('forum.product.version'),
            'pending' => $this->pendingMigrations(),
            'tasks' => collect($tasks->definitions())->map(fn (array $definition, string $name) => [
                'minutes' => $definition[0],
                'last' => Cache::get('forum.tasks.last.'.$name),
            ]),
            'log' => session('update_log'),
            'limits' => ['upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size')],
            'zip' => class_exists(ZipArchive::class),
        ]);
    }

    public function migrate()
    {
        return back()->with('update_log', $this->refresh())->with('success', __('The database is up to date.'));
    }

    public function tasks(Tasks $tasks)
    {
        $ran = $tasks->runDue(force: true);

        return back()->with('success', __(':count background tasks were run.', ['count' => count($ran)]));
    }

    /** Unpack an update package over the application, then update the database and caches. */
    public function upload(Request $request, Updater $updater)
    {
        $request->validate([
            'package' => ['required', 'file', 'mimes:zip', 'max:102400'],
            'current_password' => ['required', 'current_password'],
        ]);
        abort_unless(class_exists(ZipArchive::class), 500, 'ZipArchive is not available.');

        $result = $updater->applyZip($request->file('package')->getRealPath());

        return is_string($result)
            ? back()->with('error', $result)
            : back()->with('update_log', $result['log'])->with('success', __('The update has been installed.'));
    }

    protected function refresh(): string
    {
        return app(Updater::class)->refresh();
    }

    /** @return string[] */
    protected function pendingMigrations(): array
    {
        try {
            $migrator = app('migrator');
            $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));

            return array_values(array_diff($files, $migrator->getRepository()->getRan()));
        } catch (Throwable) {
            return [];
        }
    }
}
