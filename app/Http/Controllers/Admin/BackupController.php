<?php

namespace App\Http\Controllers\Admin;

use App\Forum\Backups;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Backups of the database and of the uploaded files, made and downloaded from the panel. */
class BackupController extends Controller
{
    public function index(Backups $backups)
    {
        return view('admin.backups', [
            'backups' => $backups->all(),
            'uploadsSize' => $this->uploadsSize(),
        ]);
    }

    public function store(Request $request, Backups $backups)
    {
        // A backup is the whole board in one file: making and fetching one asks for the password again.
        $kind = $request->validate([
            'kind' => ['required', 'in:db,files'],
            'current_password' => ['required', 'current_password'],
        ], ['current_password.current_password' => __('The password is not correct.')])['kind'];

        try {
            $name = $kind === 'db' ? $backups->database() : $backups->files();
        } catch (Throwable $problem) {
            Log::error('Backup failed: '.$problem->getMessage());

            return back()->with('error', __('The backup could not be made: :reason', ['reason' => $problem->getMessage()]));
        }

        return back()->with('success', __('The backup ":name" is ready. Download it and keep it somewhere outside this server.', ['name' => $name]));
    }

    public function download(Request $request, string $name, Backups $backups)
    {
        $request->validate(['current_password' => ['required', 'current_password']], ['current_password.current_password' => __('The password is not correct.')]);
        abort_unless($path = $backups->path($name), 404);

        return response()->download($path, $name, ['Cache-Control' => 'no-store']);
    }

    public function destroy(string $name, Backups $backups)
    {
        abort_unless($backups->delete($name), 404);

        return back()->with('success', __('The backup has been deleted.'));
    }

    /** A rough size of what members uploaded, so the administrator knows what the files backup will weigh. */
    protected function uploadsSize(): int
    {
        $root = storage_path('app/public');
        $size = 0;
        if (is_dir($root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $size += $file->isFile() ? $file->getSize() : 0;
            }
        }

        return $size;
    }
}
