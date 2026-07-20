<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/app/');
});

Route::get('/login', function () {
    return redirect('/app/');
})->name('login');

Route::get('/dashboard', function () {
    return redirect('/app/');
})->name('dashboard');
