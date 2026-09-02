<?php

use Goldnead\ClientRooms\Http\Controllers\DownloadController;
use Goldnead\ClientRooms\Http\Controllers\MemberController;
use Goldnead\ClientRooms\Http\Controllers\SubmissionDownloadController;
use Goldnead\ClientRooms\Http\Middleware\AlwaysJson;
use Illuminate\Support\Facades\Route;

/*
| The client's downloads. `signed` rejects a tampered or expired link with a
| 403 before the controller runs; each controller adds the checks a signature
| cannot know about (hidden again, room closed). Under Statamic's `/!/`
| prefix like every addon route, so they cannot collide with a page.
|
| Two routes rather than one with a type parameter: the ids belong to
| different tables, and a single URL shape would invite an id from one to be
| tried against the other.
*/

Route::get('/!/statamic-clientrooms/files/{file}', DownloadController::class)
    ->middleware('signed')
    ->whereNumber('file')
    ->name('statamic-clientrooms.download');

// What the client sent back, going the other way.
Route::get('/!/statamic-clientrooms/submissions/{file}', SubmissionDownloadController::class)
    ->middleware('signed')
    ->whereNumber('file')
    ->name('statamic-clientrooms.submission-download');

/*
| The members area, as JSON, for a front end that is not Antlers. Off unless
| `member_api` says otherwise, because a site that renders its room with the
| tag has no use for it and should not answer on routes it never asked for.
|
| No room id anywhere: every action finds the room from the signed-in user, so
| there is no parameter to change into somebody else's.
|
| The signed-out case is answered by the controller, not by the `auth`
| middleware. `auth` chooses between a JSON 401 and a redirect to a `login`
| route by reading the Accept header as it throws, and Laravel's middleware
| priority hoists it ahead of anything that would rewrite that header — so on
| a host without a `login` route it raises "Route [login] not defined" instead
| of answering. A person, not a link: 401, in JSON, every time.
*/
if (config('statamic-clientrooms.member_api', true)) {
    Route::middleware(AlwaysJson::class)->prefix('/!/statamic-clientrooms/me')->name('statamic-clientrooms.me.')->group(function (): void {
        Route::get('/', [MemberController::class, 'show'])->name('show');

        Route::patch('tasks/{task}', [MemberController::class, 'update'])
            ->name('tasks.update')
            ->whereNumber('task');

        // Throttled: this one accepts files, and a members area is as public
        // as its weakest password.
        Route::post('tasks/{task}/submissions', [MemberController::class, 'submit'])
            ->middleware('throttle:20,1')
            ->name('tasks.submit')
            ->whereNumber('task');
    });
}
