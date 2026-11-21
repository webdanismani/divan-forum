<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** A file uploaded with a post. Files live outside the web root and are served by AttachmentController. */
class Attachment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_image' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::deleting(function (Attachment $attachment) {
            Storage::disk('local')->delete(array_filter([$attachment->disk_path, $attachment->thumb_path]));
        });
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function url(bool $thumb = false): string
    {
        return route('attachments.show', ['attachment' => $this->id, 'name' => $this->filename] + ($thumb && $this->thumb_path ? ['thumb' => 1] : []));
    }

    public function bbcode(): string
    {
        return '[attach]'.$this->id.'[/attach]';
    }

    public function humanSize(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1, ',', '.').' MB'
            : max(1, (int) round($this->size / 1024)).' KB';
    }
}
