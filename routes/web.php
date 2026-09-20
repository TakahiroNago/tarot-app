<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/tarot', function () {
    return view('tarot');
})->name('tarot');
