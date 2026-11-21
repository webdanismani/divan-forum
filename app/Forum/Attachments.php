<?php

namespace App\Forum;

use App\Models\Attachment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Files uploaded with posts. An upload first belongs to an "upload key" (the
 * form being written) and is tied to the post when that form is saved.
 */
class Attachments
{
    public const IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** Never accepted, whatever the administrator configures. */
    protected const BLOCKED = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'html', 'htm', 'xhtml', 'js', 'mjs', 'svg', 'exe', 'bat', 'cmd', 'com', 'sh', 'htaccess', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp'];

    protected const THUMB_WIDTH = 520;

    /**
     * What counts as a picture and is shown in the browser. The content type comes from this list, never from
     * what the image library reports: newer PHP versions recognise more formats (SVG among them), and a file
     * that can run scripts must not be served inline from the forum's own address.
     */
    public const RASTER = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_GIF => 'image/gif', IMAGETYPE_WEBP => 'image/webp'];

    public function __construct(protected Settings $settings) {}

    /** @return string[] */
    public function extensions(): array
    {
        $list = preg_split('/[\s,;]+/', strtolower((string) $this->settings->get('attachments.extensions')), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_diff(array_unique(array_map(fn ($ext) => ltrim($ext, '.'), $list)), self::BLOCKED));
    }

    public function maxKilobytes(): int
    {
        return max(1, (int) $this->settings->get('attachments.max_kb'));
    }

    public function maxPerPost(): int
    {
        return max(1, (int) $this->settings->get('attachments.max_per_post'));
    }

    public static function newKey(): string
    {
        return Str::random(32);
    }

    public function store(UploadedFile $file, User $user, string $key): Attachment
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, $this->extensions(), true)) {
            throw ValidationException::withMessages(['file' => __('This file type is not allowed.')]);
        }
        if ($file->getSize() > $this->maxKilobytes() * 1024) {
            throw ValidationException::withMessages(['file' => __('The file is too large (:max at most).', ['max' => $this->maxKilobytes().' KB'])]);
        }
        if ($this->pending($key, $user)->count() >= $this->maxPerPost()) {
            throw ValidationException::withMessages(['file' => __('You cannot add more files to this message.')]);
        }
        // Across all forms: what a member has uploaded but not posted yet is limited, so nobody fills the disk with open drafts.
        $waiting = Attachment::where('user_id', $user->id)->whereNull('post_id');
        if ((clone $waiting)->count() >= max(20, $this->maxPerPost() * 3) || (int) (clone $waiting)->sum('size') + $file->getSize() > $this->maxKilobytes() * 1024 * 20) {
            throw ValidationException::withMessages(['file' => __('You have too many files waiting to be posted. Post or remove them first.')]);
        }

        $size = in_array($extension, self::IMAGES, true) ? @getimagesize($file->getRealPath()) : false;
        if (in_array($extension, self::IMAGES, true) && (! $size || ! isset(self::RASTER[$size[2]]) || $size[0] < 1 || $size[1] < 1)) {
            throw ValidationException::withMessages(['file' => __('This image could not be read.')]);
        }

        // Stored under a random name without the original extension, outside the web root. The preview is made
        // first: if the picture is too much for the server, nothing has been written yet.
        $directory = 'attachments/'.date('Y/m');
        $name = Str::random(40);
        $thumb = $size ? $this->thumbnail($file->getRealPath(), $size, $directory.'/'.$name.'_t.jpg') : null;
        $path = $file->storeAs($directory, $name.'.bin', 'local');

        return Attachment::create([
            'user_id' => $user->id,
            'upload_key' => $key,
            'disk_path' => $path,
            'thumb_path' => $thumb,
            'filename' => $this->cleanName($file->getClientOriginalName(), $extension),
            'extension' => $extension,
            'mime' => $size ? self::RASTER[$size[2]] : ($file->getMimeType() ?: 'application/octet-stream'),
            'size' => $file->getSize(),
            'width' => $size ? $size[0] : null,
            'height' => $size ? $size[1] : null,
            'is_image' => (bool) $size,
        ]);
    }

    /** Uploads of this form that are not tied to a post yet. */
    public function pending(?string $key, User $user): Collection
    {
        if (blank($key)) {
            return collect();
        }

        return Attachment::where('upload_key', $key)->where('user_id', $user->id)->whereNull('post_id')->orderBy('id')->get();
    }

    public function attach(?string $key, User $user, Post $post): void
    {
        // The upload itself was allowed in the forum the form named; what counts is the forum the post really is in.
        if (filled($key) && ($node = $post->thread?->node) && app(\App\Forum\Permissions\PermissionResolver::class)->can($user, 'attachment.upload', $node)) {
            Attachment::where('upload_key', $key)->where('user_id', $user->id)->whereNull('post_id')->update(['post_id' => $post->id]);
        }
    }

    /** Remove the files of posts that are about to be deleted for good. */
    public function deleteForPosts(array $postIds): void
    {
        if ($postIds) {
            Attachment::whereIn('post_id', $postIds)->get()->each->delete();
        }
    }

    /** Uploads that were never posted. */
    public function purgeOrphans(): int
    {
        $removed = 0;
        do {
            $orphans = Attachment::whereNull('post_id')->where('created_at', '<', now()->subDay())->limit(500)->get();
            $orphans->each->delete();
            $removed += $orphans->count();
        } while ($orphans->count() === 500 && $removed < 10000);

        return $removed;
    }

    protected function cleanName(string $name, string $extension): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = trim(preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $base), ' ._');

        return Str::limit($base !== '' ? $base : 'file', 120, '').'.'.$extension;
    }

    /** How many pixels the image library may unpack without running into the memory limit (about 5 bytes each). */
    protected static function pixelBudget(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        $bytes = $limit === '' || $limit === '-1'
            ? 512 * 1048576
            : (int) $limit * match (strtolower(substr($limit, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
        $free = max(0, $bytes - memory_get_usage(true) - 24 * 1048576);

        return (int) min(24_000_000, $free / 6);
    }

    /** A JPEG preview for wide images; null when GD is missing, the image is small enough already or too large to unpack. */
    protected function thumbnail(string $source, array $size, string $target): ?string
    {
        [$width, $height] = $size;
        if (! function_exists('imagecreatetruecolor') || $width <= self::THUMB_WIDTH || $width * $height > self::pixelBudget()) {
            return null;
        }

        $image = match ($size[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_GIF => @imagecreatefromgif($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default => false,
        };
        if (! $image) {
            return null;
        }

        $newHeight = max(1, (int) round($height * self::THUMB_WIDTH / $width));
        $thumb = imagecreatetruecolor(self::THUMB_WIDTH, $newHeight);
        imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, self::THUMB_WIDTH, $newHeight, $width, $height);

        ob_start();
        imagejpeg($thumb, null, 84);
        Storage::disk('local')->put($target, ob_get_clean());

        return $target;
    }
}
