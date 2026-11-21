<?php

namespace App\Console\Commands;

use App\Forum\Theme;
use App\Models\Ad;
use App\Models\Payment;
use App\Models\ThreadPromotion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class LangKeys extends Command
{
    protected $signature = 'forum:lang-keys {locale=tr : Locale to compare against} {--all : List every key, not only the missing ones}';

    protected $description = 'List interface strings that are missing from a lang/{locale}.json file';

    public function handle(): int
    {
        $keys = [];

        foreach ([app_path(), resource_path('views')] as $directory) {
            foreach (File::allFiles($directory) as $file) {
                preg_match_all('/(?:__|trans_choice)\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', $file->getContents(), $matches);
                foreach ($matches[1] as $literal) {
                    $keys[] = stripcslashes(substr($literal, 1, -1));
                }
            }
        }

        // Labels that are translated dynamically from configuration.
        foreach (config('forum.permissions') as $section => $permissions) {
            $keys[] = $section;
            array_push($keys, ...array_column($permissions, 'label'));
        }
        foreach (config('forum.settings') as $section => $definitions) {
            $keys[] = $section;
            foreach ($definitions as $definition) {
                $keys[] = $definition['label'];
                array_push($keys, ...array_values($definition['options'] ?? []));
            }
        }
        array_push($keys, ...array_values(config('forum.premium_features')));
        $theme = app(Theme::class);
        array_push($keys, ...array_keys($theme->presets()), ...array_column($theme->fields(), 'label'), ...array_column($theme->fields(), 'hint'));
        array_push($keys, ...array_column(Ad::STATES, 0), ...array_values(Ad::METHODS));
        array_push($keys, ...array_column(Payment::STATES, 0), ...array_values(Payment::PURPOSES), ...array_values(ThreadPromotion::TYPES));
        array_push($keys, 'Members', 'Threads', 'Posts', 'Online now', 'Open reports', 'Awaiting approval',
            'Category', 'Forum', 'Link', 'Blue', 'Green', 'Red', 'Orange', 'Purple', 'Gray');

        $keys = array_values(array_unique($keys));
        $path = lang_path($this->argument('locale').'.json');
        $existing = File::exists($path) ? json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR) : [];

        $list = $this->option('all') ? $keys : array_values(array_diff($keys, array_keys($existing)));
        foreach ($list as $key) {
            $this->line($key);
        }

        $unused = array_diff(array_keys($existing), $keys);
        $this->components->info(count($keys).' keys, '.count(array_diff($keys, array_keys($existing))).' missing, '.count($unused).' unused in '.basename($path));

        return self::SUCCESS;
    }
}
