<?php

use App\Http\Controllers\ApiController;
use App\Http\Middleware\ApiAuth;
use Illuminate\Support\Facades\Route;

// REST API v1: /api/v1/... Switched on in Admin panel > Settings > API.
Route::prefix('v1')->middleware([ApiAuth::class, 'throttle:120,1'])->group(function () {
    Route::get('me', [ApiController::class, 'me']);
    Route::get('forums', [ApiController::class, 'forums']);
    Route::get('forums/{forum}/threads', [ApiController::class, 'threads'])->whereNumber('forum');
    Route::post('forums/{forum}/threads', [ApiController::class, 'storeThread'])->whereNumber('forum');
    Route::get('threads/{thread}', fn (ApiController $api, int $thread) => $api->thread($thread))->whereNumber('thread');
    Route::get('threads/{thread}/posts', [ApiController::class, 'posts'])->whereNumber('thread');
    Route::post('threads/{thread}/posts', [ApiController::class, 'storePost'])->whereNumber('thread');
    Route::get('members/{member}', [ApiController::class, 'showMember'])->whereNumber('member');
});
