<?php

use Goldnead\ClientRooms\Http\Controllers\DownloadController;
use Illuminate\Support\Facades\Route;

/*
| The client's download. `signed` rejects a tampered or expired link with a
| 403 before the controller runs; the controller adds the checks a signature
| cannot know about (hidden again, room closed). Under Statamic's `/!/`
| prefix like every addon route, so it cannot collide with a page.
*/

Route::get('/!/statamic-clientrooms/files/{file}', DownloadController::class)
    ->middleware('signed')
    ->whereNumber('file')
    ->name('statamic-clientrooms.download');
