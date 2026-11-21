<?php

namespace App\Http\Controllers;

use App\Forum\Attachments;
use App\Forum\Edition;
use App\Http\Controllers\Concerns\ChecksVisibility;
use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    use ChecksVisibility;

    public function __construct(protected Attachments $attachments) {}

    /** Upload from the editor (JSON). The file is tied to a post when the form is saved. */
    public function store(Request $request, Edition $edition)
    {
        abort_unless($edition->has('attachments'), 404);

        $data = $request->validate([
            'file' => ['required', 'file'],
            'key' => ['required', 'string', 'size:32', 'alpha_num'],
            'node_id' => ['required', 'integer'],
        ]);

        $node = $this->viewableNode((int) $data['node_id']);
        forum_authorize('attachment.upload', $node);

        return response()->json($this->payload($this->attachments->store($request->file('file'), $request->user(), $data['key'])));
    }

    public function show(Request $request, Attachment $attachment)
    {
        if ($attachment->post_id && $attachment->post) {
            [, $node] = $this->visiblePost($attachment->post);
            forum_authorize('attachment.view', $node);
        } else {
            // Not posted yet: only the uploader sees it.
            abort_unless($request->user() && ($request->user()->id === $attachment->user_id || Gate::allows('admin')), 404);
        }

        $thumb = $request->boolean('thumb') && $attachment->thumb_path;
        $path = $thumb ? $attachment->thumb_path : $attachment->disk_path;
        abort_unless(Storage::disk('local')->exists($path), 404);

        if (! $thumb && ! $attachment->is_image) {
            Attachment::whereKey($attachment->id)->increment('download_count');
        }

        // Only raster images are shown in the browser; everything else is a download, whatever the stored row says.
        $inline = $thumb || ($attachment->is_image && in_array($attachment->mime, Attachments::RASTER, true));

        return Storage::disk('local')->response($path, $attachment->filename, [
            'Content-Type' => $thumb ? 'image/jpeg' : ($inline ? $attachment->mime : 'application/octet-stream'),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=86400',
        ], $inline ? 'inline' : 'attachment');
    }

    public function destroy(Request $request, Attachment $attachment)
    {
        $user = $request->user();
        $allowed = $attachment->user_id === $user->id || Gate::allows('admin');

        if (! $allowed && $attachment->post) {
            [, $node] = $this->visiblePost($attachment->post);
            $allowed = forum_can('mod.editAny', $node);
        }
        abort_unless($allowed, 403);

        $attachment->delete();

        return response()->json(['deleted' => true]);
    }

    protected function payload(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->filename,
            'size' => $attachment->humanSize(),
            'is_image' => $attachment->is_image,
            'url' => $attachment->url(),
            'thumb' => $attachment->is_image ? $attachment->url(true) : null,
            'bbcode' => $attachment->bbcode(),
            'delete_url' => route('attachments.destroy', $attachment),
        ];
    }
}
