<?php

use Goldnead\ClientRooms\Http\Controllers\Cp\FilesController;
use Goldnead\ClientRooms\Http\Controllers\Cp\RoomsController;
use Goldnead\ClientRooms\Http\Controllers\Cp\TasksController;
use Illuminate\Support\Facades\Route;

/*
| `{room}`, `{task}` and `{file}` are plain integers, resolved inside the
| controllers through the brand-scoped query. Nothing is bound to the names:
| an implicit binding would claim them application-wide, and a sibling addon
| using `{file}` in a route of its own would get this addon's binder.
|
| Authorization twice, on purpose: `can:` here, and the same Gate check inside
| every controller action, so neither a route added later nor a controller
| reused elsewhere can forget it.
*/

Route::prefix('client-rooms')->name('client-rooms.')->middleware('can:view client rooms')->group(function (): void {
    Route::get('/', [RoomsController::class, 'index'])->name('index');
    Route::get('{room}', [RoomsController::class, 'show'])->name('show')->whereNumber('room');
    Route::get('{room}/files/{file}/download', [FilesController::class, 'download'])->name('files.download')->whereNumber(['room', 'file']);

    Route::middleware('can:edit client rooms')->group(function (): void {
        Route::post('/', [RoomsController::class, 'store'])->name('store');
        Route::patch('{room}', [RoomsController::class, 'update'])->name('update')->whereNumber('room');
        Route::post('{room}/close', [RoomsController::class, 'close'])->name('close')->whereNumber('room');
        Route::post('{room}/reopen', [RoomsController::class, 'reopen'])->name('reopen')->whereNumber('room');

        Route::post('{room}/tasks', [TasksController::class, 'store'])->name('tasks.store')->whereNumber('room');
        Route::patch('{room}/tasks/{task}', [TasksController::class, 'update'])->name('tasks.update')->whereNumber(['room', 'task']);
        Route::delete('{room}/tasks/{task}', [TasksController::class, 'destroy'])->name('tasks.destroy')->whereNumber(['room', 'task']);

        Route::post('{room}/files', [FilesController::class, 'store'])->name('files.store')->whereNumber('room');
        Route::patch('{room}/files/{file}', [FilesController::class, 'update'])->name('files.update')->whereNumber(['room', 'file']);
        Route::delete('{room}/files/{file}', [FilesController::class, 'destroy'])->name('files.destroy')->whereNumber(['room', 'file']);
    });
});
