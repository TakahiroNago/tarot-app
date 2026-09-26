<!-- routes/web.php -->

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TarotController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/tarot', [TarotController::class, 'index'])->name('tarot');

Route::post('/tarot/draw', [TarotController::class, 'draw'])->name('tarot.draw');
