<?php

use App\Http\Controllers\MediaController;
use App\Http\Controllers\ShareController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $index = public_path('app/index.html');
    if (! is_file($index)) {
        return response('Kiosk UI is not built. From frontend/, run npm run build.', 503);
    }

    return response()->file($index, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-cache',
    ]);
});

Route::get('/s/{shareToken}', ShareController::class)->where('shareToken', '[A-Za-z0-9]{40}');

Route::get('/media/local', MediaController::class)->name('media.local')->middleware('signed');
