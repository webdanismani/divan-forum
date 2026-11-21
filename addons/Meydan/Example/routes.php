<?php

use Illuminate\Support\Facades\Route;

// Loaded inside the "web" middleware group, so sessions, sign-in and the forum layout work.
Route::get('/example-addon', fn () => view('meydan-example::hello'))->name('example-addon');
