<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PostController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::middleware(['throttle:30,1'])->group(function () {
    Route::get('/', function () {
        return view('welcome');
    })->name('home');
    Route::resource('articles', PostController::class)->parameters(['articles' => 'slug'])->names('posts');
    Route::get('/mentions', function () {
        return view('mentions');
    })->name('mentions');
    Route::get('/cookies', function () {
        return view('cookies');
    })->name('cookies');

    //AUTH
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->middleware('auth')->name('dashboard');
});
