<?php

use Goldnead\ClientRooms\Http\Controllers\DownloadController;
use Goldnead\ClientRooms\Http\Controllers\SubmissionDownloadController;
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
